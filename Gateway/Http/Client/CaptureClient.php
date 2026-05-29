<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Http\Client;

use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;

/**
 * HTTP client for capturing pre-authorized payments via Flitt API.
 *
 * SIMPLIFY-5: this client OWNS the wire-payload build and the Flitt signature,
 * mirroring how {@see StatusClient} signs its own request. Callers hand over
 * order-level inputs (flitt_order_id, amount in minor units, currency, store)
 * and let the client shape + sign + send; transport is delegated to
 * {@see FlittHttpClient}. The admin Capture controller therefore no longer
 * builds {request: {...signature}} envelopes or touches Config::generateSignature.
 *
 * Capture is non-idempotent (a retried capture double-charges), so it never
 * passes retryable = true to {@see FlittHttpClient::post()}.
 */
class CaptureClient
{
    private const ENDPOINT = '/api/capture/order_id';

    public function __construct(
        private readonly Config $config,
        private readonly FlittHttpClient $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Capture a pre-authorized payment.
     *
     * Builds and signs the Flitt request from order-level inputs, then posts it.
     *
     * @param string $flittOrderId The Flitt order_id (e.g. "duka_000000042_1743998765")
     * @param int $amountMinor Capture amount in minor units (tetri)
     * @param string $currency ISO currency code (e.g. "GEL")
     * @param int $storeId Store ID for config lookup
     * @return array<string, mixed> Flitt response
     * @throws FlittApiException
     */
    public function capture(string $flittOrderId, int $amountMinor, string $currency, int $storeId): array
    {
        $params = [
            'order_id' => $flittOrderId,
            'merchant_id' => $this->config->getMerchantId($storeId),
            'amount' => (string) $amountMinor,
            'currency' => $currency,
        ];
        $params['signature'] = Config::generateSignature($params, $this->config->getPassword($storeId));

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('Flitt Capture request', [
                'endpoint' => self::ENDPOINT,
                'params' => array_diff_key($params, ['signature' => true]),
            ]);
        }

        return $this->httpClient->post(self::ENDPOINT, ['request' => $params], $storeId);
    }
}
