<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Response;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;

/**
 * Handles the response from Flitt refund/reverse operations.
 *
 * Error semantics (Session 3 Priority 1.1.6 Change A):
 *   - On Flitt failure, log the raw triple (error_code, error_message,
 *     request_id) at ERROR via the TBC logger BEFORE mapping. Ops + support
 *     need the raw values to correlate the admin-facing message back to the
 *     Flitt side.
 *   - Throw the LocalizedException returned by
 *     {@see UserFacingErrorMapper}. Magento's creditmemo controller catches
 *     LocalizedException and surfaces a friendly admin toast; the upstream
 *     `Creditmemo::register` rollback semantics are unchanged.
 *
 * See `docs/online-refund-rca.md` for the historical Flitt sandbox trace
 * that prompted this change.
 */
class RefundHandler implements HandlerInterface
{
    public function __construct(
        private readonly SubjectReader $subjectReader,
        private readonly UserFacingErrorMapper $userFacingErrorMapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $handlingSubject
     * @param array<string, mixed> $response
     * @throws LocalizedException
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = $this->subjectReader->readPayment($handlingSubject);

        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();

        // Flitt wraps the response in {"response": {...}}
        $responseData = $response['response'] ?? $response;

        $reverseStatus = (string) ($responseData['reverse_status'] ?? '');
        $responseStatus = (string) ($responseData['response_status'] ?? '');

        if ($reverseStatus !== 'approved' && $responseStatus !== 'success') {
            $rawErrorCode = $responseData['error_code'] ?? 0;
            $rawErrorMessage = (string) ($responseData['error_message'] ?? '');
            $requestId = isset($responseData['request_id'])
                ? (string) $responseData['request_id']
                : null;

            $order = $paymentDO->getOrder();
            $orderIncrementId = $order !== null ? (string) $order->getOrderIncrementId() : null;

            // Raw triple BEFORE mapping — contract documented in
            // docs/error-code-map.md §3. Mapper itself does no logging.
            $this->logger->error('TBC Flitt error mapped to user copy', [
                'context'            => 'refund.handler',
                'error_code'         => $rawErrorCode,
                'error_message'      => $rawErrorMessage,
                'request_id'         => $requestId,
                'reverse_status'     => $reverseStatus,
                'response_status'    => $responseStatus,
                'order_increment_id' => $orderIncrementId,
            ]);

            throw $this->userFacingErrorMapper->toLocalizedException(
                $rawErrorCode,
                $rawErrorMessage,
                $requestId,
            );
        }

        $payment->setAdditionalInformation('refund_status', $reverseStatus ?: $responseStatus);

        if (isset($responseData['reversal_amount'])) {
            $payment->setAdditionalInformation('reversal_amount', $responseData['reversal_amount']);
        }

        if (isset($responseData['transaction_id'])) {
            $payment->setTransactionId((string) $responseData['transaction_id']);
            $payment->setAdditionalInformation(
                'refund_transaction_id',
                $responseData['transaction_id']
            );
        }

        $payment->setIsTransactionClosed(true);
    }
}
