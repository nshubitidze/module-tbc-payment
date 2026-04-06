<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;

/**
 * Validates Flitt API responses.
 */
class ResponseValidator extends AbstractValidator
{
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        private readonly SubjectReader $subjectReader,
    ) {
        parent::__construct($resultFactory);
    }

    /**
     * @param array<string, mixed> $validationSubject
     */
    public function validate(array $validationSubject): ResultInterface
    {
        $response = $this->subjectReader->readResponse($validationSubject);
        $responseData = $response['response'] ?? $response;

        $isValid = true;
        $errorMessages = [];
        $errorCodes = [];

        $responseStatus = $responseData['response_status'] ?? '';

        if ($responseStatus !== 'success') {
            $isValid = false;
            $errorMessages[] = $responseData['error_message']
                ?? (string) __('Payment gateway returned an error');
            $errorCodes[] = $responseData['error_code'] ?? 'UNKNOWN';
        }

        return $this->createResult($isValid, $errorMessages, $errorCodes);
    }
}
