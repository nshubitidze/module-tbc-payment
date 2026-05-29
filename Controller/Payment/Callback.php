<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\ResourceConnection;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\TbcPayment\Gateway\Response\PaymentInfoKeys;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Model\FlittStatus;
use Shubo\TbcPayment\Model\OrderLocator;
use Shubo\TbcPayment\Service\ApprovalContext;
use Shubo\TbcPayment\Service\ApprovalResult;
use Shubo\TbcPayment\Service\OrderApprovalApplier;
use Shubo\TbcPayment\Service\PaymentLock;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Handles Flitt server-to-server callback notifications.
 *
 * Flitt sends POST with JSON body containing payment result.
 * This controller verifies signature, updates order status, and creates invoice.
 *
 * Concurrency (IMPROVE-2): all capture processing for a given order runs inside
 * a {@see PaymentLock}, keyed by flitt_order_id (falling back to increment_id),
 * with the order state re-read INSIDE the lock. This is the path that needed it
 * most — it historically used a plain non-locking getList SELECT + a bare
 * beginTransaction, which neither blocked nor was blocked by the SELECT ... FOR
 * UPDATE that Confirm/ReturnAction take, so the four capture paths were not
 * actually serialized against each other.
 *
 * Defensive hardening (IMPROVE-9):
 *   (a) optional Flitt source-IP allowlist — rejects callbacks from other IPs
 *       with HTTP 403 when configured; FAIL-OPEN (allow all) when empty.
 *   (b) replay protection — the state===PROCESSING short-circuit already makes
 *       an exact replay of an approved callback idempotent; we additionally
 *       persist the last-processed Flitt payment_id and treat an exact
 *       payment_id replay as a benign no-op (HTTP 200), never rejecting the
 *       legitimate first delivery. The short-circuit is scoped to APPROVED
 *       status ONLY — a reversal/chargeback re-uses the captured payment_id and
 *       must reach handleReversed(), so non-approved statuses always route to
 *       their handler even when the payment_id matches.
 */
class Callback implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** Payment additional_information key holding the last processed Flitt payment_id (IMPROVE-9b). */
    private const LAST_PAYMENT_ID_KEY = 'flitt_processed_payment_id';

    public function __construct(
        private readonly \Magento\Framework\App\Request\Http $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Json $jsonSerializer,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderLocator $orderLocator,
        private readonly CallbackValidator $callbackValidator,
        private readonly OrderApprovalApplier $approvalApplier,
        private readonly SettlementService $settlementService,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
        private readonly UserFacingErrorMapper $userFacingErrorMapper,
        private readonly PaymentLock $paymentLock,
        private readonly Config $config,
    ) {
    }

    public function execute(): ResultInterface
    {
        /** @var JsonResult $result */
        $result = $this->jsonFactory->create();

        try {
            // IMPROVE-9a: source-IP allowlist (defence in depth on top of the
            // signature check). FAIL-OPEN when the allowlist is empty.
            if (!$this->sourceIpAllowed()) {
                $this->logger->warning('Flitt callback: rejected source IP', [
                    'source_ip' => $this->resolveSourceIp(),
                ]);
                return $result->setHttpResponseCode(403)->setData(['status' => 'error']);
            }

            $content = $this->request->getContent();
            $callbackData = $this->jsonSerializer->unserialize($content);

            if (!is_array($callbackData)) {
                $this->logger->error('Flitt callback: invalid JSON payload');
                return $result->setHttpResponseCode(400)->setData(['status' => 'error']);
            }

            $this->logger->info('Flitt callback received', [
                'order_id' => $callbackData['order_id'] ?? 'unknown',
                'order_status' => $callbackData['order_status'] ?? 'unknown',
            ]);

            $orderId = $callbackData['order_id'] ?? '';

            if (empty($orderId)) {
                $this->logger->error('Flitt callback: missing order_id');
                return $result->setHttpResponseCode(400)->setData(['status' => 'error']);
            }

            // Extract Magento increment ID from prefixed Flitt order_id
            // Format: duka_{incrementId}_{timestamp}
            $incrementId = $this->orderLocator->extractIncrementId((string) $orderId);
            /** @var Order|null $order */
            $order = $this->orderLocator->byIncrementId($incrementId);

            if ($order === null) {
                $this->logger->error('Flitt callback: order not found', ['order_id' => $orderId]);
                return $result->setHttpResponseCode(404)->setData(['status' => 'error']);
            }

            $storeId = (int) $order->getStoreId();

            if (!$this->callbackValidator->validate($callbackData, $storeId)) {
                $this->logger->error('Flitt callback: signature validation failed', [
                    'order_id' => $orderId,
                ]);
                return $result->setHttpResponseCode(403)->setData(['status' => 'error']);
            }

            // IMPROVE-2: serialize the whole order-load + capture against the
            // other capture paths (Confirm/ReturnAction/CheckStatus/Cron). The
            // lock key is the flitt_order_id (guaranteed non-empty by the guard
            // above). withLock returns null on contention — we surface that as a
            // benign HTTP 200 (the work is idempotent; another path / a retry
            // finishes it).
            $lockKey = (string) $orderId;

            $outcome = $this->paymentLock->withLock(
                $lockKey,
                fn (): array => $this->handleLocked($incrementId, $lockKey, $callbackData)
            );

            if ($outcome === null) {
                $this->logger->info('Flitt callback: lock contended, deferring', [
                    'order_id' => $orderId,
                ]);
                return $result->setHttpResponseCode(200)->setData(['status' => 'deferred']);
            }

            return $result
                ->setHttpResponseCode($outcome['http'])
                ->setData(['status' => $outcome['status']]);
        } catch (\Exception $e) {
            $this->logger->error('Flitt callback error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return $result->setHttpResponseCode(500)->setData(['status' => 'error']);
        }
    }

    /**
     * Body that runs while holding the PaymentLock: re-read state, apply the
     * approval inside a DB transaction, then run settlement after commit.
     *
     * @param array<string, mixed> $callbackData
     * @return array{http: int, status: string}
     */
    private function handleLocked(string $incrementId, string $orderId, array $callbackData): array
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        $applyResult = null;
        /** @var Order|null $order */
        $order = null;

        try {
            // Re-load order inside the lock + transaction to get fresh state.
            /** @var Order|null $order */
            $order = $this->orderLocator->byIncrementId($incrementId);
            if ($order === null) {
                $connection->rollBack();
                $this->logger->error('Flitt callback: order not found on reload', ['order_id' => $orderId]);
                return ['http' => 404, 'status' => 'error'];
            }

            // IMPROVE-9b: an exact payment_id replay of an already-approved
            // callback is a benign no-op. The state===PROCESSING short-circuit
            // (inside the applier) already covers an exact replay, but checking
            // the persisted last-processed payment_id lets us answer 200 fast
            // without re-running the status-history mutation. We never reject
            // the FIRST delivery — the key is only set after a real capture.
            //
            // CRITICAL: scope this short-circuit to APPROVED status ONLY. The
            // stored payment_id is stamped on capture, and a later bank/fraud
            // REVERSAL or chargeback carries that SAME payment_id with
            // order_status=reversed. If the guard fired for non-approved
            // statuses it would 200-no-op the reversal and handleReversed()
            // (which closes/cancels the order after funds are pulled back) would
            // NEVER run — leaving the order paid/complete. So only treat an
            // exact payment_id match as a replay when the incoming status is the
            // APPROVED status the guard was designed to dedupe; every other
            // status (reversed / declined / expired) routes to its handler even
            // when the payment_id matches. handleReversed() is itself idempotent
            // over order state (an already-closed/canceled order is a no-op).
            $incomingStatus = (string) ($callbackData['order_status'] ?? '');
            if ($incomingStatus === FlittStatus::APPROVED && $this->isReplayedPaymentId($order, $callbackData)) {
                $connection->commit();
                $this->logger->info('Flitt callback: replayed payment_id, benign no-op', [
                    'order_id'   => $order->getIncrementId(),
                    'payment_id' => (string) ($callbackData['payment_id'] ?? ''),
                ]);
                return ['http' => 200, 'status' => 'ok'];
            }

            $applyResult = $this->processCallback($order, $callbackData);

            // IMPROVE-8: a refused capture (amount mismatch) is a do-not-retry
            // situation — the cart was re-priced or a stale callback is
            // replaying. Roll back any partial mutation and tell Flitt to stop
            // retrying (HTTP 400). The applier already logged at `critical`.
            if ($applyResult === ApprovalResult::RefusedAmountMismatch) {
                $connection->rollBack();
                return ['http' => 400, 'status' => 'amount_mismatch'];
            }

            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }

        // Trigger settlement OUTSIDE the order transaction (it does its own
        // external HTTP call) and only when this callback actually captured.
        if ($applyResult === ApprovalResult::Captured && $order !== null) {
            try {
                $this->settlementService->settle($order);
                $this->orderRepository->save($order);
            } catch (\Exception $e) {
                $this->logger->error('Settlement after callback failed', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
                // Don't fail the callback response -- settlement can be retried.
            }
        }

        return ['http' => 200, 'status' => 'ok'];
    }

    /**
     * IMPROVE-9a: is the request's source IP permitted to deliver callbacks?
     *
     * FAIL-OPEN: an empty allowlist permits every IP (proxy-friendly default).
     */
    private function sourceIpAllowed(): bool
    {
        $allowlist = $this->config->getCallbackIpAllowlist();
        if ($allowlist === []) {
            return true;
        }

        return in_array($this->resolveSourceIp(), $allowlist, true);
    }

    /**
     * Resolve the client IP, honouring the trusted forwarded header so a
     * reverse proxy in front of the app reports the real Flitt egress IP.
     */
    private function resolveSourceIp(): string
    {
        $forwarded = (string) $this->request->getHeader('X-Forwarded-For');
        if ($forwarded !== '') {
            // X-Forwarded-For is a comma list; the left-most entry is the client.
            $first = trim((string) (explode(',', $forwarded)[0] ?? ''));
            if ($first !== '') {
                return $first;
            }
        }

        return (string) $this->request->getClientIp();
    }

    /**
     * IMPROVE-9b: does this exact Flitt payment_id match the one stamped on the
     * order at capture time? Returns false when there is no incoming payment_id,
     * when none was recorded yet (first delivery), or when the stored and
     * incoming ids differ — so a legitimate first delivery is never blocked.
     *
     * This is a pure id-equality check; it does NOT inspect order state. The
     * caller {@see handleLocked} gates its use to APPROVED-status callbacks only,
     * because a reversal/chargeback re-uses the captured payment_id and must NOT
     * be treated as a benign replay.
     *
     * @param array<string, mixed> $callbackData
     */
    private function isReplayedPaymentId(Order $order, array $callbackData): bool
    {
        $incomingPaymentId = (string) ($callbackData['payment_id'] ?? '');
        if ($incomingPaymentId === '') {
            return false;
        }

        /** @var Payment|null $payment */
        $payment = $order->getPayment();
        if ($payment === null) {
            return false;
        }

        $processed = (string) ($payment->getAdditionalInformation(self::LAST_PAYMENT_ID_KEY) ?? '');

        return $processed !== '' && $processed === $incomingPaymentId;
    }

    /**
     * Process the callback data and update order accordingly.
     *
     * Returns the {@see ApprovalResult} for the APPROVED branch (so the caller
     * can gate settlement / replay-marking / the do-not-retry response), or null
     * for every other order_status (declined / expired / reversed / intermediate).
     *
     * @param Order $order
     * @param array<string, mixed> $callbackData
     */
    private function processCallback(Order $order, array $callbackData): ?ApprovalResult
    {
        /** @var Payment $payment */
        $payment = $order->getPayment();
        $orderStatus = $callbackData['order_status'] ?? '';

        // Store the full Flitt order_id so refunds can reference it
        $payment->setAdditionalInformation('flitt_order_id', $callbackData['order_id'] ?? '');

        // Store callback data in payment additional info
        PaymentInfoKeys::apply($payment, $callbackData);

        if (isset($callbackData['payment_id'])) {
            $payment->setTransactionId((string) $callbackData['payment_id']);
        }

        $approvalResult = null;

        switch ($orderStatus) {
            case FlittStatus::APPROVED:
                $approvalResult = $this->approvalApplier->apply($order, $callbackData, ApprovalContext::Callback);

                // IMPROVE-8: do not persist a refused (amount-mismatch) capture.
                // The applier mutated nothing; the caller rolls back and answers 400.
                if ($approvalResult === ApprovalResult::RefusedAmountMismatch) {
                    return $approvalResult;
                }

                // IMPROVE-9b: stamp the captured payment_id so an exact replay
                // is recognised as a benign no-op next time.
                if ($approvalResult === ApprovalResult::Captured) {
                    $incomingPaymentId = (string) ($callbackData['payment_id'] ?? '');
                    if ($incomingPaymentId !== '') {
                        $payment->setAdditionalInformation(self::LAST_PAYMENT_ID_KEY, $incomingPaymentId);
                    }
                }
                break;

            case FlittStatus::DECLINED:
                $this->handleDeclined($order, $callbackData);
                break;

            case FlittStatus::EXPIRED:
                $this->handleExpired($order);
                break;

            case FlittStatus::REVERSED:
                $this->handleReversed($order, $callbackData);
                break;

            case FlittStatus::CREATED:
            case FlittStatus::PROCESSING:
                $this->logger->info('Flitt callback: order in intermediate state', [
                    'order_id' => $order->getIncrementId(),
                    'order_status' => $orderStatus,
                ]);
                break;

            default:
                $this->logger->warning('Flitt callback: unknown order_status', [
                    'order_id' => $order->getIncrementId(),
                    'order_status' => $orderStatus,
                ]);
                break;
        }

        $this->orderRepository->save($order);

        return $approvalResult;
    }

    /**
     * Handle declined payment.
     *
     * The raw Flitt `error_message` was previously leaked verbatim into the
     * order-history comment, which is customer-visible via "My Orders". We now
     * log the raw triple at ERROR and translate the decline through
     * {@see UserFacingErrorMapper} so the customer sees a localized,
     * actionable message instead of e.g. "Application error".
     *
     * @param Order $order
     * @param array<string, mixed> $callbackData
     */
    private function handleDeclined(Order $order, array $callbackData): void
    {
        if ($order->getState() === Order::STATE_CANCELED) {
            return;
        }

        $rawErrorCode = $callbackData['error_code'] ?? 0;
        $rawErrorMessage = (string) ($callbackData['error_message'] ?? '');
        $requestId = isset($callbackData['request_id'])
            ? (string) $callbackData['request_id']
            : null;

        $this->logger->error('TBC Flitt error mapped to user copy', [
            'context'       => 'callback.handleDeclined',
            'error_code'    => $rawErrorCode,
            'error_message' => $rawErrorMessage,
            'request_id'    => $requestId,
            'order_id'      => $order->getIncrementId(),
        ]);

        $friendly = $this->userFacingErrorMapper
            ->toLocalizedException($rawErrorCode, $rawErrorMessage, $requestId)
            ->getMessage();

        $order->cancel();
        $order->addCommentToStatusHistory(
            (string) __('Payment declined by TBC Bank. Reason: %1', $friendly)
        );
    }

    /**
     * Handle expired payment.
     */
    private function handleExpired(Order $order): void
    {
        if ($order->getState() === Order::STATE_CANCELED) {
            return;
        }

        $order->cancel();
        $order->addCommentToStatusHistory(
            (string) __('Payment session expired at TBC Bank.')
        );
    }

    /**
     * Handle reversed (refunded) payment.
     *
     * Flitt fires `order_status=reversed` for two distinct business events:
     *   (a) a pre-authorization was released without ever being captured;
     *   (b) a previously captured payment is being refunded.
     *
     * The handler therefore runs a pure state machine over the current order
     * state and the reversal amount (integer minor units, per CLAUDE.md #6):
     *
     *   closed | canceled                                  -> no-op (idempotent)
     *   pending_payment | payment_review | new | holded    -> cancel()
     *   processing | complete + full amount                -> close
     *   processing | complete + partial amount             -> comment only
     *   unknown state                                      -> log warning
     *
     * @param Order $order
     * @param array<string, mixed> $callbackData
     */
    private function handleReversed(Order $order, array $callbackData): void
    {
        $state = (string) $order->getState();

        // (a) Idempotent terminal states: safe to re-deliver without side effects.
        if ($state === Order::STATE_CLOSED || $state === Order::STATE_CANCELED) {
            return;
        }

        $transactionId = (string) ($callbackData['payment_id'] ?? 'N/A');

        // Integer-only amount math — never compare floats on money.
        $grandTotalMinor = (int) round(((float) $order->getGrandTotal()) * 100);
        $reverseAmount = (int) ($callbackData['reverse_amount'] ?? 0);
        if ($reverseAmount <= 0) {
            $reverseAmount = (int) ($callbackData['amount'] ?? $grandTotalMinor);
        }
        $isFullReversal = $reverseAmount >= $grandTotalMinor;

        // (b) Pre-capture states: no invoice yet, run Magento's item-level cancel.
        $preCaptureStates = [
            Order::STATE_PENDING_PAYMENT,
            Order::STATE_PAYMENT_REVIEW,
            Order::STATE_NEW,
            Order::STATE_HOLDED,
        ];
        if (in_array($state, $preCaptureStates, true)) {
            $order->cancel();
            $order->addCommentToStatusHistory(
                (string) __(
                    'Payment reversed by TBC Bank before capture. Transaction ID: %1. Order cancelled.',
                    $transactionId
                )
            );
            return;
        }

        // (c) Post-capture states: full reversal closes, partial leaves state.
        if ($state === Order::STATE_PROCESSING || $state === Order::STATE_COMPLETE) {
            if ($isFullReversal) {
                $order->setState(Order::STATE_CLOSED)->setStatus(Order::STATE_CLOSED);
                $order->addCommentToStatusHistory(
                    (string) __(
                        'Payment fully reversed by TBC Bank. Transaction ID: %1. Order closed.',
                        $transactionId
                    )
                );
                return;
            }

            $amountDisplay = number_format($reverseAmount / 100, 2, '.', '');
            $currency = (string) $order->getOrderCurrencyCode();
            $order->addCommentToStatusHistory(
                (string) __(
                    'Partial reversal by TBC Bank. Transaction ID: %1. Amount: %2 %3. Order state unchanged.',
                    $transactionId,
                    $amountDisplay,
                    $currency
                )
            );
            return;
        }

        // (d) Unexpected state — log, do not add a comment (avoid noisy history).
        $this->logger->warning(
            'Flitt callback: unexpected reversal on state ' . $state,
            [
                'order_id' => $order->getIncrementId(),
                'state' => $state,
                'transaction_id' => $transactionId,
            ]
        );
    }

    /**
     * CSRF validation is not applicable for server-to-server callbacks.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Disable CSRF validation for this callback endpoint.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
