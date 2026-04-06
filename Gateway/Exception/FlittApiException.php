<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Exception thrown when Flitt API returns an error or is unreachable.
 */
class FlittApiException extends LocalizedException
{
}
