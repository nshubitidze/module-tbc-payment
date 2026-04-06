<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\Locale\ResolverInterface;
use Shubo\TbcPayment\Gateway\Config\Config;

/**
 * Provides checkout configuration for the TBC payment method.
 */
class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'shubo_tbc';

    public function __construct(
        private readonly Config $config,
        private readonly ResolverInterface $localeResolver,
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
                    'locale' => $this->resolveLocale(),
                ],
            ],
        ];
    }

    /**
     * Map Magento locale to Flitt-supported language code.
     *
     * Flitt Embed supports: ka (Georgian), en (English), ru (Russian).
     */
    private function resolveLocale(): string
    {
        $locale = $this->localeResolver->getLocale();
        $language = substr($locale, 0, 2);

        return match ($language) {
            'ka' => 'ka',
            'ru' => 'ru',
            default => 'en',
        };
    }
}
