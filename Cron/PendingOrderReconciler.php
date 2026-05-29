<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;
use Shubo\TbcPayment\Gateway\Http\Client\StatusClient;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Model\FlittStatus;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;
use Shubo\TbcPayment\Service\ApprovalContext;
use Shubo\TbcPayment\Service\ApprovalResult;
use Shubo\TbcPayment\Service\OrderApprovalApplier;
use Shubo\TbcPayment\Service\PaymentLock;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Cron job that reconciles stuck pending TBC payment orders.
 *
 * Finds orders older than 15 minutes that are still pending,
 * checks their status via the Flitt API, and updates accordingly.
 */
class PendingOrderReconciler
{
    private const MAX_ORDERS_PER_RUN = 50;
    private const PENDING_THRESHOLD_MINUTES = 15;

    /**
     * IMPROVE-5: payment additional_information key holding the per-order
     * reconcile attempt counter. A non-terminal Flitt status (still
     * created/processing, transport error, or signature failure) increments
     * it; when it crosses the configured cap the order is moved to a terminal
     * "needs manual review" outcome so it DROPS OUT of the pending candidate
     * set and the oldest-unresolved backlog can drain past the 50-row page.
     */
    private const KEY_RECONCILE_ATTEMPTS = 'reconcile_attempts';

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly StatusClient $statusClient,
        private readonly CallbackValidator $callbackValidator,
        private readonly OrderApprovalApplier $approvalApplier,
        private readonly SettlementService $settlementService,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
        private readonly AppState $appState,
        private readonly PaymentLock $paymentLock,
    ) {
    }

    /**
     * Execute the pending order reconciliation.
     */
    public function execute(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException) {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        }

        $orders = $this->findPendingOrders();

        if (empty($orders)) {
            return;
        }

        $this->logger->info('TBC reconciler: processing pending orders', [
            'count' => count($orders),
        ]);

        foreach ($orders as $order) {
            try {
                $this->reconcileOrder($order);
            } catch (\Exception $e) {
                $this->logger->error('TBC reconciler: failed to reconcile order', [
                    'order_id' => $order->getIncrementId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // IMPROVE-6: emit a backlog health alert after the pass so a growing
        // stuck-pending queue surfaces (→ Sentry via the ERROR-level logger).
        $this->checkPendingBacklog($orders);
    }

    /**
     * IMPROVE-6: backlog health check for stuck PENDING orders.
     *
     * Counts the candidate orders selected this run and reports the age of the
     * oldest. When the count reaches the configured threshold an ERROR is
     * logged (→ Sentry, leveraging the Batch-1 logger rewire); under threshold
     * an INFO metric is emitted so the count is always observable.
     *
     * This lives in TBC (not Shubo_Ops) on purpose: registering a
     * ComponentCheckInterface provider into Ops' aggregator would require
     * editing Shubo_Ops/etc/di.xml, which is out of scope. A formal /health
     * endpoint contributor is noted as an Ops follow-up.
     *
     * @param Order[] $orders Candidate pending orders selected this run
     */
    private function checkPendingBacklog(array $orders): void
    {
        $count = count($orders);
        if ($count === 0) {
            return;
        }

        $oldestAgeSeconds = $this->oldestAgeSeconds($orders);
        $threshold = $this->config->getBacklogAlertThreshold();

        if ($count >= $threshold) {
            $this->logger->error('TBC reconciler: stuck-pending backlog over threshold', [
                'backlog_kind' => 'pending',
                'pending_count' => $count,
                'oldest_age_seconds' => $oldestAgeSeconds,
                'threshold' => $threshold,
            ]);
            return;
        }

        $this->logger->info('TBC reconciler: stuck-pending backlog metric', [
            'backlog_kind' => 'pending',
            'pending_count' => $count,
            'oldest_age_seconds' => $oldestAgeSeconds,
            'threshold' => $threshold,
        ]);
    }

    /**
     * Age in seconds of the oldest order in the set, or null if none parse.
     *
     * @param Order[] $orders
     */
    private function oldestAgeSeconds(array $orders): ?int
    {
        $oldest = null;
        foreach ($orders as $order) {
            $createdAt = (string) $order->getCreatedAt();
            if ($createdAt === '') {
                continue;
            }
            try {
                $age = time() - (new \DateTimeImmutable($createdAt))->getTimestamp();
            } catch (\Exception) {
                continue;
            }
            if ($oldest === null || $age > $oldest) {
                $oldest = $age;
            }
        }

        return $oldest;
    }

    /**
     * Find pending TBC payment orders older than the threshold.
     *
     * @return Order[]
     */
    private function findPendingOrders(): array
    {
        $threshold = new \DateTimeImmutable(
            sprintf('-%d minutes', self::PENDING_THRESHOLD_MINUTES)
        );

        $sortOrder = $this->sortOrderBuilder
            ->setField('created_at')
            ->setAscendingDirection()
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('state', [Order::STATE_PENDING_PAYMENT, Order::STATE_PAYMENT_REVIEW], 'in')
            ->addFilter('created_at', $threshold->format('Y-m-d H:i:s'), 'lt')
            ->setPageSize(self::MAX_ORDERS_PER_RUN)
            ->setSortOrders([$sortOrder])
            ->create();

        $orderList = $this->orderRepository->getList($searchCriteria);
        $pendingOrders = [];

        /** @var Order $order */
        foreach ($orderList->getItems() as $order) {
            $payment = $order->getPayment();
            if ($payment !== null && $payment->getMethod() === ConfigProvider::CODE) {
                $pendingOrders[] = $order;
            }
        }

        return $pendingOrders;
    }

    /**
     * Reconcile a single order by checking its Flitt status.
     *
     * @param Order $order Order to reconcile
     */
    private function reconcileOrder(Order $order): void
    {
        /** @var Payment $payment */
        $payment = $order->getPayment();
        $flittOrderId = $payment->getAdditionalInformation('flitt_order_id');

        $storeId = (int) $order->getStoreId();

        if (empty($flittOrderId)) {
            $this->logger->warning('TBC reconciler: no flitt_order_id for order', [
                'order_id' => $order->getIncrementId(),
            ]);
            // No flitt_order_id is itself unresolvable — count it so the order
            // does not loop forever and eventually drops to terminal.
            $this->recordAttempt($order, $payment, $storeId);
            return;
        }

        // IMPROVE-5: bump the per-order attempt counter for THIS reconcile pass
        // and, if the order has exhausted its retries (attempt cap) or aged past
        // 2× the payment lifetime, move it to a terminal "needs manual review"
        // outcome so it drops out of the pending candidate set. Returning true
        // means the order was made terminal — stop processing it.
        if ($this->recordAttempt($order, $payment, $storeId)) {
            return;
        }

        try {
            // StatusClient::checkStatus already unwraps the Flitt `response` envelope.
            $responseData = $this->statusClient->checkStatus($flittOrderId, $storeId);
        } catch (FlittApiException $e) {
            $this->logger->error('TBC reconciler: Flitt API error', [
                'order_id' => $order->getIncrementId(),
                'flitt_order_id' => $flittOrderId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        // Edge-cases-matrix §4: Flitt returns HTTP 200 with
        // `response_status=failure` + `error_code=1011` for order_ids it has
        // never seen — the classic "token endpoint timed out before Flitt
        // registered the order" orphan class. Signature validation below
        // would reject this error envelope (Flitt does not sign failure
        // payloads the same way), so the not-found branch has to run BEFORE
        // the signature check.
        if ($this->isOrderNotFoundResponse($responseData)) {
            $this->handleOrderNotFound($order, (string) $flittOrderId, $storeId, $responseData);
            return;
        }

        if (!$this->callbackValidator->validate($responseData, $storeId)) {
            $this->logger->error('TBC reconciler: signature validation failed', [
                'order_id' => $order->getIncrementId(),
                'flitt_order_id' => $flittOrderId,
            ]);
            return;
        }

        $orderStatus = $responseData['order_status'] ?? '';

        $this->logger->info('TBC reconciler: Flitt status for order', [
            'order_id' => $order->getIncrementId(),
            'flitt_order_id' => $flittOrderId,
            'flitt_status' => $orderStatus,
        ]);

        // IMPROVE-2: serialize against Callback/Confirm/ReturnAction/CheckStatus
        // via the advisory lock, keyed by flitt_order_id. withLock returns null
        // on contention — another worker beat us; the next tick will see the
        // order already processing and short-circuit, so we just skip.
        $this->paymentLock->withLock(
            (string) $flittOrderId,
            function () use ($order, $responseData, $orderStatus): void {
                // Re-read state after acquiring the lock. For the capture status
                // (approved) an already-processing order is done — skip so a
                // concurrent path that already captured isn't re-captured.
                if ($orderStatus === FlittStatus::APPROVED && $order->getState() === Order::STATE_PROCESSING) {
                    $this->logger->info('TBC reconciler: order already processing inside lock, skipping', [
                        'order_id' => $order->getIncrementId(),
                    ]);
                    return;
                }

                $connection = $this->resourceConnection->getConnection();
                $connection->beginTransaction();
                try {
                    match ($orderStatus) {
                        FlittStatus::APPROVED => $this->handleApproved($order, $responseData),
                        FlittStatus::DECLINED => $this->handleDeclined($order, $responseData),
                        FlittStatus::EXPIRED => $this->handleExpired($order),
                        FlittStatus::CREATED, FlittStatus::PROCESSING => $this->logger->info(
                            'TBC reconciler: order still in progress, will retry',
                            ['order_id' => $order->getIncrementId(), 'flitt_status' => $orderStatus]
                        ),
                        default => $this->logger->warning(
                            'TBC reconciler: unknown Flitt status',
                            ['order_id' => $order->getIncrementId(), 'flitt_status' => $orderStatus]
                        ),
                    };
                    $connection->commit();
                } catch (\Exception $e) {
                    $connection->rollBack();
                    throw $e;
                }
            }
        );
    }

    /**
     * IMPROVE-5: increment the per-order reconcile attempt counter and, if the
     * order has exhausted its retries, move it to a terminal outcome.
     *
     * Terminal triggers (either is sufficient):
     *   - attempt count exceeds the configured cap (default 12), OR
     *   - the order is older than 2× the payment lifetime.
     *
     * The counter is persisted via OrderRepository so it survives across cron
     * ticks and process restarts. A terminal order is cancelled (it leaves the
     * pending state and so DROPS OUT of findPendingOrders' candidate set),
     * which is what lets a >50-row backlog of oldest-unresolved orders drain
     * past the page size instead of starving newer ones.
     *
     * @param Order $order Order being reconciled
     * @param Payment $payment Order payment carrying the counter
     * @param int $storeId Store scope for config
     * @return bool True if the order was moved to terminal (stop processing it)
     */
    private function recordAttempt(Order $order, Payment $payment, int $storeId): bool
    {
        $attempts = (int) $payment->getAdditionalInformation(self::KEY_RECONCILE_ATTEMPTS) + 1;
        $payment->setAdditionalInformation(self::KEY_RECONCILE_ATTEMPTS, $attempts);

        $maxAttempts = $this->config->getReconcileMaxAttempts($storeId);
        $lifetime = $this->config->getPaymentLifetime($storeId);
        $ageSeconds = $this->orderAgeSeconds($order);
        $agedOut = $ageSeconds !== null && $ageSeconds > (2 * $lifetime);

        if ($attempts > $maxAttempts || $agedOut) {
            $this->moveToTerminal($order, $attempts, $maxAttempts, $ageSeconds, $lifetime);
            return true;
        }

        // Persist the bumped counter so the next tick sees it. A bare save here
        // (the resolving paths below open their own transaction) keeps the
        // counter coherent even when the Flitt status is still in-progress.
        $this->orderRepository->save($order);

        return false;
    }

    /**
     * Move an exhausted order to a terminal "needs manual review" outcome.
     *
     * Cancels the order (so it leaves the pending candidate set), stamps a
     * marker on the payment for observability, and emits an ERROR alert
     * (→ Sentry) so ops know an order could not be auto-reconciled. Mirrors the
     * existing handleOrderNotFound terminal pattern (cancel + history + save in
     * its own transaction).
     *
     * @param Order $order Order to terminate
     * @param int $attempts Attempt count reached
     * @param int $maxAttempts Configured cap
     * @param int|null $ageSeconds Order age in seconds (null if unparsable)
     * @param int $lifetime Configured payment lifetime in seconds
     */
    private function moveToTerminal(
        Order $order,
        int $attempts,
        int $maxAttempts,
        ?int $ageSeconds,
        int $lifetime,
    ): void {
        /** @var Payment $payment */
        $payment = $order->getPayment();
        $reason = $attempts > $maxAttempts ? 'attempt_cap_exceeded' : 'age_exceeded_2x_lifetime';

        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();
        try {
            $payment->setAdditionalInformation('reconcile_terminal', $reason);
            if ($order->canCancel()) {
                $order->cancel();
            }
            $order->addCommentToStatusHistory(
                (string) __(
                    'TBC reconciler: order moved to manual review after %1 attempts (cap %2). '
                    . 'Reason: %3. The reconciler will no longer retry this order.',
                    $attempts,
                    $maxAttempts,
                    $reason
                )
            );
            $this->orderRepository->save($order);
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }

        $this->logger->error('TBC reconciler: order exhausted retries, moved to manual review', [
            'order_id' => $order->getIncrementId(),
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'age_seconds' => $ageSeconds,
            'lifetime' => $lifetime,
            'reason' => $reason,
        ]);
    }

    /**
     * Age of the order in seconds, or null if created_at is missing/unparsable.
     *
     * @param Order $order Order to measure
     */
    private function orderAgeSeconds(Order $order): ?int
    {
        $createdAt = (string) $order->getCreatedAt();
        if ($createdAt === '') {
            return null;
        }
        try {
            return time() - (new \DateTimeImmutable($createdAt))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Detect Flitt's "we have never heard of this order" response.
     *
     * Flitt returns HTTP 200 with either
     *   {response_status: "failure", error_code: 1011, ...}
     * or an effectively empty response status when the order_id wasn't
     * registered on their side (e.g. because /api/checkout/url timed out on
     * our side before it actually hit the endpoint).
     *
     * @param array<string, mixed> $responseData
     */
    private function isOrderNotFoundResponse(array $responseData): bool
    {
        $status = (string) ($responseData['response_status'] ?? '');
        $errorCode = (int) ($responseData['error_code'] ?? 0);

        if ($errorCode === 1011) {
            return true;
        }

        // Empty/failure envelope with no order_status field — Flitt has no
        // record of the order.
        if ($status === 'failure' && !isset($responseData['order_status'])) {
            return true;
        }

        if ($status === '' && !isset($responseData['order_status'])) {
            return true;
        }

        return false;
    }

    /**
     * Handle a Flitt "order not found" response: if the Magento order is
     * older than the payment lifetime we cancel it (Flitt will never
     * register it now), otherwise we leave it alone — Flitt may still be
     * catching up, and a premature cancel would race a late success.
     *
     * @param array<string, mixed> $responseData
     */
    private function handleOrderNotFound(
        Order $order,
        string $flittOrderId,
        int $storeId,
        array $responseData
    ): void {
        $createdAt = (string) $order->getCreatedAt();
        $ageSeconds = null;
        if ($createdAt !== '') {
            try {
                $ageSeconds = time() - (new \DateTimeImmutable($createdAt))->getTimestamp();
            } catch (\Exception) {
                $ageSeconds = null;
            }
        }

        $lifetime = $this->config->getPaymentLifetime($storeId);

        if ($ageSeconds === null || $ageSeconds <= $lifetime) {
            $this->logger->info(
                'TBC reconciler: Flitt reports order not found but order is within lifetime; retry later',
                [
                    'order_id'       => $order->getIncrementId(),
                    'flitt_order_id' => $flittOrderId,
                    'age_seconds'    => $ageSeconds,
                    'lifetime'       => $lifetime,
                    'error_code'     => $responseData['error_code'] ?? null,
                ]
            );
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();
        try {
            $order->cancel();
            $order->addCommentToStatusHistory(
                (string) __(
                    'Flitt never received this order; cancelled by reconciler after '
                    . 'payment lifetime (%1s) expired. flitt_order_id: %2',
                    $lifetime,
                    $flittOrderId
                )
            );
            $this->orderRepository->save($order);
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }

        $this->logger->warning('TBC reconciler: cancelled orphaned order (Flitt never registered it)', [
            'order_id'       => $order->getIncrementId(),
            'flitt_order_id' => $flittOrderId,
            'age_seconds'    => $ageSeconds,
            'lifetime'       => $lifetime,
        ]);
    }

    /**
     * Handle approved payment.
     *
     * The shared approve mutation (transaction id, preauth-vs-direct capture,
     * state/status, history comment) is delegated to OrderApprovalApplier. The
     * reconciler keeps ownership of persistence and the post-commit settlement,
     * matching the other capture paths (the applier never saves or settles).
     *
     * @param Order $order Order to approve
     * @param array<string, mixed> $responseData Flitt response data
     */
    private function handleApproved(Order $order, array $responseData): void
    {
        if ($order->getState() === Order::STATE_PROCESSING) {
            return;
        }

        $applyResult = $this->approvalApplier->apply($order, $responseData, ApprovalContext::Reconciler);

        // IMPROVE-8: the applier refused the capture because the Flitt amount
        // diverged from the order grand total (cart edited mid-flow). It logged
        // at `critical` and mutated nothing; leave the order untouched (no save,
        // no settle) for an admin to reconcile.
        if ($applyResult === ApprovalResult::RefusedAmountMismatch) {
            $this->logger->error('TBC reconciler: capture refused (amount mismatch), left for admin reconcile', [
                'order_id' => $order->getIncrementId(),
            ]);
            return;
        }

        $this->orderRepository->save($order);

        // Preauth only holds funds — no capture, so nothing to settle yet.
        // Settlement runs only on the direct-sale (auto-capture) path, exactly
        // as it did before the applier extraction.
        if ($this->config->isPreauth((int) $order->getStoreId())) {
            $this->logger->info('TBC reconciler: order preauth approved (funds held)', [
                'order_id' => $order->getIncrementId(),
                'payment_id' => $responseData['payment_id'] ?? 'N/A',
            ]);
            return;
        }

        // Trigger settlement if auto-settle is enabled.
        try {
            $this->settlementService->settle($order);
            $this->orderRepository->save($order);
        } catch (\Exception $e) {
            $this->logger->error('TBC reconciler: settlement failed', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('TBC reconciler: order approved', [
            'order_id' => $order->getIncrementId(),
            'payment_id' => $responseData['payment_id'] ?? 'N/A',
        ]);
    }

    /**
     * Handle declined payment.
     *
     * @param Order $order Order to cancel
     * @param array<string, mixed> $responseData Flitt response data
     */
    private function handleDeclined(Order $order, array $responseData): void
    {
        $order->cancel();
        $order->addCommentToStatusHistory(
            (string) __(
                'Payment declined by TBC Bank (reconciled by cron). Reason: %1',
                $responseData['error_message'] ?? 'N/A'
            )
        );

        $this->orderRepository->save($order);

        $this->logger->info('TBC reconciler: order declined and cancelled', [
            'order_id' => $order->getIncrementId(),
        ]);
    }

    /**
     * Handle expired payment.
     *
     * @param Order $order Order to cancel
     */
    private function handleExpired(Order $order): void
    {
        $order->cancel();
        $order->addCommentToStatusHistory(
            (string) __('Payment session expired at TBC Bank (reconciled by cron).')
        );

        $this->orderRepository->save($order);

        $this->logger->info('TBC reconciler: order expired and cancelled', [
            'order_id' => $order->getIncrementId(),
        ]);
    }
}
