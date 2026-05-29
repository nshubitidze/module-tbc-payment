<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Model\OrderLocator;

/**
 * Batch 3 SIMPLIFY-4 §2: OrderLocator centralises the Flitt-order-id → Magento
 * order lookup for the three frontend capture controllers (Callback, Confirm,
 * ReturnAction). These tests pin the three branches each copy used to carry
 * inline: by increment id, by flitt order id (with the strict format + stored-id
 * verification), and the not-found / mismatch null paths.
 */
class OrderLocatorTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private LoggerInterface&MockObject $logger;
    private OrderLocator $locator;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')
            ->willReturn($this->createMock(SearchCriteria::class));

        $this->locator = new OrderLocator(
            $this->orderRepository,
            $this->searchCriteriaBuilder,
            $this->logger,
        );
    }

    public function testByIncrementIdReturnsFirstMatch(): void
    {
        $order = $this->createMock(Order::class);
        $this->primeGetList([$order]);

        self::assertSame($order, $this->locator->byIncrementId('000000042'));
    }

    public function testByIncrementIdReturnsFirstWhenMultipleMatch(): void
    {
        $first = $this->createMock(Order::class);
        $second = $this->createMock(Order::class);
        $this->primeGetList([$first, $second]);

        self::assertSame(
            $first,
            $this->locator->byIncrementId('000000042'),
            'A multi-result list must collapse to the first row (setPageSize(1) intent).'
        );
    }

    public function testByIncrementIdReturnsNullWhenNoMatch(): void
    {
        $this->primeGetList([]);

        self::assertNull($this->locator->byIncrementId('000000042'));
    }

    public function testExtractIncrementIdStripsPrefix(): void
    {
        self::assertSame('000000042', $this->locator->extractIncrementId('duka_000000042_1700000000'));
    }

    public function testExtractIncrementIdFallsBackToRawValue(): void
    {
        // Legacy / non-conforming order_id — return verbatim, no regex match.
        self::assertSame('legacy-id', $this->locator->extractIncrementId('legacy-id'));
    }

    public function testByFlittOrderIdResolvesAndVerifiesStoredId(): void
    {
        $flittOrderId = 'duka_000000042_1700000000';
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $k): mixed => $k === 'flitt_order_id' ? $flittOrderId : null
        );
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $this->primeGetList([$order]);

        self::assertSame($order, $this->locator->byFlittOrderId($flittOrderId));
    }

    public function testByFlittOrderIdReturnsNullOnBadFormat(): void
    {
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('unrecognised Flitt order ID format'));

        // getList must NOT even be attempted for a malformed order_id.
        $this->orderRepository->expects(self::never())->method('getList');

        self::assertNull($this->locator->byFlittOrderId('not-a-flitt-id'));
    }

    public function testByFlittOrderIdReturnsNullWhenOrderMissing(): void
    {
        $this->primeGetList([]);

        self::assertNull($this->locator->byFlittOrderId('duka_000000042_1700000000'));
    }

    public function testByFlittOrderIdReturnsNullWhenStoredIdMismatches(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $k): mixed => $k === 'flitt_order_id' ? 'duka_999999999_1700000000' : null
        );
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIncrementId')->willReturn('000000042');
        $this->primeGetList([$order]);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('flitt_order_id mismatch'));

        self::assertNull(
            $this->locator->byFlittOrderId('duka_000000042_1700000000'),
            'A payment whose stored flitt_order_id differs must NOT resolve (cross-order guard).'
        );
    }

    public function testByFlittOrderIdReturnsNullWhenPaymentAbsent(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn(null);
        $this->primeGetList([$order]);

        self::assertNull($this->locator->byFlittOrderId('duka_000000042_1700000000'));
    }

    /**
     * @param list<Order&MockObject> $orders
     */
    private function primeGetList(array $orders): void
    {
        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn($orders);
        $this->orderRepository->method('getList')->willReturn($searchResult);
    }
}
