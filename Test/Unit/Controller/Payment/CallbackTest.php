<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Controller\Payment;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Controller\Payment\Callback;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Model\OrderLocator;
use Shubo\TbcPayment\Service\OrderApprovalApplier;
use Shubo\TbcPayment\Service\PaymentLock;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Regression tests for BUG-6: Flitt 'reversed' callback must transition the
 * Magento order state so refunded orders do not linger in 'processing'.
 *
 * The transition matrix under test:
 *  - closed/canceled                → no-op (idempotent)
 *  - pending_payment / payment_review / new / holded → cancel
 *  - processing / complete with full amount   → closed
 *  - processing / complete with partial       → state unchanged, comment only
 *  - unknown state                   → log warning, no state change
 */
class CallbackTest extends TestCase
{
    private HttpRequest&MockObject $request;
    private JsonFactory&MockObject $jsonFactory;
    private Json&MockObject $jsonSerializer;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private OrderLocator&MockObject $orderLocator;
    private CallbackValidator&MockObject $callbackValidator;
    private OrderApprovalApplier&MockObject $approvalApplier;
    private SettlementService&MockObject $settlementService;
    private LoggerInterface&MockObject $logger;
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private JsonResult&MockObject $jsonResult;
    private UserFacingErrorMapper&MockObject $userFacingErrorMapper;
    private PaymentLock&MockObject $paymentLock;
    private Config&MockObject $config;

    /** @var list<string> */
    private array $comments = [];

    /** @var list<array{0: string, 1: string}> */
    private array $stateTransitions = [];

    private int $cancelCalls = 0;

    protected function setUp(): void
    {
        $this->request              = $this->createMock(HttpRequest::class);
        $this->jsonFactory          = $this->createMock(JsonFactory::class);
        $this->jsonSerializer       = $this->createMock(Json::class);
        $this->orderRepository      = $this->createMock(OrderRepositoryInterface::class);
        $this->orderLocator         = $this->createMock(OrderLocator::class);
        $this->callbackValidator    = $this->createMock(CallbackValidator::class);
        $this->approvalApplier      = $this->createMock(OrderApprovalApplier::class);
        $this->settlementService    = $this->createMock(SettlementService::class);
        $this->logger               = $this->createMock(LoggerInterface::class);
        $this->resourceConnection   = $this->createMock(ResourceConnection::class);
        $this->connection           = $this->createMock(AdapterInterface::class);
        $this->jsonResult           = $this->createMock(JsonResult::class);
        $this->userFacingErrorMapper = $this->createMock(UserFacingErrorMapper::class);
        $this->paymentLock          = $this->createMock(PaymentLock::class);
        $this->config               = $this->createMock(Config::class);

        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->jsonResult->method('setHttpResponseCode')->willReturnSelf();

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);

        $this->callbackValidator->method('validate')->willReturn(true);

        // Empty allowlist → all source IPs allowed (fail-open default).
        $this->config->method('getCallbackIpAllowlist')->willReturn([]);

        // PaymentLock under test here just runs the wrapped callable (the lock
        // is acquired). Concurrency-contention behaviour is covered separately.
        $this->paymentLock->method('withLock')->willReturnCallback(
            static fn (string $key, callable $cb): mixed => $cb()
        );

        // Settlement must never be invoked on the reversed branch.
        $this->settlementService->expects(self::never())->method('settle');
    }

    public function testReversedFromProcessingWithFullAmountClosesOrder(): void
    {
        $order = $this->primeOrder(
            state: Order::STATE_PROCESSING,
            grandTotal: 10.50,
            callbackData: ['reverse_amount' => 1050, 'payment_id' => 'abc'],
        );

        $this->buildController()->execute();

        self::assertSame(
            [[Order::STATE_CLOSED, Order::STATE_CLOSED]],
            $this->stateTransitions,
            'Processing + full reversal should close the order'
        );
        self::assertSame(0, $this->cancelCalls);
        self::assertNotEmpty($this->comments);
        self::assertStringContainsString('Order closed', $this->comments[0]);
        self::assertStringContainsString('abc', $this->comments[0]);
        unset($order);
    }

    public function testReversedFromCompleteWithFullAmountClosesOrder(): void
    {
        $order = $this->primeOrder(
            state: Order::STATE_COMPLETE,
            grandTotal: 10.50,
            callbackData: ['reverse_amount' => 1050, 'payment_id' => 'abc'],
        );

        $this->buildController()->execute();

        self::assertSame(
            [[Order::STATE_CLOSED, Order::STATE_CLOSED]],
            $this->stateTransitions,
        );
        self::assertSame(0, $this->cancelCalls);
        self::assertStringContainsString('Order closed', $this->comments[0]);
        unset($order);
    }

    public function testReversedFromPendingPaymentCancelsOrder(): void
    {
        $order = $this->primeOrder(
            state: Order::STATE_PENDING_PAYMENT,
            grandTotal: 10.50,
            callbackData: ['reverse_amount' => 1050, 'payment_id' => 'abc'],
        );

        $this->buildController()->execute();

        self::assertSame(1, $this->cancelCalls);
        self::assertSame([], $this->stateTransitions);
        self::assertNotEmpty($this->comments);
        self::assertStringContainsString('before capture', $this->comments[0]);
        unset($order);
    }

    public function testReversedFromPaymentReviewCancelsOrder(): void
    {
        $order = $this->primeOrder(
            state: Order::STATE_PAYMENT_REVIEW,
            grandTotal: 10.50,
            callbackData: ['reverse_amount' => 1050, 'payment_id' => 'abc'],
        );

        $this->buildController()->execute();

        self::assertSame(1, $this->cancelCalls);
        self::assertStringContainsString('before capture', $this->comments[0]);
        unset($order);
    }

    public function testReversedFromHoldedCancelsOrder(): void
    {
        $order = $this->primeOrder(
            state: Order::STATE_HOLDED,
            grandTotal: 10.50,
            callbackData: ['reverse_amount' => 1050, 'payment_id' => 'abc'],
        );

        $this->buildController()->execute();

        self::assertSame(1, $this->cancelCalls);
        self::assertStringContainsString('before capture', $this->comments[0]);
        unset($order);
    }

    public function testReversedFromNewCancelsOrder(): void
    {
        $order = $this->primeOrder(
            state: Order::STATE_NEW,
            grandTotal: 10.50,
            callbackData: ['reverse_amount' => 1050, 'payment_id' => 'abc'],
        );

        $this->buildController()->execute();

        self::assertSame(1, $this->cancelCalls);
        self::assertStringContainsString('before capture', $this->comments[0]);
        unset($order);
    }

    public function testReversedFromProcessingWithPartialAmountLeavesStateUnchanged(): void
    {
        $order = $this->primeOrder(
            state: Order::STATE_PROCESSING,
            grandTotal: 10.50,
            callbackData: ['reverse_amount' => 500, 'payment_id' => 'abc'],
        );

        $this->buildController()->execute();

        self::assertSame(0, $this->cancelCalls);
        self::assertSame([], $this->stateTransitions);
        self::assertNotEmpty($this->comments);
        self::assertStringContainsString('Partial reversal', $this->comments[0]);
        unset($order);
    }

    public function testReversedFromClosedIsIdempotent(): void
    {
        $order = $this->primeOrder(
            state: Order::STATE_CLOSED,
            grandTotal: 10.50,
            callbackData: ['reverse_amount' => 1050, 'payment_id' => 'abc'],
        );

        $this->buildController()->execute();

        self::assertSame(0, $this->cancelCalls);
        self::assertSame([], $this->stateTransitions);
        self::assertSame([], $this->comments);
        unset($order);
    }

    public function testReversedFromCanceledIsIdempotent(): void
    {
        $order = $this->primeOrder(
            state: Order::STATE_CANCELED,
            grandTotal: 10.50,
            callbackData: ['reverse_amount' => 1050, 'payment_id' => 'abc'],
        );

        $this->buildController()->execute();

        self::assertSame(0, $this->cancelCalls);
        self::assertSame([], $this->stateTransitions);
        self::assertSame([], $this->comments);
        unset($order);
    }

    public function testReversedFromUnknownStateLogsWarning(): void
    {
        $this->logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(self::stringContains('unexpected reversal'));

        $order = $this->primeOrder(
            state: 'pending',
            grandTotal: 10.50,
            callbackData: ['reverse_amount' => 1050, 'payment_id' => 'abc'],
        );

        $this->buildController()->execute();

        self::assertSame(0, $this->cancelCalls);
        self::assertSame([], $this->stateTransitions);
        self::assertSame([], $this->comments);
        unset($order);
    }

    public function testReverseAmountFallsBackToAmountWhenMissing(): void
    {
        $order = $this->primeOrder(
            state: Order::STATE_PROCESSING,
            grandTotal: 10.50,
            callbackData: ['amount' => 1050, 'payment_id' => 'abc'],
        );

        $this->buildController()->execute();

        self::assertSame(
            [[Order::STATE_CLOSED, Order::STATE_CLOSED]],
            $this->stateTransitions,
            'Missing reverse_amount should fall back to amount'
        );
        unset($order);
    }

    public function testReverseAmountFallsBackToGrandTotalWhenBothMissing(): void
    {
        $order = $this->primeOrder(
            state: Order::STATE_PROCESSING,
            grandTotal: 10.50,
            callbackData: ['payment_id' => 'abc'],
        );

        $this->buildController()->execute();

        self::assertSame(
            [[Order::STATE_CLOSED, Order::STATE_CLOSED]],
            $this->stateTransitions,
            'Both missing should assume full reversal via grand-total fallback'
        );
        unset($order);
    }

    /**
     * Finding #1 (MUST-FIX) — a bank/fraud REVERSAL re-uses the captured
     * payment_id. The replay guard stamps flitt_processed_payment_id on capture;
     * if the guard short-circuited every matching payment_id it would 200-no-op
     * this reversal and handleReversed() (which closes the order) would never
     * run. Because the short-circuit is scoped to APPROVED status only, a
     * reversed callback carrying the SAME payment_id still routes to
     * handleReversed and the PROCESSING order transitions to CLOSED.
     */
    public function testReversedWithMatchingStoredPaymentIdStillClosesOrder(): void
    {
        $order = $this->primeOrder(
            state: Order::STATE_PROCESSING,
            grandTotal: 10.50,
            callbackData: ['reverse_amount' => 1050, 'payment_id' => 'pay-cap-1'],
            // The captured payment_id was stamped on this order on capture.
            processedPaymentId: 'pay-cap-1',
        );

        $this->buildController()->execute();

        self::assertSame(
            [[Order::STATE_CLOSED, Order::STATE_CLOSED]],
            $this->stateTransitions,
            'A reversed callback with a matching stored payment_id must NOT be '
            . 'treated as a replay; handleReversed must close the order.'
        );
        self::assertNotEmpty($this->comments);
        self::assertStringContainsString('Order closed', $this->comments[0]);
        unset($order);
    }

    /**
     * Build an order mock + wire all framework mocks so a canned reversed
     * callback payload routes through the controller.
     *
     * @param array<string, mixed> $callbackData
     */
    private function primeOrder(
        string $state,
        float $grandTotal,
        array $callbackData,
        string $processedPaymentId = '',
    ): Order&MockObject {
        $callbackData += [
            'order_id' => 'duka_000000042_1234',
            'order_status' => 'reversed',
        ];

        $rawBody = '{"canned":true}';
        $this->request->method('getContent')->willReturn($rawBody);
        $this->jsonSerializer->method('unserialize')->with($rawBody)->willReturn($callbackData);

        $payment = $this->createMock(Payment::class);
        $payment->method('setAdditionalInformation')->willReturnSelf();
        $payment->method('setTransactionId')->willReturnSelf();
        // Stored processed payment_id: empty by default (replay guard inert);
        // a non-empty value simulates a capture having stamped it, used by the
        // reversal-with-matching-id regression (finding #1).
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (?string $key = null): mixed
                => $key === 'flitt_processed_payment_id' && $processedPaymentId !== ''
                    ? $processedPaymentId
                    : null
        );

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn($state);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getGrandTotal')->willReturn($grandTotal);

        $order->method('setState')->willReturnCallback(
            function (string $newState) use ($order): Order {
                $this->stateTransitions[] = [$newState, $this->stateTransitions[count($this->stateTransitions) - 1][1] ?? ''];
                // Record state only on first call; status comes via setStatus.
                return $order;
            }
        );
        $order->method('setStatus')->willReturnCallback(
            function (string $newStatus) use ($order): Order {
                // Pair with the most recent setState row.
                $idx = count($this->stateTransitions) - 1;
                if ($idx >= 0) {
                    $this->stateTransitions[$idx][1] = $newStatus;
                }
                return $order;
            }
        );
        $order->method('cancel')->willReturnCallback(
            function () use ($order): Order {
                $this->cancelCalls++;
                return $order;
            }
        );
        $order->method('addCommentToStatusHistory')->willReturnCallback(
            function (string $msg) use ($order): \Magento\Sales\Model\Order\Status\History {
                $this->comments[] = $msg;
                /** @var \Magento\Sales\Model\Order\Status\History&MockObject $history */
                $history = $this->createMock(\Magento\Sales\Model\Order\Status\History::class);
                return $history;
            }
        );

        // The controller resolves the order via OrderLocator now (was the inline
        // SearchCriteriaBuilder getList lookup). extractIncrementId strips the
        // duka_ prefix; byIncrementId returns the order (called twice — once
        // pre-transaction, once on the in-transaction reload).
        $this->orderLocator->method('extractIncrementId')
            ->willReturnCallback(static function (string $flittOrderId): string {
                if (preg_match('/^duka_(.+)_\d+$/', $flittOrderId, $matches)) {
                    return $matches[1];
                }
                return $flittOrderId;
            });
        $this->orderLocator->method('byIncrementId')->willReturn($order);

        return $order;
    }

    private function buildController(): Callback
    {
        return new Callback(
            $this->request,
            $this->jsonFactory,
            $this->jsonSerializer,
            $this->orderRepository,
            $this->orderLocator,
            $this->callbackValidator,
            $this->approvalApplier,
            $this->settlementService,
            $this->logger,
            $this->resourceConnection,
            $this->userFacingErrorMapper,
            $this->paymentLock,
            $this->config,
        );
    }
}
