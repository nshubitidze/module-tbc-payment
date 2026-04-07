<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * TBC Payment gateway configuration reader.
 */
class Config extends \Magento\Payment\Gateway\Config\Config
{
    private const KEY_ACTIVE = 'active';
    private const KEY_TITLE = 'title';
    private const KEY_MERCHANT_ID = 'merchant_id';
    private const KEY_PASSWORD = 'password';

    private const KEY_SANDBOX_MODE = 'sandbox_mode';
    private const KEY_API_URL = 'api_url';
    private const KEY_SANDBOX_API_URL = 'sandbox_api_url';
    private const KEY_DEBUG = 'debug';
    private const KEY_SPLIT_PAYMENTS_ENABLED = 'split_payments_enabled';
    private const KEY_SPLIT_AUTO_SETTLE = 'split_auto_settle';
    private const KEY_SPLIT_RECEIVERS = 'split_receivers';
    private const KEY_EMBED_THEME_TYPE = 'embed_theme_type';
    private const KEY_EMBED_THEME_PRESET = 'embed_theme_preset';
    private const KEY_EMBED_LAYOUT = 'embed_layout';
    private const KEY_EMBED_OPTIONS_JSON = 'embed_options_json';
    private const KEY_ENABLE_WALLETS = 'enable_wallets';
    private const KEY_PAYMENT_LIFETIME = 'payment_lifetime';
    private const KEY_PAYMENT_ACTION_MODE = 'payment_action_mode';

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        ?string $methodCode = null,
        string $pathPattern = self::DEFAULT_PATH_PATTERN,
    ) {
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
    }

    public function isActive(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_ACTIVE, $storeId);
    }

    public function getTitle(?int $storeId = null): string
    {
        return (string) $this->getValue(self::KEY_TITLE, $storeId);
    }

    public function getMerchantId(?int $storeId = null): string
    {
        return (string) $this->getValue(self::KEY_MERCHANT_ID, $storeId);
    }

    public function getPassword(?int $storeId = null): string
    {
        $value = (string) $this->getValue(self::KEY_PASSWORD, $storeId);
        return $this->encryptor->decrypt($value);
    }

    public function isSandboxMode(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_SANDBOX_MODE, $storeId);
    }

    public function getApiUrl(?int $storeId = null): string
    {
        if ($this->isSandboxMode($storeId)) {
            return rtrim((string) $this->getValue(self::KEY_SANDBOX_API_URL, $storeId), '/');
        }

        return rtrim((string) $this->getValue(self::KEY_API_URL, $storeId), '/');
    }

    public function isDebugEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_DEBUG, $storeId);
    }

    public function isSplitPaymentsEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_SPLIT_PAYMENTS_ENABLED, $storeId);
    }

    public function isSplitAutoSettleEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_SPLIT_AUTO_SETTLE, $storeId);
    }

    public function getSplitReceivers(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_SPLIT_RECEIVERS, $storeId) ?: '');
    }

    public function getEmbedThemeType(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_EMBED_THEME_TYPE, $storeId) ?: 'light');
    }

    public function getEmbedThemePreset(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_EMBED_THEME_PRESET, $storeId) ?: 'reset');
    }

    public function getEmbedLayout(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_EMBED_LAYOUT, $storeId) ?: 'default');
    }

    public function getEmbedOptionsJson(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_EMBED_OPTIONS_JSON, $storeId) ?: '');
    }

    public function isWalletsEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_ENABLE_WALLETS, $storeId);
    }

    public function getPaymentLifetime(?int $storeId = null): int
    {
        $value = (int) ($this->getValue(self::KEY_PAYMENT_LIFETIME, $storeId) ?: 3600);
        return min(max($value, 300), 86400); // Clamp between 5 min and 24 hours
    }

    public function getPaymentActionMode(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_PAYMENT_ACTION_MODE, $storeId) ?: 'authorize');
    }

    public function isPreauth(?int $storeId = null): bool
    {
        return $this->getPaymentActionMode($storeId) === 'preauth';
    }

    /**
     * Generate Flitt signature for a set of parameters.
     *
     * Delegates to the official Cloudipsp SDK for signature calculation.
     *
     * @param array<string, mixed> $params Parameters to sign
     * @param string $secretKey The merchant password/secret key
     * @return string SHA1 signature
     */
    public static function generateSignature(array $params, string $secretKey): string
    {
        unset($params['signature'], $params['response_signature_string']);

        return \Cloudipsp\Helper\ApiHelper::generateSignature($params, $secretKey);
    }
}
