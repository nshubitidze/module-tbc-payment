<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Controller\Payment;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Controller\Payment\Callback;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
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
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private CallbackValidator&MockObject $callbackValidator;
    private SettlementService&MockObject $settlementService;
    private Config&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private JsonResult&MockObject $jsonResult;

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
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->callbackValidator    = $this->createMock(CallbackValidator::class);
        $this->settlementService    = $this->createMock(SettlementService::class);
        $this->config               = $this->createMock(Config::class);
        $this->logger               = $this->createMock(LoggerInterface::class);
        $this->resourceConnection   = $this->createMock(ResourceConnection::class);
        $this->connection           = $this->createMock(AdapterInterface::class);
        $this->jsonResult           = $this->createMock(JsonResult::class);

        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->jsonResult->method('setHttpResponseCode')->willReturnSelf();

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);

        $this->callbackValidator->method('validate')->willReturn(true);

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
     * Build an order mock + wire all framework mocks so a canned reversed
     * callback payload routes through the controller.
     *
     * @param array<string, mixed> $callbackData
     */
    private function primeOrder(string $state, float $grandTotal, array $callbackData): Order&MockObject
    {
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

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$order]);

        $searchCriteria = $this->createMock(SearchCriteria::class);
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);
        $this->orderRepository->method('getList')->willReturn($searchResult);

        return $order;
    }

    private function buildController(): Callback
    {
        return new Callback(
            $this->request,
            $this->jsonFactory,
            $this->jsonSerializer,
            $this->orderRepository,
            $this->searchCriteriaBuilder,
            $this->callbackValidator,
            $this->settlementService,
            $this->config,
            $this->logger,
            $this->resourceConnection,
        );
    }
}
