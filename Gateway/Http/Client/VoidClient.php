<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Http\Client;

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
 *
 * SIMPLIFY-5: this client OWNS the wire-payload build and the Flitt signature,
 * mirroring how {@see StatusClient} signs its own request. Callers hand over
 * order-level inputs (flitt_order_id, authorized amount in minor units,
 * currency, store) and let the client shape + sign + send; transport is
 * delegated to {@see FlittHttpClient}. The admin VoidPayment controller
 * therefore no longer builds {request: {...signature}} envelopes or touches
 * Config::generateSignature.
 *
 * Reversal is non-idempotent (a retried reverse double-reverses), so it never
 * passes retryable = true to {@see FlittHttpClient::post()}.
 */
class VoidClient
{
    private const ENDPOINT = '/api/reverse/order_id';

    public function __construct(
        private readonly Config $config,
        private readonly FlittHttpClient $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Reverse a pre-authorized payment.
     *
     * Builds and signs the Flitt request from order-level inputs, then posts it.
     * The amount MUST be the authorized (held) pre-auth amount, never a fresh
     * grand-total derivation — a reverse releases exactly what was held.
     *
     * @param string $flittOrderId The Flitt order_id (e.g. "duka_000000042_1743998765")
     * @param int $amountMinor Authorized amount to reverse, in minor units (tetri)
     * @param string $currency ISO currency code (e.g. "GEL")
     * @param int $storeId Store ID for config lookup
     * @return array<string, mixed> Flitt response
     * @throws FlittApiException
     */
    public function reverse(string $flittOrderId, int $amountMinor, string $currency, int $storeId): array
    {
        $params = [
            'order_id' => $flittOrderId,
            'merchant_id' => $this->config->getMerchantId($storeId),
            'amount' => (string) $amountMinor,
            'currency' => $currency,
        ];
        $params['signature'] = Config::generateSignature($params, $this->config->getPassword($storeId));

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('Flitt Void request', [
                'endpoint' => self::ENDPOINT,
                'params' => array_diff_key($params, ['signature' => true]),
            ]);
        }

        return $this->httpClient->post(self::ENDPOINT, ['request' => $params], $storeId);
    }
}
