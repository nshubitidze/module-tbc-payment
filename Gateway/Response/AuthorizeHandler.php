<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order\Payment;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;

/**
 * Handles the authorize action during order placement.
 *
 * Sets the transaction as pending since actual authorization happens
 * externally via Flitt embed + 3DS. The callback will later confirm
 * the authorization and capture the payment.
 */
class AuthorizeHandler implements HandlerInterface
{
    public function __construct(
        private readonly SubjectReader $subjectReader,
    ) {
    }

    /**
     * @param array<string, mixed> $handlingSubject
     * @param array<string, mixed> $response
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = $this->subjectReader->readPayment($handlingSubject);

        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();

        $orderId = $paymentDO->getOrder()->getOrderIncrementId();

        // Set a transaction ID so Magento creates the auth transaction record.
        // The real Flitt payment_id will be set later by the callback.
        $payment->setTransactionId($orderId . '-auth');
        $payment->setIsTransactionPending(true);
        $payment->setIsTransactionClosed(false);
        $payment->setAdditionalInformation('awaiting_flitt_confirmation', true);
    }
}
