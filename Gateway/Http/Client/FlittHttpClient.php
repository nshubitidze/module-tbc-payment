<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Http\Client;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;

/**
 * Shared HTTP transport for all Flitt API calls.
 *
 * Owns the repeated curl-create -> headers -> timeouts -> POST -> HTTP-status
 * check -> throw FlittApiException on non-2xx -> JSON-decode sequence that was
 * previously copy-pasted across the gateway HTTP clients and the two checkout
 * controllers (SIMPLIFY-2). Timeouts are config-driven and a connect-timeout is
 * always applied (IMPROVE-12).
 *
 * The client is deliberately Flitt-signing-agnostic: callers build and sign
 * their own request bodies and hand them here. Two body modes are supported:
 *   - an array body, which is JSON-encoded verbatim (callers wrap their own
 *     {"request": ...} envelope), and
 *   - a pre-serialized raw string body (e.g. the settlement v2 base64 envelope),
 *     which is sent as-is.
 *
 * Retries are opt-in and STATUS-ONLY: a retried capture/void/settle/refund/token
 * would double-charge or double-reverse, so only idempotent status reads may pass
 * $retryable = true.
 */
class FlittHttpClient
{
    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * POST a request to a Flitt endpoint and return the decoded JSON response.
     *
     * @param string $endpoint Path appended to the configured API URL (e.g. "/api/capture/order_id")
     * @param array<string, mixed>|string $body Array body (JSON-encoded here) or a pre-serialized raw body string
     * @param int $storeId Store ID for config lookup
     * @param bool $retryable Whether the call is idempotent and may be retried on transport failure.
     *                        MUST be false for non-idempotent calls (capture/void/settle/refund/token).
     * @return array<string, mixed> Decoded Flitt response
     * @throws FlittApiException On non-2xx HTTP status, a non-array body, or transport failure.
     */
    public function post(string $endpoint, array|string $body, int $storeId, bool $retryable = false): array
    {
        $url = $this->config->getApiUrl($storeId) . $endpoint;
        $payload = is_array($body) ? (string) $this->json->serialize($body) : $body;

        $maxAttempts = $retryable ? $this->config->getHttpStatusRetries($storeId) + 1 : 1;
        $lastTransportError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $curl = $this->curlFactory->create();
                $curl->addHeader('Content-Type', 'application/json');
                $this->applyTimeouts($curl, $storeId);
                $curl->post($url, $payload);

                $responseBody = $curl->getBody();
                $statusCode = $curl->getStatus();

                if ($this->config->isDebugEnabled($storeId)) {
                    $this->logger->debug('Flitt HTTP response', [
                        'url' => $url,
                        'status' => $statusCode,
                        'body' => $responseBody,
                    ]);
                }

                if ($statusCode < 200 || $statusCode >= 300) {
                    throw new FlittApiException(__('Flitt API returned HTTP %1', $statusCode));
                }

                // SerializerInterface::unserialize() throws \InvalidArgumentException
                // on malformed JSON; that is a deterministic body-shape failure, so it
                // is re-wrapped below and NOT retried.
                $response = $this->json->unserialize($responseBody);

                if (!is_array($response)) {
                    throw new FlittApiException(__('Invalid response from Flitt API'));
                }

                /** @var array<string, mixed> $response */
                return $response;
            } catch (FlittApiException $e) {
                // HTTP-status / body-shape failures are deterministic — do not retry.
                throw $e;
            } catch (\InvalidArgumentException $e) {
                // Malformed response JSON — deterministic, do not retry.
                throw new FlittApiException(__('Invalid response from Flitt API'), $e);
            } catch (\Exception $e) {
                $lastTransportError = $e;

                if ($attempt < $maxAttempts) {
                    $this->logger->warning('Flitt HTTP transport error; retrying idempotent call', [
                        'url' => $url,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
        }

        $this->logger->error('Flitt HTTP transport error: ' . ($lastTransportError?->getMessage() ?? 'unknown'), [
            'url' => $url,
            'exception' => $lastTransportError,
        ]);

        throw new FlittApiException(
            __('Unable to reach the TBC payment gateway. Please try again.'),
            $lastTransportError,
        );
    }

    /**
     * Apply config-driven connect + read timeouts to the curl handle.
     *
     * CURLOPT_CONNECTTIMEOUT is set on every path so a black-holed Flitt host
     * cannot pin a PHP worker for the full read timeout (IMPROVE-12).
     */
    private function applyTimeouts(Curl $curl, int $storeId): void
    {
        $curl->setOptions([
            CURLOPT_CONNECTTIMEOUT => $this->config->getHttpConnectTimeout($storeId),
            CURLOPT_TIMEOUT => $this->config->getHttpReadTimeout($storeId),
        ]);
    }
}
