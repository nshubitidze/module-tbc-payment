<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;
use Shubo\TbcPayment\Service\PaymentLock;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * IMPROVE-4: settlement-recovery cron.
 *
 * The pending-order reconciler only selects orders in pending_payment /
 * payment_review, so a PROCESSING order whose split settlement FAILED is never
 * re-selected by it — the merchant payout would silently never pay. This job
 * closes that gap: it finds approved/PROCESSING TBC orders whose settlement is
 * empty or last-failed (and not successfully settled) and re-drives settlement
 * via SettlementService, which reuses the BUG-7 distinct-order_id retry suffix.
 *
 * Each order is retried up to a CAPPED number of attempts
 * (Config::getSettlementMaxAttempts); when the cap is exceeded the job stops
 * retrying and emits an ERROR (→ Sentry) so a stuck payout surfaces for manual
 * intervention. It also reports the unsettled-approved backlog (IMPROVE-6).
 */
class SettlementReconciler
{
    private const MAX_ORDERS_PER_RUN = 50;

    /**
     * Lower bound on order age before a PROCESSING order is eligible for
     * settlement recovery. A freshly-captured order may be settled by the
     * synchronous capture path within seconds; waiting avoids racing it.
     */
    private const SETTLE_THRESHOLD_MINUTES = 10;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly SettlementService $settlementService,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly AppState $appState,
        private readonly PaymentLock $paymentLock,
    ) {
    }

    /**
     * Execute the settlement reconciliation pass.
     */
    public function execute(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException) {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        }

        $orders = $this->findUnsettledOrders();

        if ($orders === []) {
            return;
        }

        $this->logger->info('TBC settlement reconciler: processing unsettled orders', [
            'count' => count($orders),
        ]);

        $stillUnsettled = [];
        foreach ($orders as $order) {
            try {
                if (!$this->reconcileSettlement($order)) {
                    $stillUnsettled[] = $order;
                }
            } catch (\Exception $e) {
                $stillUnsettled[] = $order;
                $this->logger->error('TBC settlement reconciler: failed to settle order', [
                    'order_id' => $order->getIncrementId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // IMPROVE-6: report the unsettled-approved backlog after the pass.
        $this->checkSettlementBacklog($stillUnsettled);
    }

    /**
     * Re-drive settlement for a single order.
     *
     * Skips orders that have exhausted the configured settlement-attempt cap
     * (emitting an ERROR once, on the run that crosses the cap), otherwise calls
     * SettlementService::settle() which advances the BUG-7 retry suffix and only
     * stamps the blocking settlement_status on genuine success.
     *
     * The settle() call is wrapped in {@see PaymentLock::withLock}, keyed on the
     * order's flitt_order_id — the SAME key every other settle() caller uses
     * (the per-path capture controllers and the admin Settle button). Without
     * the lock an unlocked re-drive would race the settlement_attempt
     * read-modify-write (the BUG-7 distinct-suffix counter) against a concurrent
     * tick or the admin Settle button, producing duplicate Flitt settlement
     * order_ids. On lock contention (null) we skip this order and treat it as
     * still-unsettled; the next tick retries.
     *
     * @param Order $order Order to attempt settlement for
     * @return bool True if settlement succeeded this run
     */
    private function reconcileSettlement(Order $order): bool
    {
        /** @var Payment $payment */
        $payment = $order->getPayment();
        $storeId = (int) $order->getStoreId();
        $attempts = (int) $payment->getAdditionalInformation('settlement_attempt');
        $maxAttempts = $this->config->getSettlementMaxAttempts($storeId);

        // Cap reached: stop retrying. Stamp a marker so the ERROR fires once
        // (when the marker is first set), not on every subsequent run.
        if ($attempts >= $maxAttempts) {
            if (!$payment->getAdditionalInformation('settlement_recovery_exhausted')) {
                $payment->setAdditionalInformation('settlement_recovery_exhausted', true);
                $order->addCommentToStatusHistory(
                    (string) __(
                        'Settlement could not be completed after %1 attempts (cap %2). '
                        . 'Manual intervention required.',
                        $attempts,
                        $maxAttempts
                    )
                );
                $this->orderRepository->save($order);
                $this->logger->error('TBC settlement reconciler: settlement attempts exhausted', [
                    'order_id' => $order->getIncrementId(),
                    'attempts' => $attempts,
                    'max_attempts' => $maxAttempts,
                ]);
            }
            return false;
        }

        $flittOrderId = (string) $payment->getAdditionalInformation('flitt_order_id');
        if ($flittOrderId === '') {
            // No lock key available — settle() itself would skip on an empty
            // flitt_order_id, so do not advance the attempt counter unlocked.
            return false;
        }

        $settled = $this->paymentLock->withLock(
            $flittOrderId,
            function () use ($order): bool {
                $result = $this->settlementService->settle($order);
                $this->orderRepository->save($order);

                return $result;
            }
        );

        if ($settled === null) {
            // Contention — another tick or the admin Settle button holds the
            // lock. Skip; the next tick retries.
            $this->logger->info('TBC settlement reconciler: settlement lock contended, deferring', [
                'order_id' => $order->getIncrementId(),
            ]);
            return false;
        }

        if ($settled) {
            $this->logger->info('TBC settlement reconciler: settlement recovered', [
                'order_id' => $order->getIncrementId(),
            ]);
        }

        return $settled;
    }

    /**
     * Find PROCESSING TBC orders whose split settlement has not genuinely
     * succeeded yet.
     *
     * The state filter (PROCESSING) + ascending created_at gives the
     * oldest-unresolved first; the in-memory pass then drops orders that are
     * too fresh, not TBC, not split-enabled, or already genuinely settled.
     *
     * @return Order[]
     */
    private function findUnsettledOrders(): array
    {
        $threshold = new \DateTimeImmutable(
            sprintf('-%d minutes', self::SETTLE_THRESHOLD_MINUTES)
        );

        $sortOrder = $this->sortOrderBuilder
            ->setField('created_at')
            ->setAscendingDirection()
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('state', Order::STATE_PROCESSING)
            ->addFilter('created_at', $threshold->format('Y-m-d H:i:s'), 'lt')
            ->setPageSize(self::MAX_ORDERS_PER_RUN)
            ->setSortOrders([$sortOrder])
            ->create();

        $orderList = $this->orderRepository->getList($searchCriteria);
        $candidates = [];

        /** @var Order $order */
        foreach ($orderList->getItems() as $order) {
            $payment = $order->getPayment();
            if (!$payment instanceof Payment || $payment->getMethod() !== ConfigProvider::CODE) {
                continue;
            }

            $storeId = (int) $order->getStoreId();
            if (!$this->config->isSplitPaymentsEnabled($storeId)) {
                continue;
            }

            // Genuinely-settled orders are done — skip. A failed attempt is NOT
            // settled (settlement_status only carries a success value) so it
            // stays a candidate and gets re-driven.
            if ($this->settlementService->isAlreadySettled($payment)) {
                continue;
            }

            $candidates[] = $order;
        }

        return $candidates;
    }

    /**
     * IMPROVE-6: backlog health alert for unsettled-approved orders.
     *
     * Reports the count of orders that remained unsettled after this run; when
     * the count reaches the configured threshold an ERROR is logged (→ Sentry),
     * otherwise an INFO metric keeps the count observable.
     *
     * @param Order[] $orders Orders still unsettled after the pass
     */
    private function checkSettlementBacklog(array $orders): void
    {
        $count = count($orders);
        if ($count === 0) {
            return;
        }

        $threshold = $this->config->getBacklogAlertThreshold();

        if ($count >= $threshold) {
            $this->logger->error('TBC settlement reconciler: unsettled-approved backlog over threshold', [
                'backlog_kind' => 'unsettled',
                'unsettled_count' => $count,
                'threshold' => $threshold,
            ]);
            return;
        }

        $this->logger->info('TBC settlement reconciler: unsettled-approved backlog metric', [
            'backlog_kind' => 'unsettled',
            'unsettled_count' => $count,
            'threshold' => $threshold,
        ]);
    }
}
