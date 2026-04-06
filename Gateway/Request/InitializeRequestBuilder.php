<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Request;

use Magento\Framework\UrlInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;

/**
 * Builds the checkout token request for Flitt API.
 */
class InitializeRequestBuilder implements BuilderInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SubjectReader $subjectReader,
        private readonly UrlInterface $urlBuilder,
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
        $currency = $order->getCurrencyCode();

        $params = [
            'order_id' => $orderId,
            'merchant_id' => $merchantId,
            'order_desc' => (string) __('Order #%1', $orderId),
            'amount' => (string) $amount,
            'currency' => $currency,
            'server_callback_url' => $this->urlBuilder->getUrl(
                'shubo_tbc/payment/callback',
                ['_nosid' => true]
            ),
            'response_url' => $this->urlBuilder->getUrl(
                'checkout/onepage/success',
                ['_nosid' => true]
            ),
        ];

        $params['signature'] = Config::generateSignature($params, $password);
        $params['__store_id'] = $storeId;

        return $params;
    }
}
