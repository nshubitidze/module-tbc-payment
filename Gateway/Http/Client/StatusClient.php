<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Http\Client;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;

/**
 * Standalone HTTP client for checking payment status via Flitt API.
 *
 * Not part of the gateway command pool — used directly by the cron reconciler.
 */
class StatusClient
{
    private const ENDPOINT = '/api/status/order_id';

    /**
     * @param Config $config
     * @param CurlFactory $curlFactory
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Check payment status for a given Flitt order ID.
     *
     * @param string $orderId The Flitt order_id (e.g. "duka_000000042_1743998765")
     * @param int $storeId Store ID for config lookup
     * @return array<string, mixed> Flitt response containing order_status, amount, payment_id, etc.
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

        $requestBody = ['request' => $params];
        $url = $this->config->getApiUrl($storeId) . self::ENDPOINT;

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('Flitt Status request', [
                'url' => $url,
                'params' => $this->sanitizeForLog($params),
            ]);
        }

        try {
            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->setOptions([CURLOPT_TIMEOUT => 30]);
            $curl->post($url, (string) $this->json->serialize($requestBody));

            $responseBody = $curl->getBody();
            $statusCode = $curl->getStatus();

            if ($this->config->isDebugEnabled($storeId)) {
                $this->logger->debug('Flitt Status response', [
                    'status' => $statusCode,
                    'body' => $responseBody,
                ]);
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new FlittApiException(
                    __('Flitt status API returned HTTP %1', $statusCode)
                );
            }

            $response = $this->json->unserialize($responseBody);

            if (!is_array($response)) {
                throw new FlittApiException(__('Invalid status response from Flitt API'));
            }

            return $response;
        } catch (FlittApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Flitt Status error: ' . $e->getMessage(), [
                'exception' => $e,
                'order_id' => $orderId,
            ]);
            throw new FlittApiException(
                __('Unable to check payment status via TBC payment gateway.'),
                $e
            );
        }
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
