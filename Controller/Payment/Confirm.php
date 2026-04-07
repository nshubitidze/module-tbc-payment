<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
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
 * Called by the frontend JS after Flitt embed fires the success event.
 *
 * Checks the Flitt API for the real payment status and processes the order
 * immediately, so the customer doesn't have to wait for the server callback
 * or the cron reconciler.
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
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $order = $this->checkoutSession->getLastRealOrder();

            if (!$order || !$order->getEntityId()) {
                return $result->setData(['success' => false, 'message' => 'No order found.']);
            }

            /** @var Payment $payment */
            $payment = $order->getPayment();
            $flittOrderId = (string) $payment->getAdditionalInformation('flitt_order_id');

            if ($flittOrderId === '') {
                return $result->setData(['success' => false, 'message' => 'No Flitt order ID.']);
            }

            // Already processed — skip
            if ($order->getState() === Order::STATE_PROCESSING) {
                return $result->setData(['success' => true, 'already_processed' => true]);
            }

            $storeId = (int) $order->getStoreId();
            $response = $this->statusClient->checkStatus($flittOrderId, $storeId);
            $responseData = $response['response'] ?? $response;
            $flittStatus = $responseData['order_status'] ?? '';

            $this->logger->info('TBC confirm: Flitt status check', [
                'order_id' => $order->getIncrementId(),
                'flitt_status' => $flittStatus,
            ]);

            if ($flittStatus !== 'approved') {
                return $result->setData([
                    'success' => false,
                    'flitt_status' => $flittStatus,
                    'message' => 'Payment not yet approved.',
                ]);
            }

            if (!$this->callbackValidator->validate($responseData, $storeId)) {
                $this->logger->error('TBC confirm: signature validation failed', [
                    'order_id' => $order->getIncrementId(),
                ]);
                return $result->setData(['success' => false, 'message' => 'Signature validation failed.']);
            }

            $this->processApproval($order, $payment, $responseData, $storeId);
            $this->orderRepository->save($order);

            // Trigger settlement if configured
            try {
                $this->settlementService->settle($order);
                $this->orderRepository->save($order);
            } catch (\Exception $e) {
                $this->logger->error('TBC confirm: settlement failed', [
                    'order_id' => $order->getIncrementId(),
                    'error' => $e->getMessage(),
                ]);
            }

            return $result->setData(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('TBC confirm error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $result->setData(['success' => false, 'message' => 'Unable to confirm payment.']);
        }
    }

    /**
     * @param array<string, mixed> $responseData
     */
    private function processApproval(Order $order, Payment $payment, array $responseData, int $storeId): void
    {
        $infoKeys = [
            'payment_id', 'order_status', 'masked_card', 'rrn',
            'approval_code', 'tran_type', 'card_type', 'card_bin',
            'eci', 'fee', 'actual_amount', 'actual_currency',
        ];
        foreach ($infoKeys as $key) {
            if (!empty($responseData[$key])) {
                $payment->setAdditionalInformation($key, $responseData[$key]);
            }
        }

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
