<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Gateway\Response;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;
use Shubo\TbcPayment\Gateway\Response\RefundHandler;

/**
 * Session 3 Priority 1.1.7 — RefundHandler regression coverage.
 *
 * Architect scope §1.1.7 enumerates six scenarios:
 *   1. Happy path — approved + success -> handler persists, no throw.
 *   2. Declined with error_code=1002 -> mapped friendly LocalizedException.
 *   3. Declined with error_code=1013 -> different mapped message (distinct row).
 *   4. Missing error_code (only error_message) -> generic fallback copy.
 *   5. Unknown error_code (9999) -> generic fallback copy.
 *   6. transaction_id is persisted on `refund_transaction_id` additional info.
 */
class RefundHandlerTest extends TestCase
{
    private SubjectReader&MockObject $subjectReader;
    private UserFacingErrorMapper&MockObject $userFacingErrorMapper;
    private LoggerInterface&MockObject $logger;
    private Payment&MockObject $payment;
    private OrderAdapterInterface&MockObject $order;

    /** @var array<string, mixed> */
    private array $additionalInfo = [];

    private ?string $transactionId = null;

    private ?bool $transactionClosed = null;

    protected function setUp(): void
    {
        $this->subjectReader = $this->createMock(SubjectReader::class);
        $this->userFacingErrorMapper = $this->createMock(UserFacingErrorMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setAdditionalInformation',
                'getAdditionalInformation',
                'setTransactionId',
                'setIsTransactionClosed',
            ])
            ->getMock();
        $this->payment->method('setAdditionalInformation')
            ->willReturnCallback(function (string $key, $value): Payment {
                $this->additionalInfo[$key] = $value;
                return $this->payment;
            });
        $this->payment->method('setTransactionId')
            ->willReturnCallback(function (string $id): Payment {
                $this->transactionId = $id;
                return $this->payment;
            });
        $this->payment->method('setIsTransactionClosed')
            ->willReturnCallback(function (bool $closed): Payment {
                $this->transactionClosed = $closed;
                return $this->payment;
            });

        $this->order = $this->createMock(OrderAdapterInterface::class);
        $this->order->method('getOrderIncrementId')->willReturn('000000042');
    }

    public function testHappyPathApprovedPersistsAndDoesNotThrow(): void
    {
        $this->primePaymentSubject();

        $this->userFacingErrorMapper->expects(self::never())->method('toLocalizedException');
        $this->logger->expects(self::never())->method('error');

        $this->makeHandler()->handle(
            $this->subjectStub(),
            ['response' => [
                'reverse_status'  => 'approved',
                'response_status' => 'success',
                'reversal_amount' => 5000,
            ]]
        );

        self::assertSame('approved', $this->additionalInfo['refund_status']);
        self::assertSame(5000, $this->additionalInfo['reversal_amount']);
        self::assertTrue($this->transactionClosed);
    }

    public function testDeclined1002AppErrorLogsAndThrowsMappedException(): void
    {
        $this->primePaymentSubject();

        $mapped = new LocalizedException(__('System error. Please try again in a moment.'));
        $this->userFacingErrorMapper->expects(self::once())
            ->method('toLocalizedException')
            ->with(1002, 'Application error', 'req-abc')
            ->willReturn($mapped);

        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                'TBC Flitt error mapped to user copy',
                self::callback(static function (array $ctx): bool {
                    return ($ctx['context'] ?? null) === 'refund.handler'
                        && ($ctx['error_code'] ?? null) === 1002
                        && ($ctx['error_message'] ?? null) === 'Application error'
                        && ($ctx['request_id'] ?? null) === 'req-abc'
                        && ($ctx['order_increment_id'] ?? null) === '000000042';
                })
            );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('System error. Please try again in a moment.');

        $this->makeHandler()->handle(
            $this->subjectStub(),
            ['response' => [
                'response_status' => 'failure',
                'error_code'      => 1002,
                'error_message'   => 'Application error',
                'request_id'      => 'req-abc',
            ]]
        );
    }

    public function testDeclined1013DuplicateOrderIdThrowsDifferentMappedMessage(): void
    {
        $this->primePaymentSubject();

        $mapped = new LocalizedException(
            __('This payment has already been processed. Please check your orders.')
        );
        $this->userFacingErrorMapper->expects(self::once())
            ->method('toLocalizedException')
            ->with(1013, 'Duplicate order_id', null)
            ->willReturn($mapped);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This payment has already been processed. Please check your orders.');

        $this->makeHandler()->handle(
            $this->subjectStub(),
            ['response' => [
                'response_status' => 'failure',
                'error_code'      => 1013,
                'error_message'   => 'Duplicate order_id',
            ]]
        );
    }

    public function testMissingErrorCodeFallsThroughToZeroForMapper(): void
    {
        $this->primePaymentSubject();

        $mapped = new LocalizedException(
            __('Payment couldn\'t be completed. Please try again or contact support.')
        );
        $this->userFacingErrorMapper->expects(self::once())
            ->method('toLocalizedException')
            ->with(0, 'foo', null)
            ->willReturn($mapped);

        $this->expectException(LocalizedException::class);

        $this->makeHandler()->handle(
            $this->subjectStub(),
            ['response' => [
                'response_status' => 'failure',
                'error_message'   => 'foo',
            ]]
        );
    }

    public function testUnknownErrorCodePassesThroughToMapperForFallback(): void
    {
        $this->primePaymentSubject();

        $mapped = new LocalizedException(
            __('Payment couldn\'t be completed. Please try again or contact support.')
        );
        $this->userFacingErrorMapper->expects(self::once())
            ->method('toLocalizedException')
            ->with(9999, 'Weird unknown error', null)
            ->willReturn($mapped);

        $this->expectException(LocalizedException::class);

        $this->makeHandler()->handle(
            $this->subjectStub(),
            ['response' => [
                'response_status' => 'failure',
                'error_code'      => 9999,
                'error_message'   => 'Weird unknown error',
            ]]
        );
    }

    public function testTransactionIdPersistedOnRefundTransactionIdAdditionalInfo(): void
    {
        $this->primePaymentSubject();

        $this->makeHandler()->handle(
            $this->subjectStub(),
            ['response' => [
                'reverse_status'  => 'approved',
                'response_status' => 'success',
                'transaction_id'  => 'tx-77',
            ]]
        );

        self::assertSame('tx-77', $this->transactionId);
        self::assertSame('tx-77', $this->additionalInfo['refund_transaction_id']);
        self::assertTrue($this->transactionClosed);
    }

    private function primePaymentSubject(): void
    {
        $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDataObject->method('getPayment')->willReturn($this->payment);
        $paymentDataObject->method('getOrder')->willReturn($this->order);

        $this->subjectReader->method('readPayment')->willReturn($paymentDataObject);
    }

    /**
     * @return array<string, mixed>
     */
    private function subjectStub(): array
    {
        return ['payment' => $this->createMock(PaymentDataObjectInterface::class)];
    }

    private function makeHandler(): RefundHandler
    {
        return new RefundHandler(
            subjectReader: $this->subjectReader,
            userFacingErrorMapper: $this->userFacingErrorMapper,
            logger: $this->logger,
        );
    }
}
