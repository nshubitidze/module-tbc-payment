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
    private const KEY_CHECKOUT_TYPE = 'checkout_type';
    private const KEY_BRAND_LOGO_URL = 'brand_logo_url';
    private const KEY_BRAND_DESCRIPTION = 'brand_description';
    private const KEY_BRAND_ACCENT_COLOR = 'brand_accent_color';
    private const KEY_BRAND_STRIP_PROVIDER = 'brand_strip_provider';
    private const KEY_HTTP_CONNECT_TIMEOUT = 'http_connect_timeout';
    private const KEY_HTTP_READ_TIMEOUT = 'http_read_timeout';
    private const KEY_HTTP_STATUS_RETRIES = 'http_status_retries';
    private const KEY_CALLBACK_IP_ALLOWLIST = 'callback_ip_allowlist';
    private const KEY_SETTLEMENT_MAX_ATTEMPTS = 'settlement_max_attempts';
    private const KEY_RECONCILE_MAX_ATTEMPTS = 'reconcile_max_attempts';
    private const KEY_BACKLOG_ALERT_THRESHOLD = 'backlog_alert_threshold';

    private const DEFAULT_HTTP_CONNECT_TIMEOUT = 5;
    private const DEFAULT_HTTP_READ_TIMEOUT = 30;
    private const DEFAULT_HTTP_STATUS_RETRIES = 1;
    private const DEFAULT_SETTLEMENT_MAX_ATTEMPTS = 6;
    private const DEFAULT_RECONCILE_MAX_ATTEMPTS = 12;
    private const DEFAULT_BACKLOG_ALERT_THRESHOLD = 50;

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

    public function getCheckoutType(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_CHECKOUT_TYPE, $storeId) ?: 'embed');
    }

    public function isRedirectMode(?int $storeId = null): bool
    {
        return $this->getCheckoutType($storeId) === 'redirect';
    }

    public function getBrandLogoUrl(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_BRAND_LOGO_URL, $storeId) ?: '');
    }

    public function getBrandDescription(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_BRAND_DESCRIPTION, $storeId) ?: '');
    }

    public function getBrandAccentColor(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_BRAND_ACCENT_COLOR, $storeId) ?: '');
    }

    public function shouldStripProviderBranding(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_BRAND_STRIP_PROVIDER, $storeId);
    }

    /**
     * Connection timeout (seconds) for Flitt HTTP calls.
     *
     * Bounds how long the TCP/TLS handshake may take before the call is
     * abandoned, so a black-holed Flitt host cannot pin a PHP worker for the
     * full read timeout. Clamped to a sane 1–60s window.
     */
    public function getHttpConnectTimeout(?int $storeId = null): int
    {
        $value = (int) ($this->getValue(self::KEY_HTTP_CONNECT_TIMEOUT, $storeId)
            ?: self::DEFAULT_HTTP_CONNECT_TIMEOUT);

        return min(max($value, 1), 60);
    }

    /**
     * Read/total timeout (seconds) for Flitt HTTP calls.
     *
     * Bounds the full request once connected. Clamped to a 1–120s window.
     */
    public function getHttpReadTimeout(?int $storeId = null): int
    {
        $value = (int) ($this->getValue(self::KEY_HTTP_READ_TIMEOUT, $storeId)
            ?: self::DEFAULT_HTTP_READ_TIMEOUT);

        return min(max($value, 1), 120);
    }

    /**
     * Number of additional attempts for idempotent (status-only) Flitt calls.
     *
     * Applies ONLY to retryable reads (status checks). Non-idempotent calls
     * (capture/void/settle/refund/token) never retry regardless of this value.
     * Clamped to 0–3.
     */
    public function getHttpStatusRetries(?int $storeId = null): int
    {
        $value = (int) ($this->getValue(self::KEY_HTTP_STATUS_RETRIES, $storeId)
            ?? self::DEFAULT_HTTP_STATUS_RETRIES);

        return min(max($value, 0), 3);
    }

    /**
     * IMPROVE-9: Flitt callback source-IP allowlist.
     *
     * Returns the configured comma/whitespace-separated list of Flitt egress
     * IPs that are permitted to POST to the server-to-server callback endpoint,
     * parsed into a trimmed list of non-empty entries.
     *
     * FAIL-OPEN: when the admin leaves the field EMPTY this returns an empty
     * list, and the Callback controller allows ALL source IPs. This is
     * deliberate — environments behind a reverse proxy / load balancer that
     * does not forward the real client IP must not be locked out. The allowlist
     * is an optional defence-in-depth layer ON TOP of signature validation,
     * never the primary gate.
     *
     * Default (config.xml): EMPTY — out-of-the-box fail-open. An admin opts in
     * by pasting Flitt's egress IPs (the documented `54.154.216.60,3.75.125.89`
     * are a suggested starting point, shown in the system.xml field comment).
     *
     * @return list<string>
     */
    public function getCallbackIpAllowlist(?int $storeId = null): array
    {
        $raw = (string) ($this->getValue(self::KEY_CALLBACK_IP_ALLOWLIST, $storeId) ?: '');
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $ip): bool => $ip !== ''
        ));
    }

    /**
     * IMPROVE-4: maximum number of settlement attempts before the
     * settlement-recovery cron stops re-driving and alerts ops.
     *
     * Each attempt against Flitt uses a distinct settlement order_id (the
     * BUG-7 retry-suffix). The cap bounds the suffix counter so a permanently
     * failing settlement (e.g. a receiver merchant_id that no longer exists)
     * does not retry forever; when exceeded the recovery cron emits an
     * ERROR (→ Sentry) for manual intervention. Clamped to a sane 1–20 window.
     */
    public function getSettlementMaxAttempts(?int $storeId = null): int
    {
        $value = (int) ($this->getValue(self::KEY_SETTLEMENT_MAX_ATTEMPTS, $storeId)
            ?: self::DEFAULT_SETTLEMENT_MAX_ATTEMPTS);

        return min(max($value, 1), 20);
    }

    /**
     * IMPROVE-5: maximum number of reconcile attempts before the pending-order
     * reconciler moves an order to a terminal "needs manual review" outcome.
     *
     * The counter is persisted on the payment so terminal orders drop out of
     * the candidate set, allowing a >page-size backlog of oldest-unresolved
     * orders to drain instead of starving newer ones. Clamped to a sane
     * 1–50 window.
     */
    public function getReconcileMaxAttempts(?int $storeId = null): int
    {
        $value = (int) ($this->getValue(self::KEY_RECONCILE_MAX_ATTEMPTS, $storeId)
            ?: self::DEFAULT_RECONCILE_MAX_ATTEMPTS);

        return min(max($value, 1), 50);
    }

    /**
     * IMPROVE-6: backlog size at or above which the reconciler / recovery cron
     * emits an ERROR-level health alert (→ Sentry).
     *
     * The backlog health check counts stuck pending orders plus
     * unsettled-approved orders; when the total reaches this threshold an
     * ERROR is logged so ops are paged. Clamped to a sane 1–10000 window.
     */
    public function getBacklogAlertThreshold(?int $storeId = null): int
    {
        $value = (int) ($this->getValue(self::KEY_BACKLOG_ALERT_THRESHOLD, $storeId)
            ?: self::DEFAULT_BACKLOG_ALERT_THRESHOLD);

        return min(max($value, 1), 10000);
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
