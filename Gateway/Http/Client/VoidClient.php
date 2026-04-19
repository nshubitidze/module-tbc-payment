<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Http\Client;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;

/**
 * HTTP client for reversing (voiding) pre-authorized payments via Flitt API.
 *
 * Flitt exposes a single endpoint for both refund and authorization reversal:
 * /api/reverse/order_id. This client is the admin-driven counterpart to
 * RefundClient — it is invoked directly (no gateway pipeline) when an admin
 * presses the "Void Payment" button to release a pre-auth hold BEFORE the
 * Magento order is cancelled locally.
 */
class VoidClient
{
    private const ENDPOINT = '/api/reverse/order_id';

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Reverse a pre-authorized payment.
     *
     * @param array<string, mixed> $params Reverse parameters (order_id, merchant_id, amount, currency, signature)
     * @param int $storeId Store ID
     * @return array<string, mixed> Flitt response
     * @throws FlittApiException
     */
    public function reverse(array $params, int $storeId): array
    {
        $url = $this->config->getApiUrl($storeId) . self::ENDPOINT;

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('Flitt Void request', [
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
                $this->logger->debug('Flitt Void response', [
                    'status' => $statusCode,
                    'body' => $responseBody,
                ]);
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new FlittApiException(__('Flitt void API returned HTTP %1', $statusCode));
            }

            $response = $this->json->unserialize($responseBody);
            if (!is_array($response)) {
                throw new FlittApiException(__('Invalid void response from Flitt API'));
            }

            return $response;
        } catch (FlittApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Flitt Void error: ' . $e->getMessage(), ['exception' => $e]);
            throw new FlittApiException(__('Unable to void payment via TBC gateway.'), $e);
        }
    }
}
