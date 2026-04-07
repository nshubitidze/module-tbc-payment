<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class EmbedLayout implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'default', 'label' => __('Default')],
            ['value' => 'plain', 'label' => __('Plain')],
            ['value' => 'wallets_only', 'label' => __('Wallets Only')],
        ];
    }
}
