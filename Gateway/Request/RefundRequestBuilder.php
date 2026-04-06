<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Request;

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
        $storeId = (int) $order->getStoreId();

        $amount = (int) round($this->subjectReader->readAmount($buildSubject) * 100);
        $orderId = $order->getOrderIncrementId();
        $merchantId = $this->config->getMerchantId($storeId);
        $password = $this->config->getPassword($storeId);
        $currency = $this->config->getCurrency($storeId);

        $params = [
            'order_id' => $orderId,
            'merchant_id' => $merchantId,
            'amount' => (string) $amount,
            'currency' => $currency,
        ];

        $params['signature'] = Config::generateSignature($params, $password);
        $params['__store_id'] = $storeId;

        return $params;
    }
}
