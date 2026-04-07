<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Http\Client\StatusClient;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Admin controller to check and sync the Flitt payment status for a TBC order.
 *
 * Queries the Flitt API. If the payment is approved but the order hasn't been
 * updated yet, processes the approval (capture + invoice).
 */
class CheckStatus extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Sales::actions_edit';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StatusClient $statusClient,
        private readonly CallbackValidator $callbackValidator,
        private readonly Config $config,
        private readonly SettlementService $settlementService,
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
            /** @var Payment $payment */
            $payment = $order->getPayment();
            $flittOrderId = (string) $payment->getAdditionalInformation('flitt_order_id');

            if ($flittOrderId === '') {
                $this->messageManager->addWarningMessage((string) __('No Flitt order ID found.'));
                return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
            }

            $storeId = (int) $order->getStoreId();
            $response = $this->statusClient->checkStatus($flittOrderId, $storeId);
            $responseData = $response['response'] ?? $response;
            $flittStatus = $responseData['order_status'] ?? 'unknown';

            $this->messageManager->addSuccessMessage(
                (string) __('Flitt payment status: %1 | Payment ID: %2 | Card: %3',
                    $flittStatus,
                    $responseData['payment_id'] ?? 'N/A',
                    $responseData['masked_card'] ?? 'N/A'
                )
            );

            // If Flitt says approved but order is still pending — process it
            if (
                $flittStatus === 'approved'
                && in_array($order->getState(), [Order::STATE_PAYMENT_REVIEW, Order::STATE_PENDING_PAYMENT], true)
            ) {
                if (!$this->callbackValidator->validate($responseData, $storeId)) {
                    $this->messageManager->addErrorMessage((string) __('Signature validation failed. Order not updated.'));
                    return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
                }

                $this->processApproval($order, $payment, $responseData, $storeId);
                $this->orderRepository->save($order);

                // Trigger settlement if configured
                try {
                    $this->settlementService->settle($order);
                    $this->orderRepository->save($order);
                } catch (\Exception $e) {
                    $this->logger->error('Settlement after manual status check failed', [
                        'order_id' => $order->getIncrementId(),
                        'error' => $e->getMessage(),
                    ]);
                }

                $this->messageManager->addSuccessMessage(
                    (string) __('Order updated to processing. Payment captured.')
                );
            } elseif (
                in_array($flittStatus, ['declined', 'expired'], true)
                && $order->getState() !== Order::STATE_CANCELED
            ) {
                $order->cancel();
                $order->addCommentToStatusHistory(
                    (string) __('Order cancelled after manual status check. Flitt status: %1', $flittStatus)
                );
                $this->orderRepository->save($order);
                $this->messageManager->addWarningMessage(
                    (string) __('Payment %1. Order has been cancelled.', $flittStatus)
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Status check failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage((string) __('Status check failed: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }

    /**
     * Process an approved payment — mirrors Callback::handleApproved().
     */
    /**
     * @param array<string, mixed> $responseData
     */
    private function processApproval(Order $order, Payment $payment, array $responseData, int $storeId): void
    {
        // Store callback-equivalent data
        $infoKeys = [
            'payment_id', 'order_status', 'masked_card', 'rrn',
            'approval_code', 'tran_type', 'card_type', 'card_bin',
            'eci', 'actual_amount', 'actual_currency',
        ];
        foreach ($infoKeys as $key) {
            if (!empty($responseData[$key])) {
                $payment->setAdditionalInformation($key, $responseData[$key]);
            }
        }

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
                (string) __('Funds held by TBC Bank (manual status check). Payment ID: %1. Use "Capture Payment" to charge.', $paymentId)
            );
        } else {
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(true);
            $amountMinor = (int) ($responseData['amount'] ?? (int) round($order->getGrandTotal() * 100));
            $payment->registerCaptureNotification($amountMinor / 100);
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __('Payment approved by TBC Bank (manual status check). Payment ID: %1', $paymentId)
            );
        }
    }
}
