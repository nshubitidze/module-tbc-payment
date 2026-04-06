<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Http\Client;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;

/**
 * HTTP client for creating Flitt checkout tokens.
 */
class CreatePaymentClient implements ClientInterface
{
    private const ENDPOINT = '/api/checkout/token';

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
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
        $storeId = $requestBody['__store_id'] ?? null;
        unset($requestBody['__store_id']);

        $url = $this->config->getApiUrl($storeId) . self::ENDPOINT;

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('Flitt CreatePayment request', [
                'url' => $url,
                'params' => $this->sanitizeForLog($requestBody),
            ]);
        }

        try {
            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->setOption(CURLOPT_TIMEOUT, 30);
            $curl->post($url, $this->json->serialize($requestBody));

            $responseBody = $curl->getBody();
            $statusCode = $curl->getStatus();

            if ($this->config->isDebugEnabled($storeId)) {
                $this->logger->debug('Flitt CreatePayment response', [
                    'status' => $statusCode,
                    'body' => $responseBody,
                ]);
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new FlittApiException(
                    __('Flitt API returned HTTP %1', $statusCode)
                );
            }

            $response = $this->json->unserialize($responseBody);

            if (!is_array($response)) {
                throw new FlittApiException(__('Invalid response from Flitt API'));
            }

            return $response;
        } catch (FlittApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Flitt CreatePayment error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new FlittApiException(
                __('Unable to communicate with TBC payment gateway. Please try again.'),
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
        unset($sanitized['password'], $sanitized['signature']);
        if (isset($sanitized['merchant_id'])) {
            $sanitized['merchant_id'] = substr((string) $sanitized['merchant_id'], 0, 4) . '****';
        }

        return $sanitized;
    }
}
