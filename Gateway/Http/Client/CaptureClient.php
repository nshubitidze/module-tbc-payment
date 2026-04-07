<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Http\Client;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;

/**
 * HTTP client for capturing pre-authorized payments via Flitt API.
 */
class CaptureClient
{
    private const ENDPOINT = '/api/capture/order_id';

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Capture a pre-authorized payment.
     *
     * @param array<string, mixed> $params Capture parameters (order_id, merchant_id, amount, currency, signature)
     * @param int $storeId Store ID
     * @return array<string, mixed> Flitt response
     * @throws FlittApiException
     */
    public function capture(array $params, int $storeId): array
    {
        $url = $this->config->getApiUrl($storeId) . self::ENDPOINT;

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('Flitt Capture request', [
                'url' => $url,
                'params' => array_diff_key($params, ['signature' => true]),
            ]);
        }

        try {
            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->setOptions([CURLOPT_TIMEOUT => 30]);
            $curl->post($url, (string) $this->json->serialize(['request' => $params]));

            $responseBody = $curl->getBody();
            $statusCode = $curl->getStatus();

            if ($this->config->isDebugEnabled($storeId)) {
                $this->logger->debug('Flitt Capture response', [
                    'status' => $statusCode,
                    'body' => $responseBody,
                ]);
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new FlittApiException(__('Flitt capture API returned HTTP %1', $statusCode));
            }

            $response = $this->json->unserialize($responseBody);
            if (!is_array($response)) {
                throw new FlittApiException(__('Invalid capture response from Flitt API'));
            }

            return $response;
        } catch (FlittApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Flitt Capture error: ' . $e->getMessage(), ['exception' => $e]);
            throw new FlittApiException(__('Unable to capture payment via TBC gateway.'), $e);
        }
    }
}
