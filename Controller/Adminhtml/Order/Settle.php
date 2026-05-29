<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;
use Shubo\TbcPayment\Service\PaymentLock;
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
     * @param PaymentLock $paymentLock
     */
    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SettlementService $settlementService,
        private readonly LoggerInterface $logger,
        private readonly PaymentLock $paymentLock,
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
            /** @var Payment|null $payment */
            $payment = $order->getPayment();
            if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
                throw new LocalizedException(__('Invalid payment method for this action.'));
            }

            // Serialize the settle() read-modify-write (the BUG-7 distinct
            // settlement_attempt suffix) against the settlement-recovery cron
            // and any concurrent click, using the SAME flitt_order_id lock key
            // every other settle() caller uses. On contention the cron / another
            // click is already settling — surface a benign retry notice.
            $flittOrderId = (string) $payment->getAdditionalInformation('flitt_order_id');
            if ($flittOrderId === '') {
                throw new LocalizedException(__('No Flitt order ID found on this order.'));
            }

            $result = $this->paymentLock->withLock(
                $flittOrderId,
                function () use ($order): bool {
                    $settled = $this->settlementService->settle($order, manual: true);
                    $this->orderRepository->save($order);

                    return $settled;
                }
            );

            if ($result === null) {
                $this->messageManager->addNoticeMessage(
                    (string) __('Another settlement is already in progress for this order. Please try again in a moment.')
                );
            } elseif ($result) {
                $this->messageManager->addSuccessMessage(
                    (string) __('Payment settlement has been sent successfully.')
                );
            } else {
                $this->messageManager->addWarningMessage(
                    (string) __(
                        'Settlement was not processed. Check if split payments are enabled'
                        . ' and receivers are configured.'
                    )
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
