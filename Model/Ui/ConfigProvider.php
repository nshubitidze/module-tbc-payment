<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Shubo\TbcPayment\Gateway\Config\Config;

/**
 * Provides checkout configuration for the TBC payment method.
 */
class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'shubo_tbc';

    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if (!$this->config->isActive()) {
            return [];
        }

        return [
            'payment' => [
                self::CODE => [
                    'isActive' => true,
                    'title' => $this->config->getTitle(),
                    'sdkUrl' => $this->config->getApiUrl() . '/latest/checkout-vue/checkout.js',
                    'sdkCssUrl' => $this->config->getApiUrl() . '/latest/checkout-vue/checkout.css',
                    'tokenUrl' => 'shubo_tbc/payment/gettoken',
                ],
            ],
        ];
    }
}
