<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the payment action mode configuration field.
 */
class PaymentAction implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'authorize', 'label' => __('Authorize (Charge Immediately)')],
            ['value' => 'preauth', 'label' => __('Authorize Only (Hold Funds)')],
        ];
    }
}
