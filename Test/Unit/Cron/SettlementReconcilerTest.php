<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Cron;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\State as AppState;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Cron\SettlementReconciler;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;
use Shubo\TbcPayment\Service\PaymentLock;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Finding #5 — the settlement-recovery cron must re-drive SettlementService::settle()
 * UNDER the per-order PaymentLock (keyed on flitt_order_id), the same key every
 * other settle() caller uses. An unlocked re-drive would race the BUG-7
 * settlement_attempt read-modify-write against a concurrent tick or the admin
 * Settle button. On lock contention the order is skipped (next tick retries).
 */
class SettlementReconcilerTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private SortOrderBuilder&MockObject $sortOrderBuilder;
    private SettlementService&MockObject $settlementService;
    private Config&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private AppState&MockObject $appState;
    private PaymentLock&MockObject $paymentLock;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->sortOrderBuilder = $this->createMock(SortOrderBuilder::class);
        $this->settlementService = $this->createMock(SettlementService::class);
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->appState = $this->createMock(AppState::class);
        $this->paymentLock = $this->createMock(PaymentLock::class);

        $this->sortOrderBuilder->method('setField')->willReturnSelf();
        $this->sortOrderBuilder->method('setAscendingDirection')->willReturnSelf();
        $this->sortOrderBuilder->method('create')->willReturn($this->createMock(SortOrder::class));

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setSortOrders')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $this->appState->method('getAreaCode')->willReturn('crontab');
        $this->config->method('getSettlementMaxAttempts')->willReturn(6);
        $this->config->method('isSplitPaymentsEnabled')->willReturn(true);
        $this->config->method('getBacklogAlertThreshold')->willReturn(50);
        $this->settlementService->method('isAlreadySettled')->willReturn(false);
    }

    /**
     * Lock acquired → settle() runs under withLock keyed on flitt_order_id, and a
     * successful settle is reported recovered.
     */
    public function testSettleRunsUnderLockKeyedOnFlittOrderId(): void
    {
        $order = $this->makeOrder('duka_000000042_1700');
        $this->primeOrderList([$order]);

        // The lock must be keyed on the flitt_order_id and run the body.
        $this->paymentLock->expects(self::once())
            ->method('withLock')
            ->with('duka_000000042_1700', self::isType('callable'))
            ->willReturnCallback(static fn (string $k, callable $cb): mixed => $cb());

        $this->settlementService->expects(self::once())->method('settle')->with($order)->willReturn(true);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->buildReconciler()->execute();
    }

    /**
     * Lock contention (withLock → null) → settle() is NOT invoked and the order
     * is skipped; the next tick retries.
     */
    public function testLockContentionSkipsOrderWithoutSettling(): void
    {
        $order = $this->makeOrder('duka_000000042_1700');
        $this->primeOrderList([$order]);

        $contendedLock = $this->createMock(PaymentLock::class);
        $contendedLock->expects(self::once())
            ->method('withLock')
            ->with('duka_000000042_1700', self::isType('callable'))
            ->willReturn(null);

        // settle() never runs on contention.
        $this->settlementService->expects(self::never())->method('settle');

        $this->buildReconciler($contendedLock)->execute();
    }

    private function makeOrder(string $flittOrderId): Order&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $k): mixed => match ($k) {
                'flitt_order_id' => $flittOrderId,
                'settlement_attempt' => 0,
                default => null,
            }
        );
        $payment->method('setAdditionalInformation')->willReturnSelf();

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        return $order;
    }

    /**
     * @param list<Order&MockObject> $orders
     */
    private function primeOrderList(array $orders): void
    {
        $list = $this->createMock(OrderSearchResultInterface::class);
        $list->method('getItems')->willReturn($orders);
        $this->orderRepository->method('getList')->willReturn($list);
    }

    private function buildReconciler(?PaymentLock $lock = null): SettlementReconciler
    {
        return new SettlementReconciler(
            $this->orderRepository,
            $this->searchCriteriaBuilder,
            $this->sortOrderBuilder,
            $this->settlementService,
            $this->config,
            $this->logger,
            $this->appState,
            $lock ?? $this->paymentLock,
        );
    }
}
