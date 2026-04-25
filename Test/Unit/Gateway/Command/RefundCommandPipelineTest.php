<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\Command\GatewayCommand;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;
use Shubo\TbcPayment\Gateway\Http\TransferFactory;
use Shubo\TbcPayment\Gateway\Request\RefundRequestBuilder;
use Shubo\TbcPayment\Gateway\Response\RefundHandler;

/**
 * Session 3 Pass 4 — reviewer-signoff M-1 regression guard.
 *
 * Drives the actual {@see GatewayCommand} pipeline end-to-end using:
 *   - real {@see RefundRequestBuilder}
 *   - real {@see TransferFactory}
 *   - stub {@see ClientInterface} returning a Flitt 1002 ("Application
 *     error") envelope verbatim from the production trace
 *   - real {@see RefundHandler} with a mocked {@see UserFacingErrorMapper}
 *
 * The pipeline MUST surface the friendly mapped {@see LocalizedException},
 * NOT Magento's generic `CommandException`. If a future change reintroduces
 * a validator on `ShuboTbcRefundCommand` (etc/di.xml) the
 * `processErrors`/`CommandException` short-circuit will preempt the handler
 * and this test will fail loudly.
 *
 * @see \Magento\Payment\Gateway\Command\GatewayCommand::execute()
 * @see app/code/Shubo/TbcPayment/docs/online-refund-rca.md "Pass 4 follow-up"
 */
class RefundCommandPipelineTest extends TestCase
{
    private Config&MockObject $config;
    private SubjectReader $subjectReader;
    private RefundHandler $handler;
    private UserFacingErrorMapper&MockObject $userFacingErrorMapper;
    private LoggerInterface&MockObject $refundHandlerLogger;
    private LoggerInterface&MockObject $gatewayLogger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getMerchantId')->willReturn('1549901');
        $this->config->method('getPassword')->willReturn('test-password');

        $this->subjectReader = new SubjectReader();
        $this->userFacingErrorMapper = $this->createMock(UserFacingErrorMapper::class);
        $this->refundHandlerLogger = $this->createMock(LoggerInterface::class);
        $this->gatewayLogger = $this->createMock(LoggerInterface::class);

        $this->handler = new RefundHandler(
            subjectReader: $this->subjectReader,
            userFacingErrorMapper: $this->userFacingErrorMapper,
            logger: $this->refundHandlerLogger,
        );
    }

    /**
     * On Flitt 1002 the friendly {@see LocalizedException} from
     * `UserFacingErrorMapper` MUST bubble up, not Magento's
     * `CommandException` "Transaction has been declined" default.
     */
    public function testFlitt1002SurfacesFriendlyMappedException(): void
    {
        $client = $this->stubClientReturning([
            'response' => [
                'response_status' => 'failure',
                'error_code'      => 1002,
                'error_message'   => 'Application error',
                'request_id'      => 'req-pipeline-test',
            ],
        ]);

        $friendly = new LocalizedException(__('System error. Please try again in a moment.'));
        $this->userFacingErrorMapper->expects(self::once())
            ->method('toLocalizedException')
            ->with(1002, 'Application error', 'req-pipeline-test')
            ->willReturn($friendly);

        // The handler-side logger MUST log the raw triple at error level
        // so ops can correlate to Flitt support — this is the contract
        // documented in Gateway/Response/RefundHandler.php.
        $this->refundHandlerLogger->expects(self::once())
            ->method('error')
            ->with('TBC Flitt error mapped to user copy', self::callback(
                static fn (array $ctx): bool =>
                    ($ctx['error_code'] ?? null) === 1002
                    && ($ctx['error_message'] ?? null) === 'Application error'
                    && ($ctx['request_id'] ?? null) === 'req-pipeline-test'
            ));

        $command = $this->buildCommand($client);

        $caught = null;
        try {
            $command->execute($this->buildSubject(grandTotal: 50.00));
        } catch (\Throwable $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'Refund command must throw on Flitt 1002');
        self::assertNotInstanceOf(
            CommandException::class,
            $caught,
            'Pipeline must NOT raise Magento\'s generic CommandException — that '
            . 'means a validator preempted the handler. Drop the validator '
            . 'from ShuboTbcRefundCommand (see reviewer-signoff M-1).'
        );
        self::assertInstanceOf(
            LocalizedException::class,
            $caught,
            'Pipeline must surface a friendly LocalizedException from RefundHandler.',
        );
        self::assertSame(
            'System error. Please try again in a moment.',
            $caught->getMessage(),
            'Surfaced message must come from UserFacingErrorMapper, not Magento\'s default copy.',
        );
    }

    /**
     * Happy-path symmetry — when Flitt approves the reverse, the pipeline
     * runs through the handler's persistence branch without throwing.
     */
    public function testFlittApprovedRunsHandlerPersistenceWithoutThrow(): void
    {
        $client = $this->stubClientReturning([
            'response' => [
                'reverse_status'  => 'approved',
                'response_status' => 'success',
                'reversal_amount' => 5000,
                'transaction_id'  => 'tx-pipeline-77',
            ],
        ]);

        $this->userFacingErrorMapper->expects(self::never())
            ->method('toLocalizedException');
        $this->refundHandlerLogger->expects(self::never())->method('error');

        $command = $this->buildCommand($client);
        $subject = $this->buildSubject(grandTotal: 50.00);

        $command->execute($subject);

        // The handler stamps refund_status onto the Payment via setAdditionalInformation —
        // assert via the captured mock. Pull payment back out of the subject.
        /** @var PaymentDataObjectInterface $pdo */
        $pdo = $subject['payment'];
        /** @var Payment&MockObject $payment */
        $payment = $pdo->getPayment();
        // Only verifying the flow completed; payment mock state is tested in
        // RefundHandlerTest. This test owns the pipeline contract.
        self::assertNotNull($payment);
    }

    private function buildCommand(ClientInterface $client): GatewayCommand
    {
        $transferFactory = new TransferFactory(new TransferBuilder());
        $requestBuilder = new RefundRequestBuilder(
            config: $this->config,
            subjectReader: $this->subjectReader,
        );

        // Reviewer-signoff M-1: NO validator. RefundHandler is the sole gatekeeper.
        return new GatewayCommand(
            $requestBuilder,
            $transferFactory,
            $client,
            $this->gatewayLogger,
            $this->handler,
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function stubClientReturning(array $response): ClientInterface
    {
        return new class ($response) implements ClientInterface {
            /**
             * @param array<string, mixed> $response
             */
            public function __construct(private readonly array $response)
            {
            }

            /**
             * @return array<string, mixed>
             */
            public function placeRequest(TransferInterface $transferObject): array
            {
                return $this->response;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSubject(float $grandTotal): array
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getAdditionalInformation',
                'setAdditionalInformation',
                'setTransactionId',
                'setIsTransactionClosed',
            ])
            ->getMock();
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(static function (string $key): ?string {
                return match ($key) {
                    'flitt_order_id' => 'duka_000000042_1700000000',
                    default => null,
                };
            });
        $payment->method('setAdditionalInformation')->willReturnSelf();
        $payment->method('setTransactionId')->willReturnSelf();
        $payment->method('setIsTransactionClosed')->willReturnSelf();

        $order = $this->createMock(OrderAdapterInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCurrencyCode')->willReturn('GEL');
        $order->method('getOrderIncrementId')->willReturn('000000042');

        $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDataObject->method('getPayment')->willReturn($payment);
        $paymentDataObject->method('getOrder')->willReturn($order);

        return [
            'payment' => $paymentDataObject,
            'amount'  => $grandTotal,
        ];
    }
}
