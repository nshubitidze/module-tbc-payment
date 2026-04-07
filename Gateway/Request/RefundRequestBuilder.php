<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Request;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;

/**
 * Builds the refund/reverse request for Flitt API.
 */
class RefundRequestBuilder implements BuilderInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SubjectReader $subjectReader,
        private readonly Json $json,
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

        // Use the Flitt order_id stored during callback, fall back to increment_id
        $flittOrderId = $payment->getAdditionalInformation('flitt_order_id')
            ?: $order->getOrderIncrementId();

        $params = [
            'order_id' => $flittOrderId,
            'merchant_id' => $merchantId,
            'amount' => (string) $amount,
            'currency' => $currency,
        ];

        // Include receiver data for split payment refunds
        $settlementReceivers = $payment->getAdditionalInformation('settlement_receivers');
        if (!empty($settlementReceivers)) {
            try {
                $receivers = $this->json->unserialize($settlementReceivers);
                if (is_array($receivers) && !empty($receivers)) {
                    $params['receiver'] = $receivers;
                }
            } catch (\Exception) {
                // Skip receiver data if deserialization fails
            }
        }

        $params['signature'] = Config::generateSignature($params, $password);
        $params['__store_id'] = $storeId;

        return $params;
    }
}
