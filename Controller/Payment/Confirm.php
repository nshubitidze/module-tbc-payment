<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Http\Client\StatusClient;
use Shubo\TbcPayment\Gateway\Response\PaymentInfoKeys;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Called by the frontend JS after Flitt embed fires the success event.
 *
 * Checks the Flitt API for the real payment status and processes the order
 * immediately, so the customer doesn't have to wait for the server callback
 * or the cron reconciler.
 *
 * Race-safety: Callback (server-to-server) and PendingOrderReconciler can run
 * concurrently with this controller. We wrap the order load + state check +
 * approval inside a DB transaction with a SELECT ... FOR UPDATE on the order
 * row so only one of the three paths can transition the order to PROCESSING
 * and create the invoice.
 */
class Confirm implements HttpPostActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StatusClient $statusClient,
        private readonly CallbackValidator $callbackValidator,
        private readonly Config $config,
        private readonly SettlementService $settlementService,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
    ) {
    }

    public function execute(): ResultInterface
    {
        /** @var JsonResult $result */
        $result = $this->jsonFactory->create();

        try {
            $sessionOrder = $this->checkoutSession->getLastRealOrder();

            if (!$sessionOrder || !$sessionOrder->getEntityId()) {
                return $result->setData(['success' => false, 'message' => (string) __('No order found.')]);
            }

            /** @var Payment $sessionPayment */
            $sessionPayment = $sessionOrder->getPayment();
            $flittOrderId = (string) $sessionPayment->getAdditionalInformation('flitt_order_id');

            if ($flittOrderId === '') {
                return $result->setData(['success' => false, 'message' => (string) __('No Flitt order ID.')]);
            }

            // Fast pre-check before doing any external API call: skip if already done.
            if ($sessionOrder->getState() === Order::STATE_PROCESSING) {
                return $result->setData(['success' => true, 'already_processed' => true]);
            }

            $storeId = (int) $sessionOrder->getStoreId();
            $response = $this->statusClient->checkStatus($flittOrderId, $storeId);
            $responseData = $response['response'] ?? $response;
            $flittStatus = $responseData['order_status'] ?? '';

            $this->logger->info('TBC confirm: Flitt status check', [
                'order_id' => $sessionOrder->getIncrementId(),
                'flitt_status' => $flittStatus,
            ]);

            if ($flittStatus !== 'approved') {
                return $result->setData([
                    'success' => false,
                    'flitt_status' => $flittStatus,
                    'message' => (string) __('Payment not yet approved.'),
                ]);
            }

            if (!$this->callbackValidator->validate($responseData, $storeId)) {
                $this->logger->error('TBC confirm: signature validation failed', [
                    'order_id' => $sessionOrder->getIncrementId(),
                ]);
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Signature validation failed.'),
                ]);
            }

            $order = $this->processWithLock(
                (int) $sessionOrder->getEntityId(),
                $sessionOrder->getIncrementId(),
                $responseData,
                $storeId,
            );

            // Trigger settlement OUTSIDE the order transaction; settlement does its own
            // external HTTP call and we don't want to hold the row lock during it.
            if ($order !== null) {
                try {
                    $this->settlementService->settle($order);
                    $this->orderRepository->save($order);
                } catch (\Exception $e) {
                    $this->logger->error('TBC confirm: settlement failed', [
                        'order_id' => $order->getIncrementId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $result->setData(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('TBC confirm error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $result->setData([
                'success' => false,
                'message' => (string) __('Unable to confirm payment.'),
            ]);
        }
    }

    /**
     * Acquire a row-level lock on the order, re-check state, and process the approval.
     *
     * Returns the processed order (so the caller can run settlement after commit) or
     * null if another path beat us to it.
     *
     * @param array<string, mixed> $responseData
     */
    private function processWithLock(
        int $orderEntityId,
        string $incrementId,
        array $responseData,
        int $storeId,
    ): ?Order {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            // SELECT ... FOR UPDATE on the order row blocks concurrent
            // Callback / Confirm / Cron processing for the same order.
            $orderTable = $this->resourceConnection->getTableName('sales_order');
            $select = $connection->select()
                ->from($orderTable, ['entity_id', 'state'])
                ->where('entity_id = ?', $orderEntityId)
                ->forUpdate(true);
            $row = $connection->fetchRow($select);

            if ($row === false) {
                $connection->rollBack();
                $this->logger->warning('TBC confirm: order row vanished under lock', [
                    'order_id' => $incrementId,
                ]);
                return null;
            }

            // Re-load via the repository so we get the full domain object.
            $order = $this->loadOrder($incrementId);

            if ($order === null) {
                $connection->rollBack();
                $this->logger->warning('TBC confirm: order disappeared between lock and reload', [
                    'order_id' => $incrementId,
                ]);
                return null;
            }

            // Idempotent check INSIDE the locked region: if another path
            // already promoted the order, do nothing.
            if ($order->getState() === Order::STATE_PROCESSING) {
                $connection->commit();
                $this->logger->info('TBC confirm: already processed by concurrent path', [
                    'order_id' => $incrementId,
                ]);
                return null;
            }

            /** @var Payment $payment */
            $payment = $order->getPayment();
            $this->processApproval($order, $payment, $responseData, $storeId);
            $this->orderRepository->save($order);

            $connection->commit();

            return $order;
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Load an order by increment ID via the repository.
     */
    private function loadOrder(string $incrementId): ?Order
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        /** @var Order|null $order */
        $order = reset($orders) ?: null;

        return $order;
    }

    /**
     * @param array<string, mixed> $responseData
     */
    private function processApproval(Order $order, Payment $payment, array $responseData, int $storeId): void
    {
        PaymentInfoKeys::apply($payment, $responseData);

        $payment->setAdditionalInformation('flitt_order_id', $responseData['order_id'] ?? '');
        $payment->setAdditionalInformation('awaiting_flitt_confirmation', false);

        $paymentId = (string) ($responseData['payment_id'] ?? '');
        if ($paymentId !== '') {
            $payment->setTransactionId($paymentId);
            $payment->setParentTransactionId($order->getIncrementId() . '-auth');
        }

        if ($this->config->isPreauth($storeId)) {
            $payment->setAdditionalInformation('preauth_approved', true);
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(false);
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __('Funds held by TBC Bank. Payment ID: %1. Use "Capture Payment" to charge.', $paymentId)
            );
        } else {
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(true);
            $amountMinor = (int) ($responseData['amount'] ?? (int) round($order->getGrandTotal() * 100));
            $payment->registerCaptureNotification($amountMinor / 100);
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __('Payment approved by TBC Bank. Payment ID: %1', $paymentId)
            );
        }

        $this->logger->info('TBC confirm: order approved', [
            'order_id' => $order->getIncrementId(),
            'payment_id' => $paymentId,
        ]);
    }
}
