<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;

/**
 * Sets TBC payment orders to pending_payment state after placement.
 *
 * Since no payment processing happens during order creation (the actual
 * payment is handled externally by Flitt), we need to explicitly set the
 * order to pending_payment. The callback, cron reconciler, or confirm
 * endpoint will later move it to processing after the bank approves.
 */
class SetPendingPaymentState implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getData('order');

        if ($order === null) {
            return;
        }

        $payment = $order->getPayment();

        if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
            return;
        }

        $order->setState(Order::STATE_PENDING_PAYMENT);
        $order->setStatus('pending_payment');
    }
}
