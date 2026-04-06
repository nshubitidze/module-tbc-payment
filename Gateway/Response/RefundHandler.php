<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order\Payment;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;

/**
 * Handles the response from Flitt refund/reverse operations.
 */
class RefundHandler implements HandlerInterface
{
    public function __construct(
        private readonly SubjectReader $subjectReader,
    ) {
    }

    /**
     * @param array<string, mixed> $handlingSubject
     * @param array<string, mixed> $response
     * @throws FlittApiException
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = $this->subjectReader->readPayment($handlingSubject);

        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();

        $reverseStatus = $response['reverse_status'] ?? '';

        if ($reverseStatus !== 'approved' && $reverseStatus !== 'success') {
            $errorMessage = $response['error_message'] ?? (string) __('Refund was declined by the payment gateway');
            throw new FlittApiException(__($errorMessage));
        }

        $payment->setAdditionalInformation('refund_status', $reverseStatus);

        if (isset($response['reversal_amount'])) {
            $payment->setAdditionalInformation('reversal_amount', $response['reversal_amount']);
        }

        if (isset($response['transaction_id'])) {
            $payment->setTransactionId($response['transaction_id']);
            $payment->setAdditionalInformation('refund_transaction_id', $response['transaction_id']);
        }

        $payment->setIsTransactionClosed(true);
    }
}
