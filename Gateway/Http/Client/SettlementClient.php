<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Http\Client;

use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;

/**
 * HTTP client for Flitt settlement (split payment distribution) operations.
 *
 * Settlement uses a different request format than other Flitt APIs: the order
 * data is base64-encoded and the signature is the v2 sha1(password|base64_data).
 * This client owns that base64 + v2-signature shaping and serializes the finished
 * {"request":{version,data,signature}} envelope itself, then hands the raw body
 * string to {@see FlittHttpClient} for transport.
 *
 * Settlement is non-idempotent (a retried settle double-distributes funds), so it
 * never passes retryable = true.
 */
class SettlementClient
{
    private const ENDPOINT = '/api/settlement';

    public function __construct(
        private readonly Config $config,
        private readonly FlittHttpClient $httpClient,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Send settlement request to distribute funds to receivers.
     *
     * @param array<string, mixed> $orderData The "order" object data (will be base64-encoded)
     * @param int $storeId Store ID for config lookup
     * @return array<string, mixed> Flitt response
     * @throws FlittApiException
     */
    public function settle(array $orderData, int $storeId): array
    {
        $password = $this->config->getPassword($storeId);

        $dataJson = (string) $this->json->serialize(['order' => $orderData]);
        $dataBase64 = base64_encode($dataJson);
        /** @phpstan-ignore argument.type */
        $signature = \Cloudipsp\Helper\ApiHelper::generateSignature($dataBase64, $password, '2.0');

        $requestBody = (string) $this->json->serialize([
            'request' => [
                'version' => '2.0',
                'data' => $dataBase64,
                'signature' => $signature,
            ],
        ]);

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('Flitt Settlement request', [
                'endpoint' => self::ENDPOINT,
                'order_data' => $this->sanitizeForLog($orderData),
            ]);
        }

        // Hand the pre-serialized v2 envelope to the shared transport as a raw body
        // so FlittHttpClient never learns about settlement signing.
        return $this->httpClient->post(self::ENDPOINT, $requestBody, $storeId);
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
        if (isset($sanitized['merchant_id'])) {
            $sanitized['merchant_id'] = substr((string) $sanitized['merchant_id'], 0, 4) . '****';
        }
        unset($sanitized['receiver']);

        return $sanitized;
    }
}
