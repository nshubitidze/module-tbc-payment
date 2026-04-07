<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\ResourceConnection;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Handles Flitt server-to-server callback notifications.
 *
 * Flitt sends POST with JSON body containing payment result.
 * This controller verifies signature, updates order status, and creates invoice.
 */
class Callback implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly \Magento\Framework\App\Request\Http $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Json $jsonSerializer,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CallbackValidator $callbackValidator,
        private readonly SettlementService $settlementService,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
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
            $incrementId = $this->extractIncrementId((string) $orderId);
            $order = $this->loadOrderByIncrementId($incrementId);

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

            $connection = $this->resourceConnection->getConnection();
            $connection->beginTransaction();
            try {
                // Re-load order inside transaction to get fresh state
                $order = $this->loadOrderByIncrementId($incrementId);
                if ($order === null) {
                    $connection->rollBack();
                    $this->logger->error('Flitt callback: order not found on reload', ['order_id' => $orderId]);
                    return $result->setHttpResponseCode(404)->setData(['status' => 'error']);
                }

                $this->processCallback($order, $callbackData);
                $connection->commit();
            } catch (\Exception $e) {
                $connection->rollBack();
                throw $e;
            }

            // Trigger settlement if payment was approved and auto-settle is enabled
            if (($callbackData['order_status'] ?? '') === 'approved') {
                try {
                    $this->settlementService->settle($order);
                    // Save again to persist settlement additional info
                    $this->orderRepository->save($order);
                } catch (\Exception $e) {
                    $this->logger->error('Settlement after callback failed', [
                        'order_id' => $orderId,
                        'error' => $e->getMessage(),
                    ]);
                    // Don't fail the callback response -- settlement can be retried
                }
            }

            return $result->setData(['status' => 'ok']);
        } catch (\Exception $e) {
            $this->logger->error('Flitt callback error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return $result->setHttpResponseCode(500)->setData(['status' => 'error']);
        }
    }

    /**
     * Process the callback data and update order accordingly.
     *
     * @param Order $order
     * @param array<string, mixed> $callbackData
     */
    private function processCallback(Order $order, array $callbackData): void
    {
        /** @var Payment $payment */
        $payment = $order->getPayment();
        $orderStatus = $callbackData['order_status'] ?? '';

        // Store the full Flitt order_id so refunds can reference it
        $payment->setAdditionalInformation('flitt_order_id', $callbackData['order_id'] ?? '');

        // Store callback data in payment additional info
        $infoKeys = [
            'payment_id', 'order_status', 'masked_card', 'rrn',
            'approval_code', 'tran_type', 'sender_email',
            'card_type', 'card_bin', 'eci', 'fee',
            'response_code', 'response_description',
            'actual_amount', 'actual_currency',
        ];

        foreach ($infoKeys as $key) {
            if (!empty($callbackData[$key])) {
                $payment->setAdditionalInformation($key, $callbackData[$key]);
            }
        }

        if (isset($callbackData['payment_id'])) {
            $payment->setTransactionId((string) $callbackData['payment_id']);
        }

        switch ($orderStatus) {
            case 'approved':
                $this->handleApproved($order, $payment, $callbackData);
                break;

            case 'declined':
                $this->handleDeclined($order, $callbackData);
                break;

            case 'expired':
                $this->handleExpired($order);
                break;

            case 'reversed':
                $this->handleReversed($order, $callbackData);
                break;

            case 'created':
            case 'processing':
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
    }

    /**
     * Handle approved payment.
     *
     * @param Order $order
     * @param Payment $payment
     * @param array<string, mixed> $callbackData
     */
    private function handleApproved(Order $order, Payment $payment, array $callbackData): void
    {
        if ($order->getState() === Order::STATE_PROCESSING) {
            return; // Already processed, avoid double-processing
        }

        $storeId = (int) $order->getStoreId();

        if ($this->config->isPreauth($storeId)) {
            $payment->setAdditionalInformation('awaiting_flitt_confirmation', false);
            $payment->setAdditionalInformation('preauth_approved', true);

            $paymentId = (string) ($callbackData['payment_id'] ?? '');
            if ($paymentId !== '') {
                $payment->setTransactionId($paymentId);
                }

            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(false);

            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __('Funds held by TBC Bank (preauth). Payment ID: %1. Use "Capture Payment" button to charge.', $callbackData['payment_id'] ?? 'N/A')
            );
            return;
        }

        $payment->setAdditionalInformation('awaiting_flitt_confirmation', false);

        // Set the Flitt payment ID as transaction ID and link to the auth transaction
        $paymentId = (string) ($callbackData['payment_id'] ?? '');
        if ($paymentId !== '') {
            $payment->setTransactionId($paymentId);
        }

        $payment->setIsTransactionPending(false);
        $payment->setIsTransactionClosed(true);
        $amountMinor = (int) ($callbackData['amount'] ?? (int) round($order->getGrandTotal() * 100));
        $payment->registerCaptureNotification($amountMinor / 100);

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->addCommentToStatusHistory(
            (string) __('Payment approved by TBC Bank. Payment ID: %1', $callbackData['payment_id'] ?? 'N/A')
        );
    }

    /**
     * Handle declined payment.
     *
     * @param Order $order
     * @param array<string, mixed> $callbackData
     */
    private function handleDeclined(Order $order, array $callbackData): void
    {
        if ($order->getState() === Order::STATE_CANCELED) {
            return;
        }

        $order->cancel();
        $order->addCommentToStatusHistory(
            (string) __('Payment declined by TBC Bank. Reason: %1', $callbackData['error_message'] ?? 'N/A')
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
     * @param Order $order
     * @param array<string, mixed> $callbackData
     */
    private function handleReversed(Order $order, array $callbackData): void
    {
        if ($order->getState() === Order::STATE_CLOSED) {
            return;
        }

        $order->addCommentToStatusHistory(
            (string) __('Payment reversed by TBC Bank. Transaction ID: %1', $callbackData['payment_id'] ?? 'N/A')
        );
    }

    /**
     * Extract Magento increment ID from the Flitt order_id.
     *
     * Flitt order_id format: duka_{incrementId}_{timestamp}
     * Falls back to the raw value if format doesn't match (e.g. legacy orders).
     */
    private function extractIncrementId(string $flittOrderId): string
    {
        if (preg_match('/^duka_(.+)_\d+$/', $flittOrderId, $matches)) {
            return $matches[1];
        }

        return $flittOrderId;
    }

    /**
     * Load order by increment ID.
     */
    private function loadOrderByIncrementId(string $incrementId): ?Order
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
