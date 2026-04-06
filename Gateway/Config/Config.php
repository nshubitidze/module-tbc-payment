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
    private const KEY_CURRENCY = 'currency';
    private const KEY_SANDBOX_MODE = 'sandbox_mode';
    private const KEY_API_URL = 'api_url';
    private const KEY_SANDBOX_API_URL = 'sandbox_api_url';
    private const KEY_DEBUG = 'debug';
    private const KEY_SPLIT_PAYMENTS_ENABLED = 'split_payments_enabled';

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

    public function getCurrency(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_CURRENCY, $storeId) ?: 'GEL');
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

    /**
     * Generate Flitt signature for a set of parameters.
     *
     * @param array<string, mixed> $params Parameters to sign
     * @param string $secretKey The merchant password/secret key
     * @return string SHA1 signature
     */
    public static function generateSignature(array $params, string $secretKey): string
    {
        unset($params['signature'], $params['response_signature_string']);
        $params = array_filter(
            $params,
            static fn (mixed $value): bool => $value !== '' && $value !== null
        );
        ksort($params);
        $values = array_values($params);
        array_unshift($values, $secretKey);

        return sha1(implode('|', $values));
    }
}
