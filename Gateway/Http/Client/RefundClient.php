<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Http\Client;

use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;

/**
 * HTTP client for Flitt refund/reverse operations.
 *
 * Stays on the Magento gateway pipeline (implements ClientInterface) and keeps
 * unpacking the store id + body from the TransferObject and wrapping the
 * {"request": ...} envelope itself; only the curl transport is delegated to
 * {@see FlittHttpClient}.
 *
 * Refund is non-idempotent (a retried refund double-refunds), so it never passes
 * retryable = true.
 */
class RefundClient implements ClientInterface
{
    private const ENDPOINT = '/api/reverse/order_id';

    public function __construct(
        private readonly Config $config,
        private readonly FlittHttpClient $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param TransferInterface $transferObject
     * @return array<string, mixed>
     * @throws FlittApiException
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        $requestBody = $transferObject->getBody();
        $storeId = (int) ($requestBody['__store_id'] ?? 0);
        unset($requestBody['__store_id']);

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('Flitt Refund request', [
                'endpoint' => self::ENDPOINT,
                'params' => $this->sanitizeForLog($requestBody),
            ]);
        }

        // Flitt expects the body wrapped in {"request": {...}}.
        return $this->httpClient->post(self::ENDPOINT, ['request' => $requestBody], $storeId);
    }

    /**
     * Remove sensitive data before logging.
     *
     * @param array<string, mixed> $data
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
