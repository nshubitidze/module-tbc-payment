<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Controller\Payment\Confirm;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Http\Client\StatusClient;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Regression tests for BUG-11: Confirm must use a DB transaction + row lock
 * so a concurrent Callback or Cron run cannot create duplicate invoices.
 */
class ConfirmTest extends TestCase
{
    private JsonFactory&MockObject $jsonFactory;
    private CheckoutSession&MockObject $checkoutSession;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private StatusClient&MockObject $statusClient;
    private CallbackValidator&MockObject $callbackValidator;
    private Config&MockObject $config;
    private SettlementService&MockObject $settlementService;
    private LoggerInterface&MockObject $logger;
    private ResourceConnection&MockObject $resourceConnection;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private AdapterInterface&MockObject $connection;
    private JsonResult&MockObject $jsonResult;

    /** @var array<string, mixed> */
    private array $lastResultData = [];

    protected function setUp(): void
    {
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->statusClient = $this->createMock(StatusClient::class);
        $this->callbackValidator = $this->createMock(CallbackValidator::class);
        $this->config = $this->createMock(Config::class);
        $this->settlementService = $this->createMock(SettlementService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->jsonResult = $this->createMock(JsonResult::class);

        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->method('setData')->willReturnCallback(function ($data) {
            $this->lastResultData = $data;
            return $this->jsonResult;
        });

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $name): string => $name);
    }

    public function testEarlyReturnWhenOrderAlreadyInProcessingState(): void
    {
        $sessionOrder = $this->makeOrderMock(state: Order::STATE_PROCESSING, flittOrderId: 'duka_X_1');

        $this->checkoutSession->method('getLastRealOrder')->willReturn($sessionOrder);

        // No external call must happen, no transaction either.
        $this->statusClient->expects(self::never())->method('checkStatus');
        $this->connection->expects(self::never())->method('beginTransaction');

        $this->buildController()->execute();

        self::assertTrue($this->lastResultData['success']);
        self::assertTrue($this->lastResultData['already_processed']);
    }

    public function testSecondConfirmDoesNotReProcessWhenLockSeesProcessingState(): void
    {
        // First request: order is pending_payment in session AND on reload.
        // Second request: order is pending_payment in session, but ANOTHER process
        // (Callback) flipped it to PROCESSING by the time we acquire the lock.
        $sessionOrder = $this->makeOrderMock(
            state: Order::STATE_PENDING_PAYMENT,
            flittOrderId: 'duka_000000042_1700',
        );
        $this->checkoutSession->method('getLastRealOrder')->willReturn($sessionOrder);

        $this->statusClient->method('checkStatus')->willReturn(['response' => [
            'order_status' => 'approved',
            'order_id' => 'duka_000000042_1700',
            'payment_id' => 'pay-1',
            'amount' => 1000,
        ]]);
        $this->callbackValidator->method('validate')->willReturn(true);

        // The reloaded order is already in PROCESSING — concurrent path won.
        $reloadedOrder = $this->makeOrderMock(
            state: Order::STATE_PROCESSING,
            flittOrderId: 'duka_000000042_1700',
        );
        $this->primeOrderRepositoryReload($reloadedOrder);

        $this->primeRowLockSelect();

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())->method('commit');
        $this->connection->expects(self::never())->method('rollBack');

        // CRITICAL: orderRepository::save must NOT be called when concurrent
        // path already finalised the order (otherwise duplicate invoice).
        $this->orderRepository->expects(self::never())->method('save');

        // Settlement must not run either (we returned null from processWithLock).
        $this->settlementService->expects(self::never())->method('settle');

        $this->buildController()->execute();

        self::assertTrue($this->lastResultData['success']);
    }

    public function testHappyPathProcessesAndCommits(): void
    {
        $sessionOrder = $this->makeOrderMock(
            state: Order::STATE_PENDING_PAYMENT,
            flittOrderId: 'duka_000000042_1700',
        );
        $this->checkoutSession->method('getLastRealOrder')->willReturn($sessionOrder);

        $this->statusClient->method('checkStatus')->willReturn(['response' => [
            'order_status' => 'approved',
            'order_id' => 'duka_000000042_1700',
            'payment_id' => 'pay-1',
            'amount' => 1000,
        ]]);
        $this->callbackValidator->method('validate')->willReturn(true);
        $this->config->method('isPreauth')->willReturn(false);

        // The reloaded order is fresh: still pending — we should process.
        $reloadedOrder = $this->makeOrderMock(
            state: Order::STATE_PENDING_PAYMENT,
            flittOrderId: 'duka_000000042_1700',
            grandTotal: 10.00,
        );
        $reloadedOrder->getPayment()
            ->expects(self::never())
            ->method('setParentTransactionId');
        $this->primeOrderRepositoryReload($reloadedOrder);

        $this->primeRowLockSelect();

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())->method('commit');
        // Two saves: one inside transaction (order processed), one after settlement.
        $this->orderRepository->expects(self::atLeastOnce())->method('save');

        $this->buildController()->execute();

        self::assertTrue($this->lastResultData['success']);
    }

    /**
     * Regression for Session 3 Priority 3.1 — dropping the dangling
     * parent_transaction_id synthesized from "{increment_id}-auth".
     *
     * Assert that the preauth branch also never calls setParentTransactionId.
     */
    public function testPreauthBranchDoesNotSetParentTransactionId(): void
    {
        $sessionOrder = $this->makeOrderMock(
            state: Order::STATE_PENDING_PAYMENT,
            flittOrderId: 'duka_000000042_1700',
        );
        $this->checkoutSession->method('getLastRealOrder')->willReturn($sessionOrder);

        $this->statusClient->method('checkStatus')->willReturn(['response' => [
            'order_status' => 'approved',
            'order_id' => 'duka_000000042_1700',
            'payment_id' => 'pay-1',
            'amount' => 1000,
        ]]);
        $this->callbackValidator->method('validate')->willReturn(true);
        $this->config->method('isPreauth')->willReturn(true);

        $reloadedOrder = $this->makeOrderMock(
            state: Order::STATE_PENDING_PAYMENT,
            flittOrderId: 'duka_000000042_1700',
            grandTotal: 10.00,
        );
        $reloadedOrder->getPayment()
            ->expects(self::never())
            ->method('setParentTransactionId');
        $this->primeOrderRepositoryReload($reloadedOrder);

        $this->primeRowLockSelect();

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())->method('commit');

        $this->buildController()->execute();

        self::assertTrue($this->lastResultData['success']);
    }

    private function buildController(): Confirm
    {
        return new Confirm(
            jsonFactory: $this->jsonFactory,
            checkoutSession: $this->checkoutSession,
            orderRepository: $this->orderRepository,
            statusClient: $this->statusClient,
            callbackValidator: $this->callbackValidator,
            config: $this->config,
            settlementService: $this->settlementService,
            logger: $this->logger,
            resourceConnection: $this->resourceConnection,
            searchCriteriaBuilder: $this->searchCriteriaBuilder,
        );
    }

    private function makeOrderMock(
        string $state,
        string $flittOrderId,
        float $grandTotal = 10.00,
    ): Order&MockObject {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getAdditionalInformation', 'setAdditionalInformation',
                'setIsTransactionPending', 'setIsTransactionClosed',
                'setTransactionId', 'setParentTransactionId',
                'registerCaptureNotification',
            ])
            ->getMock();
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(static function (string $key) use ($flittOrderId) {
                return $key === 'flitt_order_id' ? $flittOrderId : null;
            });
        $payment->method('setAdditionalInformation')->willReturnSelf();
        $payment->method('setIsTransactionPending')->willReturnSelf();
        $payment->method('setIsTransactionClosed')->willReturnSelf();

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getEntityId', 'getIncrementId', 'getStoreId', 'getState',
                'getPayment', 'setState', 'setStatus', 'addCommentToStatusHistory',
                'getGrandTotal',
            ])
            ->getMock();
        $order->method('getEntityId')->willReturn(11);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn($state);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    private function primeOrderRepositoryReload(Order $order): void
    {
        $searchCriteria = $this->createMock(SearchCriteria::class);
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$order]);
        $this->orderRepository->method('getList')->willReturn($searchResult);
    }

    private function primeRowLockSelect(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();

        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(['entity_id' => 11, 'state' => 'pending_payment']);
    }
}
