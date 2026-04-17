<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Request;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;

/**
 * Builds the refund/reverse request for the Flitt /api/reverse/order_id endpoint.
 *
 * IMPORTANT: this endpoint uses Flitt's v1.0 signature (sha1 of password + pipe-joined
 * sorted non-empty values). Values MUST be scalars; nested arrays would be coerced to
 * the literal string "Array" and the signature would be rejected by the gateway.
 *
 * Split-payment receiver re-allocation is therefore NOT inlined into the reverse
 * payload. Adjusting receivers requires a separate /api/settlement call (v2.0
 * signature with base64-wrapped JSON via {@see \Shubo\TbcPayment\Service\SettlementService}).
 * The settled amount stored on the payment is already the refunded amount because
 * the reverse endpoint reduces it on Flitt's side.
 */
class RefundRequestBuilder implements BuilderInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SubjectReader $subjectReader,
    ) {
    }

    /**
     * @param array<string, mixed> $buildSubject
     * @return array<string, mixed>
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = $this->subjectReader->readPayment($buildSubject);
        $order = $paymentDO->getOrder();
        $payment = $paymentDO->getPayment();
        $storeId = (int) $order->getStoreId();

        $amount = (int) round($this->subjectReader->readAmount($buildSubject) * 100);
        $merchantId = $this->config->getMerchantId($storeId);
        $password = $this->config->getPassword($storeId);
        $currency = $order->getCurrencyCode();

        // Use the Flitt order_id stored during callback, fall back to increment_id.
        $flittOrderId = $payment->getAdditionalInformation('flitt_order_id')
            ?: $order->getOrderIncrementId();

        // v1.0-safe payload: ONLY scalar values. Do not inline receiver arrays here.
        $params = [
            'order_id' => (string) $flittOrderId,
            'merchant_id' => (string) $merchantId,
            'amount' => (string) $amount,
            'currency' => (string) $currency,
        ];

        $params['signature'] = Config::generateSignature($params, $password);
        $params['__store_id'] = $storeId;

        return $params;
    }
}
