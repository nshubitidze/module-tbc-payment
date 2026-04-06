<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order\Payment;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;

/**
 * Handles the response from Flitt checkout token creation.
 *
 * Stores the token in payment additional_information for frontend use.
 */
class InitializeHandler implements HandlerInterface
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

        $responseData = $response['response'] ?? $response;

        $responseStatus = $responseData['response_status'] ?? '';
        if ($responseStatus !== 'success') {
            $errorMessage = $responseData['error_message'] ?? (string) __('Failed to create payment session');
            throw new FlittApiException(__($errorMessage));
        }

        $token = $responseData['token'] ?? '';
        if (empty($token)) {
            throw new FlittApiException(__('Payment token not received from gateway'));
        }

        $payment->setAdditionalInformation('flitt_token', $token);
        $payment->setAdditionalInformation('flitt_response_status', $responseStatus);
        $payment->setIsTransactionPending(true);
    }
}
