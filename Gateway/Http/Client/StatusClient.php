<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Http\Client;

use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;

/**
 * Standalone HTTP client for checking payment status via Flitt API.
 *
 * Not part of the gateway command pool — used directly by the cron reconciler.
 * Status checks are idempotent reads, so this is the ONLY client that opts in to
 * the bounded retry in {@see FlittHttpClient::post()}.
 */
class StatusClient
{
    private const ENDPOINT = '/api/status/order_id';

    public function __construct(
        private readonly Config $config,
        private readonly FlittHttpClient $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Check payment status for a given Flitt order ID.
     *
     * The Flitt status payload is nested under a top-level `response` key. This
     * method unwraps that envelope and returns the inner `response` array (or the
     * whole decoded body if it is absent or non-array, e.g. a malformed reply) so
     * every caller receives the status fields (order_status, amount, payment_id,
     * response_status, error_code, …) directly without repeating the unwrap.
     *
     * @param string $orderId The Flitt order_id (e.g. "duka_000000042_1743998765")
     * @param int $storeId Store ID for config lookup
     * @return array<string, mixed> Unwrapped Flitt status payload (order_status, amount, payment_id, etc.)
     * @throws FlittApiException
     */
    public function checkStatus(string $orderId, int $storeId): array
    {
        $merchantId = $this->config->getMerchantId($storeId);
        $password = $this->config->getPassword($storeId);

        $params = [
            'order_id' => $orderId,
            'merchant_id' => $merchantId,
        ];

        $params['signature'] = Config::generateSignature($params, $password);

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('Flitt Status request', [
                'endpoint' => self::ENDPOINT,
                'params' => $this->sanitizeForLog($params),
            ]);
        }

        // Status reads are idempotent — safe to retry on transport failure.
        $body = $this->httpClient->post(self::ENDPOINT, ['request' => $params], $storeId, retryable: true);

        // Flitt nests the status fields under `response`. Unwrap here so callers
        // do not each repeat `$body['response'] ?? $body`. When the `response`
        // envelope is absent (malformed reply) we fall back to the whole body.
        // NOTE: this is equivalent-or-better, not byte-identical to the old
        // per-caller behaviour. For the pathological case where `response` is a
        // SCALAR string, the old reconciler treated the unwrap as unusable and
        // bailed; here we fall through and return the outer body array, giving
        // the caller the rest of the fields to work with rather than nothing.
        $response = $body['response'] ?? $body;

        return is_array($response) ? $response : $body;
    }

    /**
     * Remove sensitive data before logging.
     *
     * @param array<string, mixed> $data Data to sanitize
     * @return array<string, mixed>
     */
    private function sanitizeForLog(array $data): array
    {
        $sanitized = $data;
        unset($sanitized['signature']);
        if (isset($sanitized['merchant_id'])) {
            $sanitized['merchant_id'] = substr((string) $sanitized['merchant_id'], 0, 4) . '****';
        }

        return $sanitized;
    }
}
