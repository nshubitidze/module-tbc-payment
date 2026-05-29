<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;
use Shubo\TbcPayment\Gateway\Http\Client\CaptureClient;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;
use Shubo\TbcPayment\Service\PaymentLock;

/**
 * Admin controller to manually capture a pre-authorized TBC payment.
 *
 * IMPROVE-3 — idempotency + concurrency hardening:
 *  - A pre-API guard short-circuits when the local `capture_status` is already
 *    `captured` (no API call, no second registerCaptureNotification).
 *  - The capture runs inside {@see PaymentLock::withLock} keyed on the Flitt
 *    order_id (which is guaranteed present — the empty case short-circuits with a
 *    LocalizedException before the lock). Inside the lock the order/payment are
 *    RELOADED from the repository and the `capture_status` guard re-checked
 *    against that FRESH read — the pre-check alone is TOCTOU-racy: a serialized
 *    two-click race (A captures+saves+releases, then B acquires) would otherwise
 *    see B's stale, pre-lock in-memory snapshot with an empty `capture_status`
 *    and fire a SECOND real capture. Reloading under the lock means B observes
 *    A's persisted `capture_status==='captured'` and short-circuits.
 *  - The Flitt benign "already captured" reply (HTTP 2xx with a non-success
 *    capture_status whose text mentions "already", or a thrown
 *    {@see FlittApiException} whose message does) sets the idempotency sentinel
 *    and saves WITHOUT a second registerCaptureNotification (the invoice already
 *    exists; double-firing raises a duplicate-invoice exception).
 *  - A real failure is fail-closed: order state and capture_status are left
 *    untouched and the admin sees mapped retry copy.
 *
 * SIMPLIFY-5 — the wire-payload build + Flitt signature now live in
 * {@see CaptureClient}; this controller only hands order-level inputs to the
 * client and orchestrates order/payment + lock + invoice/state.
 */
class Capture extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_TbcPayment::capture';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CaptureClient $captureClient,
        private readonly PaymentLock $paymentLock,
        private readonly UserFacingErrorMapper $userFacingErrorMapper,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);

            /** @var Payment|null $payment */
            $payment = $order->getPayment();
            if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
                throw new LocalizedException(__('Invalid payment method for this action.'));
            }

            $flittOrderId = (string) $payment->getAdditionalInformation('flitt_order_id');
            if ($flittOrderId === '') {
                throw new LocalizedException(__('No Flitt order ID found on this order.'));
            }

            // Pre-API idempotency guard. If a prior click already captured,
            // skip the Flitt round-trip entirely — no API call, no second
            // registerCaptureNotification (the invoice already exists).
            if ($payment->getAdditionalInformation('capture_status') === 'captured') {
                $this->logger->info('TBC capture: already captured locally, skipping API', [
                    'order_id' => $orderId,
                    'flitt_order_id' => $flittOrderId,
                ]);
                $this->messageManager->addSuccessMessage((string) __('Payment was already captured.'));
                return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
            }

            // Lock on the Flitt order_id (guaranteed non-empty above; the empty
            // case already short-circuited with a LocalizedException). This is the
            // same key the frontend capture paths and the cron reconciler use, so
            // an admin click serializes against a concurrent callback/cron.
            $ran = $this->paymentLock->withLock(
                $flittOrderId,
                fn (): bool => $this->doCapture($flittOrderId, $orderId)
            );

            if ($ran === null) {
                // Another admin click holds the lock right now. Do NOT touch
                // state — surface a benign retry message.
                $this->logger->warning('TBC capture: lock contention, skipped', [
                    'order_id' => $orderId,
                    'flitt_order_id' => $flittOrderId,
                ]);
                $this->messageManager->addErrorMessage(
                    (string) __('Another capture is already in progress for this order. Please try again in a moment.')
                );
            }
        } catch (LocalizedException $e) {
            // LocalizedException is by Magento convention author-safe to show.
            $this->logger->error('TBC manual capture — LocalizedException', [
                'order_id' => $orderId,
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            // Bland fallback so raw exception text never leaks to admin UI.
            $this->logger->error('TBC manual capture failed', [
                'order_id' => $orderId,
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->messageManager->addErrorMessage(
                (string) __('Capture failed. See shubo_tbc_payment.log for details.')
            );
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }

    /**
     * Capture body, executed while holding the per-order advisory lock.
     *
     * RELOADS the order/payment from the repository inside the lock so the
     * `capture_status` re-check reads committed state, not the pre-lock
     * in-memory snapshot. This is what actually defeats the serialized
     * two-concurrent-clicks race: when click A has already captured, saved and
     * released the lock, click B's reload here observes A's persisted
     * `capture_status==='captured'` and short-circuits with NO API call and NO
     * second registerCaptureNotification. After the reload it performs exactly
     * one of:
     *   - a real first capture (one registerCaptureNotification), or
     *   - the benign "already captured" path (sentinel only, no notification), or
     *   - a fail-closed real failure (no state mutation, mapped retry copy).
     *
     * @return bool true once the lock body has run (the withLock sentinel)
     */
    private function doCapture(string $flittOrderId, int $orderId): bool
    {
        // Reload under the lock so the guard reads committed state (mirrors
        // Confirm/CheckStatus's in-lock reload).
        /** @var Order $order */
        $order = $this->orderRepository->get($orderId);
        $payment = $order->getPayment();
        if (!$payment instanceof Payment) {
            throw new LocalizedException(__('Invalid payment method for this action.'));
        }

        // Re-check inside the lock against the FRESH read — defeats the
        // two-concurrent-clicks race (B sees A's persisted capture_status).
        if ($payment->getAdditionalInformation('capture_status') === 'captured') {
            $this->logger->info('TBC capture: already captured (re-checked under lock), skipping API', [
                'order_id' => $orderId,
                'flitt_order_id' => $flittOrderId,
            ]);
            $this->messageManager->addSuccessMessage((string) __('Payment was already captured.'));
            return true;
        }

        $storeId = (int) $order->getStoreId();
        $currency = (string) $order->getOrderCurrencyCode();
        // Integer money: the held pre-auth equals the order grand total in tetri.
        $amountMinor = (int) round((float) $order->getGrandTotal() * 100);

        try {
            $response = $this->captureClient->capture($flittOrderId, $amountMinor, $currency, $storeId);
            $responseData = $response['response'] ?? $response;
            /** @var array<string, mixed> $responseData */
            $captureStatus = (string) ($responseData['capture_status'] ?? $responseData['response_status'] ?? '');

            if ($captureStatus === 'captured' || $captureStatus === 'success') {
                // Legitimate FIRST capture — registerCaptureNotification exactly
                // once, which fires sales_order_payment_pay → Commission → Payout.
                $payment->setAdditionalInformation('preauth_approved', false);
                $payment->setAdditionalInformation('capture_status', 'captured');
                $payment->registerCaptureNotification($amountMinor / 100);

                $order->addCommentToStatusHistory(
                    (string) __(
                        'Payment captured by TBC Bank. Amount: %1 %2',
                        $order->getGrandTotal(),
                        $currency
                    )
                );
                $this->orderRepository->save($order);
                $this->messageManager->addSuccessMessage(
                    (string) __('Payment has been captured successfully.')
                );
                return true;
            }

            // HTTP 2xx but a non-success capture_status. Flitt reports logical
            // errors in the body — route the benign "already captured" reply to
            // the idempotent path; anything else is a real failure.
            $errorMessage = (string) ($responseData['error_message']
                ?? $responseData['response_description']
                ?? 'Unknown error');
            $errorCode = $responseData['error_code'] ?? $responseData['response_code'] ?? 0;

            if ($this->mentionsAlreadyCaptured($errorMessage)) {
                $this->markAlreadyCaptured($order, $payment, $orderId, $flittOrderId, $errorMessage);
                return true;
            }

            // Real failure — fail-closed.
            $this->failClosed($orderId, $flittOrderId, $errorCode, $errorMessage);
            return true;
        } catch (FlittApiException $e) {
            // Non-2xx / transport failure. FlittHttpClient surfaces non-2xx as
            // "Flitt API returned HTTP <code>" (no raw Flitt text), so a thrown
            // exception is treated as a real failure unless its own message
            // happens to mention "already" (defensive).
            $rawMessage = $e->getMessage();
            if ($this->mentionsAlreadyCaptured($rawMessage)) {
                $this->markAlreadyCaptured($order, $payment, $orderId, $flittOrderId, $rawMessage);
                return true;
            }

            $this->failClosed($orderId, $flittOrderId, (int) $e->getCode(), $rawMessage);
            return true;
        }
    }

    /**
     * Benign idempotent path: the bank says the order is already captured.
     *
     * Set the local sentinel and save WITHOUT a second registerCaptureNotification
     * — the invoice already exists (a prior click or a capture-path callback),
     * and re-firing would raise a duplicate-invoice exception.
     */
    private function markAlreadyCaptured(
        Order $order,
        Payment $payment,
        int $orderId,
        string $flittOrderId,
        string $rawMessage,
    ): void {
        $this->logger->warning('TBC capture: already captured (idempotent)', [
            'context' => 'admin.capture',
            'order_id' => $orderId,
            'flitt_order_id' => $flittOrderId,
            'raw_message' => $rawMessage,
        ]);
        $payment->setAdditionalInformation('preauth_approved', false);
        $payment->setAdditionalInformation('capture_status', 'captured');
        $this->orderRepository->save($order);
        $this->messageManager->addSuccessMessage((string) __('Payment was already captured.'));
    }

    /**
     * Fail-closed: leave order state and capture_status untouched and surface
     * mapped retry copy. The cron reconciler can pick the order up next pass.
     */
    private function failClosed(int $orderId, string $flittOrderId, int|string $errorCode, string $rawMessage): void
    {
        $this->logger->error('TBC capture failed', [
            'context' => 'admin.capture',
            'order_id' => $orderId,
            'flitt_order_id' => $flittOrderId,
            'error_code' => $errorCode,
            'raw_message' => $rawMessage,
        ]);
        $friendly = $this->userFacingErrorMapper->toLocalizedException($errorCode, $rawMessage);
        $this->messageManager->addErrorMessage($friendly->getMessage());
    }

    /**
     * Does the Flitt message indicate the order was already captured/approved?
     * Mirrors the BOG benign-reply detection (already + a capture-ish stem).
     */
    private function mentionsAlreadyCaptured(string $message): bool
    {
        $lowered = strtolower($message);
        if (!str_contains($lowered, 'already')) {
            return false;
        }
        foreach (['captur', 'complet', 'approve', 'settl'] as $stem) {
            if (str_contains($lowered, $stem)) {
                return true;
            }
        }
        return false;
    }
}
