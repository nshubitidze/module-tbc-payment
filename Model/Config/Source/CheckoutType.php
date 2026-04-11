<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CheckoutType implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'embed', 'label' => __('Embedded Card Form')],
            ['value' => 'redirect', 'label' => __('Redirect to Payment Page')],
        ];
    }
}
