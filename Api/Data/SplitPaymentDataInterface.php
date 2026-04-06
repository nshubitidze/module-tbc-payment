<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Api\Data;

/**
 * Data interface for split payment information.
 *
 * Used to pass merchant-specific amounts when splitting payments
 * across multiple receivers via Flitt.
 */
interface SplitPaymentDataInterface
{
    public function getMerchantId(): string;

    public function setMerchantId(string $merchantId): self;

    public function getAmount(): int;

    public function setAmount(int $amount): self;

    public function getCurrency(): string;

    public function setCurrency(string $currency): self;

    public function getDescription(): string;

    public function setDescription(string $description): self;
}
