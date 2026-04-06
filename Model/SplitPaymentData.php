<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Model;

use Shubo\TbcPayment\Api\Data\SplitPaymentDataInterface;

/**
 * Implementation of split payment data.
 */
class SplitPaymentData implements SplitPaymentDataInterface
{
    private string $merchantId = '';
    private int $amount = 0;
    private string $currency = 'GEL';
    private string $description = '';

    public function getMerchantId(): string
    {
        return $this->merchantId;
    }

    public function setMerchantId(string $merchantId): self
    {
        $this->merchantId = $merchantId;
        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }
}
