<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ThemeType implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'light', 'label' => __('Light')],
            ['value' => 'dark', 'label' => __('Dark')],
        ];
    }
}
