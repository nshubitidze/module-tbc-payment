<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Controller\Payment\ReturnAction;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Http\Client\StatusClient;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Regression test for BUG-9: when Flitt reports 'processing' or 'created'
 * on the customer's redirect-return request, we previously sent the
 * customer to /checkout/onepage/success with a "being processed" notice.
 * That was misleading: if the bank later declines the authorization, the
 * customer already saw a success confirmation and expects goods. The
 * callback + cron reconciler can still flip the order in either
 * direction, so the customer must NOT be sent to the success page until
 * the status is terminal.
 *
 * New behaviour: pending statuses redirect to /checkout with a notice
 * that the bank is still processing, and no checkout-session success
 * data is stamped.
 */
class ReturnActionTest extends TestCase
{
    private HttpRequest&MockObject $request;
    private RedirectFactory&MockObject $redirectFactory;
    private RedirectResult&MockObject $redirectResult;
    private CheckoutSession&MockObject $checkoutSession;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private StatusClient&MockObject $statusClient;
    private CallbackValidator&MockObject $callbackValidator;
    private SettlementService&MockObject $settlementService;
    private MessageManagerInterface&MockObject $messageManager;
    private Config&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private ResourceConnection&MockObject $resourceConnection;

    /** @var list<string> */
    private array $redirectTargets = [];
    /** @var list<string> */
    private array $notices = [];

    protected function setUp(): void
    {
        $this->request              = $this->createMock(HttpRequest::class);
        $this->redirectFactory      = $this->createMock(RedirectFactory::class);
        $this->redirectResult       = $this->createMock(RedirectResult::class);
        $this->checkoutSession      = $this->createMock(CheckoutSession::class);
        $this->orderRepository      = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->statusClient         = $this->createMock(StatusClient::class);
        $this->callbackValidator    = $this->createMock(CallbackValidator::class);
        $this->settlementService    = $this->createMock(SettlementService::class);
        $this->messageManager       = $this->createMock(MessageManagerInterface::class);
        $this->config               = $this->createMock(Config::class);
        $this->logger               = $this->createMock(LoggerInterface::class);
        $this->resourceConnection   = $this->createMock(ResourceConnection::class);

        $this->redirectFactory->method('create')->willReturn($this->redirectResult);
        $this->redirectResult->method('setPath')->willReturnCallback(
            function (string $path): RedirectResult {
                $this->redirectTargets[] = $path;
                return $this->redirectResult;
            }
        );
        $this->messageManager->method('addNoticeMessage')
            ->willReturnCallback(function (string $m): MessageManagerInterface {
                $this->notices[] = $m;
                return $this->messageManager;
            });
        $this->messageManager->method('addErrorMessage')
            ->willReturnSelf();
    }

    public function testPendingStatusProcessingDoesNotRedirectToSuccess(): void
    {
        $this->primeFlittStatus('processing');
        $this->buildController()->execute();

        self::assertSame(['checkout'], $this->redirectTargets);
        self::assertNotEmpty($this->notices);
        self::assertStringContainsString('still being processed', $this->notices[0]);
    }

    public function testPendingStatusCreatedDoesNotRedirectToSuccess(): void
    {
        $this->primeFlittStatus('created');
        $this->buildController()->execute();

        self::assertSame(['checkout'], $this->redirectTargets);
        self::assertNotEmpty($this->notices);
    }

    public function testDeclinedStatusRedirectsToCheckoutViaHandleFailure(): void
    {
        // Baseline: declined should still cancel via handleFailure -> /checkout
        $this->primeFlittStatus('declined', cancelable: true);
        $this->buildController()->execute();

        self::assertSame(['checkout'], $this->redirectTargets);
    }

    /**
     * Regression: Flitt POSTs the `response_url` back when a customer returns
     * from the hosted payment page. The controller must accept POST or the
     * customer sees a 404 after a successful payment. Previously only
     * HttpGetActionInterface was declared → POST returned 404.
     */
    public function testControllerAcceptsPostForFlittResponseUrl(): void
    {
        self::assertInstanceOf(
            \Magento\Framework\App\Action\HttpPostActionInterface::class,
            $this->buildController(),
            'ReturnAction must accept POST — Flitt POSTs the response_url, a GET-only'
            . ' controller 404s after a successful payment.'
        );
        self::assertInstanceOf(
            \Magento\Framework\App\Action\HttpGetActionInterface::class,
            $this->buildController(),
            'ReturnAction must also accept GET for callers that issue browser'
            . ' navigation back to this URL.'
        );
    }

    private function primeFlittStatus(string $status, bool $cancelable = false): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $k, mixed $d = null): mixed
                => $k === 'order_id' ? 'duka_000000042_1234' : $d
        );

        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnMap([
            ['flitt_order_id', 'duka_000000042_1234'],
        ]);

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getEntityId')->willReturn(42);
        if ($cancelable) {
            $order->method('addCommentToStatusHistory')->willReturnSelf();
        }

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$order]);

        $searchCriteria = $this->createMock(SearchCriteria::class);
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);
        $this->orderRepository->method('getList')->willReturn($searchResult);

        $this->statusClient->method('checkStatus')
            ->willReturn(['response' => ['order_status' => $status]]);
    }

    private function buildController(): ReturnAction
    {
        return new ReturnAction(
            $this->request,
            $this->redirectFactory,
            $this->checkoutSession,
            $this->orderRepository,
            $this->searchCriteriaBuilder,
            $this->statusClient,
            $this->callbackValidator,
            $this->settlementService,
            $this->messageManager,
            $this->config,
            $this->logger,
            $this->resourceConnection,
        );
    }
}
