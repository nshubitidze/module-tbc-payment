<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Service\FlittLanguageResolver;

/**
 * Provides checkout configuration for the TBC payment method.
 */
class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'shubo_tbc';

    public function __construct(
        private readonly Config $config,
        private readonly FlittLanguageResolver $languageResolver,
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
                    'locale' => $this->languageResolver->resolve(),
                    'checkoutType' => $this->config->getCheckoutType(),
                    'brandLogoUrl' => $this->config->getBrandLogoUrl(),
                    'brandDescription' => $this->config->getBrandDescription(),
                    'brandAccentColor' => $this->config->getBrandAccentColor(),
                    'brandStripProvider' => $this->config->shouldStripProviderBranding(),
                    'embedThemeType' => $this->config->getEmbedThemeType(),
                    'embedThemePreset' => $this->config->getEmbedThemePreset(),
                    'embedLayout' => $this->config->getEmbedLayout(),
                    'embedOptionsJson' => $this->config->getEmbedOptionsJson(),
                    'enableWallets' => $this->config->isWalletsEnabled(),
                ],
            ],
        ];
    }
}
