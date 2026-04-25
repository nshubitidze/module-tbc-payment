<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Request;

use Magento\Framework\Exception\LocalizedException;
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

        // Session 3 Priority 1.1.6 Change C — no silent fallback to increment_id.
        //
        // Rationale: redirect-flow orders placed before commit 4b8d444 persisted
        // no `flitt_order_id` on the payment. The previous fallback sent a bare
        // "{increment_id}" to Flitt's /api/reverse, but the real Flitt-side
        // order_id is `duka_{increment_id}_{timestamp}` — the request was
        // guaranteed to fail with code 1013 or 1002. Surface an actionable
        // LocalizedException instead so admin knows to issue an Offline refund
        // and reconcile manually.
        $flittOrderId = (string) $payment->getAdditionalInformation('flitt_order_id');
        if ($flittOrderId === '') {
            throw new LocalizedException(__(
                'This order is missing the Flitt reference and cannot be refunded online. '
                . 'Issue an Offline refund and reconcile with TBC Bank using the payment id from the invoice.'
            ));
        }

        // v1.0-safe payload: ONLY scalar values. Do not inline receiver arrays here.
        $params = [
            'order_id' => $flittOrderId,
            'merchant_id' => (string) $merchantId,
            'amount' => (string) $amount,
            'currency' => (string) $currency,
        ];

        $params['signature'] = Config::generateSignature($params, $password);
        $params['__store_id'] = $storeId;

        return $params;
    }
}
