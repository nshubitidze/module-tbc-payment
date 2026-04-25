<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Controller\Adminhtml\Order\VoidPayment;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;
use Shubo\TbcPayment\Gateway\Http\Client\VoidClient;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;

/**
 * BUG-5: The admin "Void Payment" button MUST call the Flitt reverse API
 * to release the pre-authorization hold BEFORE cancelling the Magento order.
 *
 * Soft-fail policy (CLAUDE.md §10): if the upstream reverse call fails, we
 * still cancel locally and surface a warning — the local cancel is the
 * contract, the reversal is cleanup.
 */
class VoidPaymentTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private MessageManagerInterface&MockObject $messageManager;
    private RedirectFactory&MockObject $redirectFactory;
    private RedirectResult&MockObject $redirectResult;
    private HttpRequest&MockObject $request;
    private LoggerInterface&MockObject $logger;
    private Context&MockObject $context;
    private VoidClient&MockObject $voidClient;
    private Config&MockObject $config;

    /** @var list<string> */
    private array $capturedErrors = [];

    /** @var list<string> */
    private array $capturedWarnings = [];

    /** @var list<string> */
    private array $capturedSuccesses = [];

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->messageManager  = $this->createMock(MessageManagerInterface::class);
        $this->redirectResult  = $this->createMock(RedirectResult::class);
        $this->redirectFactory = $this->createMock(RedirectFactory::class);
        $this->request         = $this->createMock(HttpRequest::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->voidClient      = $this->createMock(VoidClient::class);
        $this->config          = $this->createMock(Config::class);

        $this->redirectResult->method('setPath')->willReturnSelf();
        $this->redirectFactory->method('create')->willReturn($this->redirectResult);
        $this->request->method('getParam')->willReturnCallback(static fn (string $k): mixed
            => $k === 'order_id' ? 42 : null);

        $this->messageManager->method('addErrorMessage')
            ->willReturnCallback(function (string $m): MessageManagerInterface {
                $this->capturedErrors[] = $m;
                return $this->messageManager;
            });
        $this->messageManager->method('addWarningMessage')
            ->willReturnCallback(function (string $m): MessageManagerInterface {
                $this->capturedWarnings[] = $m;
                return $this->messageManager;
            });
        $this->messageManager->method('addSuccessMessage')
            ->willReturnCallback(function (string $m): MessageManagerInterface {
                $this->capturedSuccesses[] = $m;
                return $this->messageManager;
            });

        $this->context = $this->createMock(Context::class);
        $this->context->method('getRequest')->willReturn($this->request);
        $this->context->method('getMessageManager')->willReturn($this->messageManager);
        $this->context->method('getResultRedirectFactory')->willReturn($this->redirectFactory);
    }

    public function testReverseApiCalledWithFlittOrderIdAndSignedParams(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $k): mixed
                => $k === 'flitt_order_id' ? 'duka_000042_1234' : null
        );

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(10.50);
        $order->method('getOrderCurrencyCode')->willReturn('GEL');
        $order->expects(self::once())->method('cancel')->willReturnSelf();
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->config->method('getMerchantId')->with(1)->willReturn('1549901');
        $this->config->method('getPassword')->with(1)->willReturn('test_secret');

        $expectedSignature = Config::generateSignature(
            [
                'order_id' => 'duka_000042_1234',
                'merchant_id' => '1549901',
                'amount' => '1050',
                'currency' => 'GEL',
            ],
            'test_secret',
        );

        $this->voidClient->expects(self::once())
            ->method('reverse')
            ->with(
                self::callback(static function (array $params) use ($expectedSignature): bool {
                    return $params['order_id'] === 'duka_000042_1234'
                        && $params['merchant_id'] === '1549901'
                        && $params['amount'] === '1050'
                        && $params['currency'] === 'GEL'
                        && isset($params['signature'])
                        && strlen((string) $params['signature']) === 40
                        && $params['signature'] === $expectedSignature;
                }),
                1,
            )
            ->willReturn(['response' => ['reverse_status' => 'approved', 'reverse_amount' => 1050]]);

        $controller = new VoidPayment(
            $this->context,
            $this->orderRepository,
            $this->logger,
            $this->voidClient,
            $this->config,
        );

        $controller->execute();

        self::assertEmpty($this->capturedErrors, 'No error messages expected on happy path.');
        self::assertNotEmpty($this->capturedSuccesses, 'Success message expected.');
    }

    public function testReverseSkippedWhenNoFlittOrderId(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('getAdditionalInformation')->willReturn('');

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->expects(self::once())->method('cancel')->willReturnSelf();
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        // Reverse MUST NOT be called when there is no flitt_order_id.
        $this->voidClient->expects(self::never())->method('reverse');

        $controller = new VoidPayment(
            $this->context,
            $this->orderRepository,
            $this->logger,
            $this->voidClient,
            $this->config,
        );

        $controller->execute();

        self::assertEmpty($this->capturedErrors, 'Skipping reverse is normal, no error expected.');
        self::assertNotEmpty($this->capturedSuccesses);
    }

    public function testOrderStillCancelledWhenReverseApiThrows(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $k): mixed
                => $k === 'flitt_order_id' ? 'duka_000042_1234' : null
        );

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(10.50);
        $order->method('getOrderCurrencyCode')->willReturn('GEL');
        // Local cancel MUST still happen even when reverse fails.
        $order->expects(self::once())->method('cancel')->willReturnSelf();
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->config->method('getMerchantId')->willReturn('1549901');
        $this->config->method('getPassword')->willReturn('test_secret');

        $this->voidClient->expects(self::once())
            ->method('reverse')
            ->willThrowException(new FlittApiException(__('Flitt void API returned HTTP 500')));

        $controller = new VoidPayment(
            $this->context,
            $this->orderRepository,
            $this->logger,
            $this->voidClient,
            $this->config,
        );

        $controller->execute();

        self::assertNotEmpty(
            $this->capturedWarnings,
            'Warning message expected when reverse call fails.'
        );
        self::assertStringContainsString(
            'hold could not be released',
            $this->capturedWarnings[0],
            'Warning should explain the pre-auth hold release failure.'
        );
    }

    public function testReverseStatusCopiedToPaymentAdditionalInfo(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $k): mixed
                => $k === 'flitt_order_id' ? 'duka_000042_1234' : null
        );

        // Capture every setAdditionalInformation call so we can assert reverse_status was set.
        /** @var array<string, mixed> $captured */
        $captured = [];
        $payment->method('setAdditionalInformation')->willReturnCallback(
            function (string $k, mixed $v) use (&$captured, $payment): Payment {
                $captured[$k] = $v;
                return $payment;
            }
        );

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(10.50);
        $order->method('getOrderCurrencyCode')->willReturn('GEL');
        $order->method('cancel')->willReturnSelf();
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        $this->orderRepository->method('get')->willReturn($order);

        $this->config->method('getMerchantId')->willReturn('1549901');
        $this->config->method('getPassword')->willReturn('test_secret');

        $this->voidClient->method('reverse')
            ->willReturn(['response' => ['reverse_status' => 'approved']]);

        $controller = new VoidPayment(
            $this->context,
            $this->orderRepository,
            $this->logger,
            $this->voidClient,
            $this->config,
        );

        $controller->execute();

        self::assertArrayHasKey('reverse_status', $captured);
        self::assertSame('approved', $captured['reverse_status']);
    }

    public function testReverseDeclinedStillCancelsLocally(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $k): mixed
                => $k === 'flitt_order_id' ? 'duka_000042_1234' : null
        );

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(10.50);
        $order->method('getOrderCurrencyCode')->willReturn('GEL');
        $order->expects(self::once())->method('cancel')->willReturnSelf();
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->config->method('getMerchantId')->willReturn('1549901');
        $this->config->method('getPassword')->willReturn('test_secret');

        $this->voidClient->expects(self::once())
            ->method('reverse')
            ->willReturn([
                'response' => [
                    'reverse_status' => 'declined',
                    'error_message' => 'Already reversed',
                ],
            ]);

        $controller = new VoidPayment(
            $this->context,
            $this->orderRepository,
            $this->logger,
            $this->voidClient,
            $this->config,
        );

        $controller->execute();

        self::assertNotEmpty(
            $this->capturedWarnings,
            'A warning message is expected when reverse_status is not approved/success.'
        );
    }
}
