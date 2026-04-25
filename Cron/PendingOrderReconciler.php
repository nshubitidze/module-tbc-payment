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
use Shubo\TbcPayment\Gateway\Response\PaymentInfoKeys;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;
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

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly StatusClient $statusClient,
        private readonly CallbackValidator $callbackValidator,
        private readonly SettlementService $settlementService,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
        private readonly AppState $appState,
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

        if (empty($flittOrderId)) {
            $this->logger->warning('TBC reconciler: no flitt_order_id for order', [
                'order_id' => $order->getIncrementId(),
            ]);
            return;
        }

        $storeId = (int) $order->getStoreId();

        try {
            $response = $this->statusClient->checkStatus($flittOrderId, $storeId);
        } catch (FlittApiException $e) {
            $this->logger->error('TBC reconciler: Flitt API error', [
                'order_id' => $order->getIncrementId(),
                'flitt_order_id' => $flittOrderId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $responseData = $response['response'] ?? $response;

        if (!is_array($responseData)) {
            $this->logger->error('TBC reconciler: unexpected response structure', [
                'order_id' => $order->getIncrementId(),
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

        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();
        try {
            match ($orderStatus) {
                'approved' => $this->handleApproved($order, $payment, $responseData),
                'declined' => $this->handleDeclined($order, $responseData),
                'expired' => $this->handleExpired($order),
                'created', 'processing' => $this->logger->info(
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
     * Handle approved payment -- mirrors Callback::handleApproved().
     *
     * @param Order $order Order to approve
     * @param Payment $payment Order payment
     * @param array<string, mixed> $responseData Flitt response data
     */
    private function handleApproved(Order $order, Payment $payment, array $responseData): void
    {
        if ($order->getState() === Order::STATE_PROCESSING) {
            return;
        }

        $this->storePaymentInfo($payment, $responseData);

        $storeId = (int) $order->getStoreId();

        if ($this->config->isPreauth($storeId)) {
            $payment->setAdditionalInformation('awaiting_flitt_confirmation', false);
            $payment->setAdditionalInformation('preauth_approved', true);

            $paymentId = (string) ($responseData['payment_id'] ?? '');
            if ($paymentId !== '') {
                // NOTE: We intentionally do NOT call setParentTransactionId() here.
                // In direct-sale (non-preauth) mode there is no auth transaction upstream
                // that the capture could point at, and inventing a synthetic
                // "{increment_id}-auth" parent_txn_id produced dangling parent links in the
                // admin transaction tree. When preauth capture is implemented as a
                // distinct workflow, reintroduce the parent pointer from a REAL auth row.
                $payment->setTransactionId($paymentId);
            }

            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(false);

            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __(
                    'Funds held by TBC Bank - preauth (reconciled by cron). Payment ID: %1. Use "Capture Payment" button to charge.',
                    $responseData['payment_id'] ?? 'N/A'
                )
            );

            $this->orderRepository->save($order);

            $this->logger->info('TBC reconciler: order preauth approved (funds held)', [
                'order_id' => $order->getIncrementId(),
                'payment_id' => $responseData['payment_id'] ?? 'N/A',
            ]);
            return;
        }

        // Set the Flitt payment ID as the transaction ID for the capture.
        // NOTE: We intentionally do NOT call setParentTransactionId() here.
        // In direct-sale (non-preauth) mode there is no auth transaction upstream
        // that the capture could point at, and inventing a synthetic
        // "{increment_id}-auth" parent_txn_id produced dangling parent links in the
        // admin transaction tree. When preauth capture is implemented as a
        // distinct workflow, reintroduce the parent pointer from a REAL auth row.
        $paymentId = (string) ($responseData['payment_id'] ?? '');
        if ($paymentId !== '') {
            $payment->setTransactionId($paymentId);
        }

        $payment->setIsTransactionPending(false);
        $payment->setIsTransactionClosed(true);
        $amountMinor = (int) ($responseData['amount'] ?? (int) round($order->getGrandTotal() * 100));
        $payment->registerCaptureNotification($amountMinor / 100);

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->addCommentToStatusHistory(
            (string) __(
                'Payment approved by TBC Bank (reconciled by cron). Payment ID: %1',
                $responseData['payment_id'] ?? 'N/A'
            )
        );

        $this->orderRepository->save($order);

        // Trigger settlement if auto-settle is enabled
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

    /**
     * Store Flitt response data in payment additional information.
     *
     * @param Payment $payment Order payment
     * @param array<string, mixed> $responseData Flitt response data
     */
    private function storePaymentInfo(Payment $payment, array $responseData): void
    {
        PaymentInfoKeys::apply($payment, $responseData);
    }
}
