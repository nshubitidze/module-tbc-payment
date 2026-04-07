<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ThemePreset implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'reset', 'label' => __('Default (No preset)')],
            ['value' => 'black', 'label' => __('Black')],
            ['value' => 'silver', 'label' => __('Silver')],
            ['value' => 'vibrant_gold', 'label' => __('Vibrant Gold')],
            ['value' => 'euphoric_pink', 'label' => __('Euphoric Pink')],
            ['value' => 'heated_steel', 'label' => __('Heated Steel')],
            ['value' => 'nude_pink', 'label' => __('Nude Pink')],
            ['value' => 'tropical_gold', 'label' => __('Tropical Gold')],
            ['value' => 'navy_shimmer', 'label' => __('Navy Shimmer')],
        ];
    }
}
