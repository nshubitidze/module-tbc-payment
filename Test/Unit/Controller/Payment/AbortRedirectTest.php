<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Controller\Payment\AbortRedirect;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;

/**
 * Regression tests for BUG-14: the AbortRedirect controller must cancel a
 * just-placed but unpaid shubo_tbc order when the redirect init fails on
 * the client. It must refuse to touch orders that:
 *   - do not exist,
 *   - are not in pending_payment state,
 *   - already have invoices,
 *   - belong to a different payment method.
 *
 * These guards prevent misuse as a generic order-cancel backdoor.
 */
class AbortRedirectTest extends TestCase
{
    private JsonFactory&MockObject $jsonFactory;
    private HttpRequest&MockObject $request;
    private CheckoutSession&MockObject $checkoutSession;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private LoggerInterface&MockObject $logger;
    private JsonResult&MockObject $jsonResult;

    /** @var array<string, mixed> */
    private array $lastResultData = [];

    protected function setUp(): void
    {
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->request = $this->createMock(HttpRequest::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->jsonResult = $this->createMock(JsonResult::class);

        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->method('setData')->willReturnCallback(function ($data) {
            $this->lastResultData = $data;
            return $this->jsonResult;
        });
    }

    public function testHappyPathCancelsPendingPaymentShuboTbcOrder(): void
    {
        $order = $this->makeOrder(
            state: Order::STATE_PENDING_PAYMENT,
            method: ConfigProvider::CODE,
            hasInvoices: false,
        );
        $order->expects(self::once())->method('cancel');
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->primeOrderLookup('000000042', $order);
        $this->request->method('getParam')->willReturnCallback(static function (string $key) {
            return $key === 'increment_id' ? '000000042' : null;
        });

        $this->buildController()->execute();

        self::assertTrue($this->lastResultData['success']);
        self::assertSame('000000042', $this->lastResultData['increment_id']);
    }

    public function testRefusesWrongPaymentMethod(): void
    {
        $order = $this->makeOrder(
            state: Order::STATE_PENDING_PAYMENT,
            method: 'checkmo', // <-- different method
            hasInvoices: false,
        );
        $order->expects(self::never())->method('cancel');
        $this->orderRepository->expects(self::never())->method('save');

        $this->primeOrderLookup('000000042', $order);
        $this->request->method('getParam')->willReturnCallback(static function (string $key) {
            return $key === 'increment_id' ? '000000042' : null;
        });

        $this->buildController()->execute();

        self::assertFalse($this->lastResultData['success']);
    }

    public function testRefusesOrderNotInPendingPayment(): void
    {
        $order = $this->makeOrder(
            state: Order::STATE_PROCESSING, // <-- already advanced
            method: ConfigProvider::CODE,
            hasInvoices: false,
        );
        $order->expects(self::never())->method('cancel');
        $this->orderRepository->expects(self::never())->method('save');

        $this->primeOrderLookup('000000042', $order);
        $this->request->method('getParam')->willReturnCallback(static function (string $key) {
            return $key === 'increment_id' ? '000000042' : null;
        });

        $this->buildController()->execute();

        self::assertFalse($this->lastResultData['success']);
    }

    public function testRefusesOrderWithInvoices(): void
    {
        $order = $this->makeOrder(
            state: Order::STATE_PENDING_PAYMENT,
            method: ConfigProvider::CODE,
            hasInvoices: true,
        );
        $order->expects(self::never())->method('cancel');
        $this->orderRepository->expects(self::never())->method('save');

        $this->primeOrderLookup('000000042', $order);
        $this->request->method('getParam')->willReturnCallback(static function (string $key) {
            return $key === 'increment_id' ? '000000042' : null;
        });

        $this->buildController()->execute();

        self::assertFalse($this->lastResultData['success']);
    }

    public function testReturnsErrorWhenOrderNotFound(): void
    {
        $this->primeOrderLookup('000000042', null);
        $this->request->method('getParam')->willReturnCallback(static function (string $key) {
            return $key === 'increment_id' ? '000000042' : null;
        });

        $this->buildController()->execute();

        self::assertFalse($this->lastResultData['success']);
    }

    public function testReturnsErrorOnMissingIncrementId(): void
    {
        $this->request->method('getParam')->willReturn('');

        $this->orderRepository->expects(self::never())->method('getList');
        $this->orderRepository->expects(self::never())->method('save');

        $this->buildController()->execute();

        self::assertFalse($this->lastResultData['success']);
    }

    /**
     * Controller under test, wired with all mocked dependencies.
     */
    private function buildController(): AbortRedirect
    {
        return new AbortRedirect(
            jsonFactory: $this->jsonFactory,
            request: $this->request,
            checkoutSession: $this->checkoutSession,
            orderRepository: $this->orderRepository,
            searchCriteriaBuilder: $this->searchCriteriaBuilder,
            logger: $this->logger,
        );
    }

    /**
     * Build an Order mock with the given state + payment method + invoice
     * presence so each test can tune just the attribute it cares about.
     */
    private function makeOrder(
        string $state,
        string $method,
        bool $hasInvoices,
    ): Order&MockObject {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMethod'])
            ->getMock();
        $payment->method('getMethod')->willReturn($method);

        $invoiceCollection = $this->getMockBuilder(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSize'])
            ->getMock();
        $invoiceCollection->method('getSize')->willReturn($hasInvoices ? 1 : 0);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getEntityId', 'getIncrementId', 'getState', 'getPayment',
                'cancel', 'addCommentToStatusHistory', 'getInvoiceCollection',
            ])
            ->getMock();
        $order->method('getEntityId')->willReturn(11);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getState')->willReturn($state);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getInvoiceCollection')->willReturn($invoiceCollection);
        $order->method('cancel')->willReturnSelf();
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        return $order;
    }

    /**
     * Prime the repository to return (or not) a given order for a given
     * increment_id lookup.
     */
    private function primeOrderLookup(string $incrementId, ?Order $order): void
    {
        $searchCriteria = $this->createMock(SearchCriteria::class);
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn($order === null ? [] : [$order]);
        $this->orderRepository->method('getList')->willReturn($searchResult);
    }
}
