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
 * HTTP client for Flitt refund/reverse operations.
 */
class RefundClient implements ClientInterface
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
     * @param TransferInterface $transferObject
     * @return array<string, mixed>
     * @throws FlittApiException
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        $requestBody = $transferObject->getBody();
        $storeId = $requestBody['__store_id'] ?? null;
        unset($requestBody['__store_id']);

        // Flitt reverse endpoint uses the order_id in the URL path
        $url = $this->config->getApiUrl($storeId) . self::ENDPOINT;

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('Flitt Refund request', [
                'url' => $url,
                'params' => $this->sanitizeForLog($requestBody),
            ]);
        }

        try {
            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->setOptions([CURLOPT_TIMEOUT => 30]);
            // Flitt expects the body wrapped in {"request": {...}}
            $curl->post($url, (string) $this->json->serialize(['request' => $requestBody]));

            $responseBody = $curl->getBody();
            $statusCode = $curl->getStatus();

            if ($this->config->isDebugEnabled($storeId)) {
                $this->logger->debug('Flitt Refund response', [
                    'status' => $statusCode,
                    'body' => $responseBody,
                ]);
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new FlittApiException(
                    __('Flitt refund API returned HTTP %1', $statusCode)
                );
            }

            $response = $this->json->unserialize($responseBody);

            if (!is_array($response)) {
                throw new FlittApiException(__('Invalid refund response from Flitt API'));
            }

            return $response;
        } catch (FlittApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Flitt Refund error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new FlittApiException(
                __('Unable to process refund via TBC payment gateway. Please try again.'),
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
        unset($sanitized['signature']);
        if (isset($sanitized['merchant_id'])) {
            $sanitized['merchant_id'] = substr((string) $sanitized['merchant_id'], 0, 4) . '****';
        }

        return $sanitized;
    }
}
