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
use Shubo\TbcPayment\Gateway\Http\Client\CaptureClient;

/**
 * Admin controller to manually capture a pre-authorized TBC payment.
 */
class Capture extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_TbcPayment::capture';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CaptureClient $captureClient,
        private readonly Config $config,
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
            $storeId = (int) $order->getStoreId();

            $flittOrderId = (string) $payment->getAdditionalInformation('flitt_order_id');
            if ($flittOrderId === '') {
                throw new \RuntimeException('No Flitt order ID found on this order.');
            }

            $amount = (int) round((float) $order->getGrandTotal() * 100);
            $currency = (string) $order->getOrderCurrencyCode();
            $merchantId = $this->config->getMerchantId($storeId);
            $password = $this->config->getPassword($storeId);

            $params = [
                'order_id' => $flittOrderId,
                'merchant_id' => $merchantId,
                'amount' => (string) $amount,
                'currency' => $currency,
            ];
            $params['signature'] = Config::generateSignature($params, $password);

            $response = $this->captureClient->capture($params, $storeId);
            $responseData = $response['response'] ?? $response;
            $captureStatus = $responseData['capture_status'] ?? $responseData['response_status'] ?? '';

            if ($captureStatus === 'captured' || $captureStatus === 'success') {
                $payment->setAdditionalInformation('preauth_approved', false);
                $payment->setAdditionalInformation('capture_status', 'captured');

                $payment->registerCaptureNotification((float) $order->getGrandTotal());

                $order->addCommentToStatusHistory(
                    (string) __('Payment captured by TBC Bank. Amount: %1 %2', $order->getGrandTotal(), $currency)
                );

                $this->orderRepository->save($order);
                $this->messageManager->addSuccessMessage((string) __('Payment has been captured successfully.'));
            } else {
                $errorMsg = $responseData['error_message']
                    ?? $responseData['response_description']
                    ?? 'Unknown error';
                throw new \RuntimeException('Capture failed: ' . $errorMsg);
            }
        } catch (\Exception $e) {
            $this->logger->error('Manual capture failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage((string) __('Capture failed: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
