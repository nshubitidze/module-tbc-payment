<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Plugin;

use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;

/**
 * Plugin to add TBC payment action buttons to the admin order view toolbar.
 *
 * Adds: Settle Payment, Capture Payment, Void Payment, Check Flitt Status.
 */
class AddSettleButton
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * Add TBC payment buttons to order view if conditions are met.
     */
    public function beforeSetLayout(View $subject): void
    {
        $order = $subject->getOrder();

        if ($order === null) {
            return;
        }

        /** @var Payment|null $payment */
        $payment = $order->getPayment();

        if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
            return;
        }

        $storeId = (int) $order->getStoreId();

        // "Check Flitt Status" button -- available for any TBC order with a flitt_order_id
        $flittOrderId = $payment->getAdditionalInformation('flitt_order_id');
        if (!empty($flittOrderId)) {
            $checkUrl = $subject->getUrl(
                'shubo_tbc/order/checkStatus',
                ['order_id' => $order->getEntityId()]
            );

            $subject->addButton(
                'tbc_check_status',
                [
                    'label' => __('Check Flitt Status'),
                    'class' => 'action-secondary',
                    'onclick' => "setLocation('{$checkUrl}')",
                ]
            );
        }

        // "Void Payment" button -- for preauth orders with held funds (not yet captured)
        if (
            $order->getState() === Order::STATE_PROCESSING
            && $payment->getAdditionalInformation('preauth_approved')
            && $payment->getAdditionalInformation('capture_status') !== 'captured'
        ) {
            $voidUrl = $subject->getUrl(
                'shubo_tbc/order/voidPayment',
                ['order_id' => $order->getEntityId()]
            );

            $subject->addButton(
                'tbc_void_payment',
                [
                    'label' => __('Void Payment'),
                    'class' => 'action-secondary',
                    'onclick' => "confirmSetLocation('"
                        . __('This will cancel the payment authorization. The order will be cancelled. Continue?')
                        . "', '{$voidUrl}')",
                ]
            );
        }

        if ($order->getState() !== Order::STATE_PROCESSING) {
            return;
        }

        // "Capture Payment" button -- for preauth orders awaiting capture
        if (
            $payment->getAdditionalInformation('preauth_approved')
            && $payment->getAdditionalInformation('capture_status') !== 'captured'
        ) {
            $captureUrl = $subject->getUrl(
                'shubo_tbc/order/capture',
                ['order_id' => $order->getEntityId()]
            );

            $subject->addButton(
                'tbc_capture_payment',
                [
                    'label' => __('Capture Payment'),
                    'class' => 'action-secondary',
                    'onclick' => "confirmSetLocation('"
                        . __('This will charge the held amount on the customer\'s card. Continue?')
                        . "', '{$captureUrl}')",
                ]
            );
        }

        // "Settle Payment" button -- for split payment distribution
        if (!$this->config->isSplitPaymentsEnabled($storeId)) {
            return;
        }

        if ($payment->getAdditionalInformation('settlement_status')) {
            return;
        }

        $settleUrl = $subject->getUrl(
            'shubo_tbc/order/settle',
            ['order_id' => $order->getEntityId()]
        );

        $subject->addButton(
            'tbc_settle_payment',
            [
                'label' => __('Settle Payment'),
                'class' => 'action-secondary',
                'onclick' => "confirmSetLocation('"
                    . __('This will distribute the payment to configured receivers. Continue?')
                    . "', '{$settleUrl}')",
            ]
        );
    }
}
