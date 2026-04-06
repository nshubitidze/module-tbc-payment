<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * AJAX endpoint for obtaining a Flitt checkout token.
 *
 * Called by the frontend JS component after order placement.
 */
class GetToken implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CommandPoolInterface $commandPool,
        private readonly PaymentDataObjectFactory $paymentDataObjectFactory,
        private readonly LoggerInterface $logger,
        private readonly RequestInterface $request,
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $orderId = $this->checkoutSession->getLastRealOrder()->getId();

            if (!$orderId) {
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('No order found in session'),
                ]);
            }

            $order = $this->orderRepository->get((int) $orderId);
            $payment = $order->getPayment();

            if ($payment === null) {
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Payment information not found'),
                ]);
            }

            $paymentDataObject = $this->paymentDataObjectFactory->create($payment);

            $this->commandPool->get('initialize')->execute([
                'payment' => $paymentDataObject,
                'amount' => (float) $order->getGrandTotal(),
                'store_id' => (int) $order->getStoreId(),
            ]);

            $token = $payment->getAdditionalInformation('flitt_token');

            if (empty($token)) {
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Failed to obtain payment token'),
                ]);
            }

            $this->orderRepository->save($order);

            return $result->setData([
                'success' => true,
                'token' => $token,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('TBC GetToken error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $result->setData([
                'success' => false,
                'message' => (string) __('Unable to initialize payment. Please try again.'),
            ]);
        }
    }
}
