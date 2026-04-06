<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Block\Payment;

use Magento\Payment\Block\Info as PaymentInfo;

/**
 * Payment info block for admin order view.
 */
class Info extends PaymentInfo
{
    /**
     * @return array<string, string>
     */
    protected function getSpecificInformation(): array
    {
        $info = [];
        $payment = $this->getInfo();

        $additionalData = [
            'Payment ID' => 'payment_id',
            'Order Status' => 'order_status',
            'Masked Card' => 'masked_card',
            'RRN' => 'rrn',
            'Approval Code' => 'approval_code',
            'Transaction Type' => 'tran_type',
        ];

        foreach ($additionalData as $label => $key) {
            $value = $payment->getAdditionalInformation($key);
            if (!empty($value)) {
                $info[(string) __($label)] = $value;
            }
        }

        return $info;
    }
}
