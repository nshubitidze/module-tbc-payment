<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Cron;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Cron\PendingOrderReconciler;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Http\Client\StatusClient;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Edge-cases-matrix §4 coverage: when Flitt's `/api/status/order_id` returns
 * "order not found" (error_code=1011 or empty failure envelope), the
 * reconciler must cancel the Magento order ONLY if the order's created_at
 * is past the configured payment_lifetime — otherwise Flitt may still be
 * catching up and a premature cancel would race a late success.
 */
class PendingOrderReconcilerTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private SortOrderBuilder&MockObject $sortOrderBuilder;
    private StatusClient&MockObject $statusClient;
    private CallbackValidator&MockObject $callbackValidator;
    private SettlementService&MockObject $settlementService;
    private Config&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private ResourceConnection&MockObject $resourceConnection;
    private AppState&MockObject $appState;
    private AdapterInterface&MockObject $adapter;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->sortOrderBuilder = $this->createMock(SortOrderBuilder::class);
        $this->statusClient = $this->createMock(StatusClient::class);
        $this->callbackValidator = $this->createMock(CallbackValidator::class);
        $this->settlementService = $this->createMock(SettlementService::class);
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->appState = $this->createMock(AppState::class);
        $this->adapter = $this->createMock(AdapterInterface::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->adapter);

        $this->sortOrderBuilder->method('setField')->willReturnSelf();
        $this->sortOrderBuilder->method('setAscendingDirection')->willReturnSelf();
        $this->sortOrderBuilder->method('create')->willReturn($this->createMock(SortOrder::class));

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setSortOrders')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $this->config->method('getPaymentLifetime')->willReturn(3600);

        // App state may or may not be set — both paths must succeed.
        $this->appState->method('getAreaCode')->willReturn('crontab');
    }

    /**
     * Flitt returns error_code=1011 for a flitt_order_id it never received
     * (token endpoint timed out on our side), AND the Magento order is
     * older than payment_lifetime → reconciler MUST cancel the order and
     * leave a history comment identifying the outage.
     */
    public function testCancelsOrderWhenFlittReturnsNotFoundAfterLifetime(): void
    {
        $createdAtOld = (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s');
        [$order, $payment] = $this->primeOrder(
            flittOrderId: 'duka_000000055_1700000000',
            createdAt: $createdAtOld,
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')
            ->with('duka_000000055_1700000000', 1)
            ->willReturn([
                'response' => [
                    'response_status' => 'failure',
                    'error_code'      => 1011,
                    'error_message'   => 'Order not found',
                ],
            ]);

        // Signature validation MUST NOT be attempted on a not-found envelope —
        // Flitt does not sign failure responses like it signs success responses.
        $this->callbackValidator->expects(self::never())->method('validate');

        $order->expects(self::once())->method('cancel');
        $order->expects(self::once())
            ->method('addCommentToStatusHistory')
            ->with(self::callback(static function ($comment): bool {
                $str = (string) $comment;
                return str_contains($str, 'Flitt never received this order')
                    && str_contains($str, 'cancelled by reconciler')
                    && str_contains($str, 'duka_000000055_1700000000');
            }));

        // Inner transaction in handleOrderNotFound commits the cancel.
        $this->adapter->expects(self::atLeastOnce())->method('beginTransaction');
        $this->adapter->expects(self::atLeastOnce())->method('commit');
        $this->adapter->expects(self::never())->method('rollBack');

        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->makeReconciler()->execute();
    }

    /**
     * Flitt returns 1011 but the order was created a minute ago — well
     * within payment_lifetime. Reconciler MUST leave the order alone:
     * Flitt may still register the order, or a later cron run will take
     * over once the lifetime is past.
     */
    public function testKeepsOrderWhenFlittReturnsNotFoundWithinLifetime(): void
    {
        $createdAtFresh = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        [$order, $payment] = $this->primeOrder(
            flittOrderId: 'duka_000000055_1700000000',
            createdAt: $createdAtFresh,
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'response' => [
                'response_status' => 'failure',
                'error_code'      => 1011,
                'error_message'   => 'Order not found',
            ],
        ]);

        $this->callbackValidator->expects(self::never())->method('validate');
        $order->expects(self::never())->method('cancel');
        $order->expects(self::never())->method('addCommentToStatusHistory');
        $this->orderRepository->expects(self::never())->method('save');
        $this->adapter->expects(self::never())->method('beginTransaction');
        $this->adapter->expects(self::never())->method('commit');

        // Session 3 Priority 3.1 regression guard — the not-found branch
        // never reaches handleApproved, so the dropped setter must not
        // reappear on this code path either.
        $payment->expects(self::never())->method('setParentTransactionId');

        $this->makeReconciler()->execute();
    }

    /**
     * Session 3 Priority 3.1 (architect-scope §3.1.4) — explicit happy-path
     * regression guard for the reconciler's `handleApproved` branch (both
     * direct-sale and preauth, which previously called
     * `setParentTransactionId('{increment_id}-auth')`). The setter is gone
     * from both branches and must never reappear.
     */
    public function testApprovedBranchNeverCallsSetParentTransactionId(): void
    {
        [$order, $payment] = $this->primeOrder(
            flittOrderId: 'duka_000000055_1700000000',
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
            grandTotal: 50.00,
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'response' => [
                'order_status'    => 'approved',
                'response_status' => 'success',
                'payment_id'      => 'pay-77',
                'amount'          => 5000,
            ],
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);

        // Direct-sale branch.
        $this->config->method('isPreauth')->willReturn(false);

        $payment->expects(self::never())->method('setParentTransactionId');

        $this->makeReconciler()->execute();
    }

    /**
     * Same regression guard but for the preauth branch (the second
     * `setParentTransactionId` site identified by architect-scope §3.1.1).
     */
    public function testApprovedPreauthBranchNeverCallsSetParentTransactionId(): void
    {
        [$order, $payment] = $this->primeOrder(
            flittOrderId: 'duka_000000055_1700000000',
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
            grandTotal: 50.00,
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'response' => [
                'order_status'    => 'approved',
                'response_status' => 'success',
                'payment_id'      => 'pay-78',
                'amount'          => 5000,
            ],
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);

        // Preauth branch.
        $this->config->method('isPreauth')->willReturn(true);

        $payment->expects(self::never())->method('setParentTransactionId');

        $this->makeReconciler()->execute();
    }

    private function makeReconciler(): PendingOrderReconciler
    {
        return new PendingOrderReconciler(
            $this->orderRepository,
            $this->searchCriteriaBuilder,
            $this->sortOrderBuilder,
            $this->statusClient,
            $this->callbackValidator,
            $this->settlementService,
            $this->config,
            $this->logger,
            $this->resourceConnection,
            $this->appState,
        );
    }

    /**
     * @return array{0: Order&MockObject, 1: Payment&MockObject}
     */
    private function primeOrder(
        string $flittOrderId,
        string $createdAt,
        float $grandTotal = 50.00,
    ): array {
        // We mock every payment method that handleApproved could possibly
        // call, INCLUDING setParentTransactionId — even though it's the
        // method we're asserting must never run. PHPUnit's `expects(never)`
        // semantics require the method to be on the mock surface.
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getAdditionalInformation',
                'setAdditionalInformation',
                'getMethod',
                'setTransactionId',
                'setParentTransactionId',
                'setIsTransactionPending',
                'setIsTransactionClosed',
                'registerCaptureNotification',
            ])
            ->getMock();
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn(?string $key = null) => $key === 'flitt_order_id' ? $flittOrderId : null
        );
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('setAdditionalInformation')->willReturnSelf();
        $payment->method('setTransactionId')->willReturnSelf();
        $payment->method('setIsTransactionPending')->willReturnSelf();
        $payment->method('setIsTransactionClosed')->willReturnSelf();

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getPayment', 'getIncrementId', 'getStoreId', 'getCreatedAt',
                'cancel', 'addCommentToStatusHistory', 'getState',
                'setState', 'setStatus', 'getGrandTotal',
            ])
            ->getMock();
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIncrementId')->willReturn('000000055');
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCreatedAt')->willReturn($createdAt);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        return [$order, $payment];
    }

    /**
     * @param list<Order&MockObject> $orders
     */
    private function primeOrderSearch(array $orders): void
    {
        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn($orders);
        $this->orderRepository->method('getList')->willReturn($searchResult);
    }
}
