<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Admin controller to trigger manual payment settlement for a TBC order.
 */
class Settle extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_TbcPayment::settle';

    /**
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param SettlementService $settlementService
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SettlementService $settlementService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    /**
     * Execute manual settlement for the given order.
     */
    public function execute(): ResultInterface
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);
            $result = $this->settlementService->settle($order, manual: true);
            $this->orderRepository->save($order);

            if ($result) {
                $this->messageManager->addSuccessMessage(
                    (string) __('Payment settlement has been sent successfully.')
                );
            } else {
                $warningMsg = 'Settlement was not processed.'
                    . ' Check if split payments are enabled'
                    . ' and receivers are configured.';
                $this->messageManager->addWarningMessage(
                    (string) __($warningMsg)
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Manual settlement failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(
                (string) __('Settlement failed: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
