<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Plugin;

use Magento\Framework\Data\Form\FormKey;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Plugin to add TBC payment action buttons to the admin order view toolbar.
 *
 * Adds: Settle Payment, Capture Payment, Void Payment, Check Flitt Status.
 */
class AddSettleButton
{
    public function __construct(
        private readonly Config $config,
        private readonly SettlementService $settlementService,
        private readonly FormKey $formKey,
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

            // VoidPayment is now POST-only (HttpPostActionInterface). A GET
            // setLocation would be rejected by the controller and by the admin
            // form-key validator, so we POST a transient form carrying the admin
            // form_key, behind the same confirm dialog.
            $subject->addButton(
                'tbc_void_payment',
                [
                    'label' => __('Void Payment'),
                    'class' => 'action-secondary',
                    'onclick' => $this->postOnClick(
                        $voidUrl,
                        (string) __(
                            'This will cancel the payment authorization. The order will be cancelled. Continue?'
                        )
                    ),
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

        // IMPROVE-4: hide the button ONLY when settlement genuinely succeeded.
        // A failed attempt (recorded under settlement_last_status, never
        // settlement_status) must keep the button visible so an admin can
        // retry — the previous truthy-settlement_status check hid it forever.
        if ($this->settlementService->isAlreadySettled($payment)) {
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

    /**
     * Build an onclick that confirms, then POSTs a transient form carrying the
     * admin form_key to $url. Used for state-mutating actions whose controller
     * is POST-only (e.g. VoidPayment). The values are JSON-encoded so quotes in
     * the translated confirm text or the URL cannot break out of the handler.
     */
    private function postOnClick(string $url, string $confirmMessage): string
    {
        $urlJs = json_encode($url, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
        $msgJs = json_encode($confirmMessage, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
        $keyJs = json_encode($this->formKey->getFormKey(), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);

        return 'if (window.confirm(' . $msgJs . ')) {'
            . 'var f = document.createElement("form");'
            . 'f.method = "POST";'
            . 'f.action = ' . $urlJs . ';'
            . 'var k = document.createElement("input");'
            . 'k.type = "hidden"; k.name = "form_key"; k.value = ' . $keyJs . ';'
            . 'f.appendChild(k); document.body.appendChild(f); f.submit();'
            . '}';
    }
}
