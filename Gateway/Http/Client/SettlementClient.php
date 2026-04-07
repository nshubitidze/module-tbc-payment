<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Http\Client;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;

/**
 * HTTP client for Flitt settlement (split payment distribution) operations.
 *
 * Settlement uses a different request format than other Flitt APIs:
 * the order data is base64-encoded and the signature is sha1(password|base64_data).
 */
class SettlementClient
{
    private const ENDPOINT = '/api/settlement';

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
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
        $url = $this->config->getApiUrl($storeId) . self::ENDPOINT;

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
                'url' => $url,
                'order_data' => $this->sanitizeForLog($orderData),
            ]);
        }

        try {
            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->setOptions([CURLOPT_TIMEOUT => 30]);
            $curl->post($url, $requestBody);

            $responseBody = $curl->getBody();
            $statusCode = $curl->getStatus();

            if ($this->config->isDebugEnabled($storeId)) {
                $this->logger->debug('Flitt Settlement response', [
                    'status' => $statusCode,
                    'body' => $responseBody,
                ]);
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new FlittApiException(
                    __('Flitt settlement API returned HTTP %1', $statusCode)
                );
            }

            $response = $this->json->unserialize($responseBody);

            if (!is_array($response)) {
                throw new FlittApiException(__('Invalid settlement response from Flitt API'));
            }

            return $response;
        } catch (FlittApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Flitt Settlement error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new FlittApiException(
                __('Unable to process settlement via TBC payment gateway.'),
                $e
            );
        }
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
