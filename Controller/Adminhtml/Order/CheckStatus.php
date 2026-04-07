<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Http\Client\StatusClient;

/**
 * Admin controller to check the Flitt payment status for a TBC order.
 *
 * Read-only operation -- queries the Flitt API and shows the result as a message.
 */
class CheckStatus extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Sales::actions_edit';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StatusClient $statusClient,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
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
        } catch (\Exception $e) {
            $this->logger->error('Status check failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage((string) __('Status check failed: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
