<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Helper;

use InvalidArgumentException;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;

/**
 * Utility for reading values from the payment gateway subject array.
 */
class SubjectReader
{
    /**
     * Read the payment data object from the subject.
     *
     * @param array<string, mixed> $subject
     * @throws InvalidArgumentException
     */
    public function readPayment(array $subject): PaymentDataObjectInterface
    {
        if (!isset($subject['payment']) || !$subject['payment'] instanceof PaymentDataObjectInterface) {
            throw new InvalidArgumentException('Payment data object should be provided');
        }

        return $subject['payment'];
    }

    /**
     * Read the amount from the subject.
     *
     * @param array<string, mixed> $subject
     * @throws InvalidArgumentException
     */
    public function readAmount(array $subject): float
    {
        if (!isset($subject['amount']) || !is_numeric($subject['amount'])) {
            throw new InvalidArgumentException('Amount should be provided');
        }

        return (float) $subject['amount'];
    }

    /**
     * Read the response from the subject.
     *
     * @param array<string, mixed> $subject
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    public function readResponse(array $subject): array
    {
        if (!isset($subject['response']) || !is_array($subject['response'])) {
            throw new InvalidArgumentException('Response does not exist');
        }

        return $subject['response'];
    }

    /**
     * Read the store ID from the subject.
     *
     * @param array<string, mixed> $subject
     */
    public function readStoreId(array $subject): ?int
    {
        return isset($subject['store_id']) ? (int) $subject['store_id'] : null;
    }
}
