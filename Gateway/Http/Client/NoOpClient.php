<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Http\Client;

use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;

/**
 * No-op HTTP client for gateway commands that don't call external APIs.
 *
 * Used by the authorize command since the actual Flitt API interaction
 * happens via the Params controller (token generation) and the callback.
 */
class NoOpClient implements ClientInterface
{
    /**
     * @return array<string, mixed>
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        return ['success' => true];
    }
}
