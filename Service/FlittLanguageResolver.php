<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Service;

use Magento\Framework\Locale\ResolverInterface;

/**
 * Maps the active store locale to a Flitt-supported language code.
 *
 * Flitt's Embed SDK and hosted payment page accept exactly three languages:
 * ka (Georgian), ru (Russian) and en (English, the default). The same
 * locale → Flitt-lang mapping was previously copied in three places
 * (ConfigProvider checkout config, Params token request, Redirect URL
 * request). Drift between copies could give a customer a Georgian embed but
 * an English hosted page, so the supported-language set lives here once.
 */
class FlittLanguageResolver
{
    public function __construct(
        private readonly ResolverInterface $localeResolver,
    ) {
    }

    /**
     * Resolve the current store locale to a Flitt-supported language code.
     *
     * @return string One of 'ka', 'ru' or 'en' (the default).
     */
    public function resolve(): string
    {
        $language = substr($this->localeResolver->getLocale(), 0, 2);

        return match ($language) {
            'ka' => 'ka',
            'ru' => 'ru',
            default => 'en',
        };
    }
}
