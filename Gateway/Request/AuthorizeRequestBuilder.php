<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Request;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;

/**
 * Builds the authorize request.
 *
 * No API call is made during authorization — the Flitt checkout token
 * was already obtained via the Params controller. This builder provides
 * the minimal data needed for the gateway command framework.
 */
class AuthorizeRequestBuilder implements BuilderInterface
{
    public function __construct(
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

        return [
            'order_id' => $order->getOrderIncrementId(),
            'amount' => $this->subjectReader->readAmount($buildSubject),
            'currency' => $order->getCurrencyCode(),
            '__store_id' => (int) $order->getStoreId(),
        ];
    }
}
