<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
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
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;
use Shubo\TbcPayment\Gateway\Http\Client\VoidClient;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;

/**
 * IMPROVE-7 — the admin "Void Payment" controller is POST-only, server-side
 * guarded (only an un-captured preauth order in PROCESSING is voidable), and
 * reverses the AUTHORIZED (held) amount, not a fresh grand_total*100. The Flitt
 * reverse call releases the pre-auth hold BEFORE the local cancel; on upstream
 * failure the local cancel still proceeds (soft-fail per CLAUDE.md §10).
 *
 * SIMPLIFY-5 — the wire-payload build + Flitt signature now live in VoidClient;
 * the controller hands order-level inputs (flitt_order_id, amount, currency,
 * store) to the client.
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

    private function controller(): VoidPayment
    {
        return new VoidPayment(
            $this->context,
            $this->orderRepository,
            $this->logger,
            $this->voidClient,
        );
    }

    /**
     * Build a voidable preauth payment (PROCESSING + preauth_approved + not
     * captured). $authorized lets a test simulate a recorded authorization
     * amount; 0.0 falls back to the order grand total.
     */
    private function voidablePayment(string $flittOrderId = 'duka_000042_1234', float $authorized = 0.0): Payment&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('getAmountAuthorized')->willReturn($authorized);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $k): mixed => match ($k) {
                'flitt_order_id' => $flittOrderId,
                'preauth_approved' => true,
                'capture_status' => null,
                default => null,
            }
        );
        return $payment;
    }

    private function voidableOrder(Payment $payment, float $grandTotal = 10.50): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getOrderCurrencyCode')->willReturn('GEL');
        $order->method('addCommentToStatusHistory')->willReturnSelf();
        return $order;
    }

    /** The controller must be POST-only so a void cannot be triggered by a GET. */
    public function testControllerIsPostOnly(): void
    {
        self::assertInstanceOf(HttpPostActionInterface::class, $this->controller());
    }

    /**
     * Voidable preauth → reverse releases the AUTHORIZED amount (grand-total
     * fallback here = 1050 tetri), and the order is cancelled.
     */
    public function testVoidableOrderReversesAuthorizedAmount(): void
    {
        $payment = $this->voidablePayment();
        $order = $this->voidableOrder($payment, 10.50);
        $order->expects(self::once())->method('cancel')->willReturnSelf();

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->voidClient->expects(self::once())
            ->method('reverse')
            ->with('duka_000042_1234', 1050, 'GEL', 1)
            ->willReturn(['response' => ['reverse_status' => 'approved']]);

        $this->controller()->execute();

        self::assertEmpty($this->capturedErrors, 'No error expected on the happy void path.');
        self::assertNotEmpty($this->capturedSuccesses);
    }

    /**
     * When the payment carries a distinct recorded authorization amount, the
     * reverse uses THAT (in tetri), not the order grand total.
     */
    public function testReverseUsesRecordedAuthorizedAmountOverGrandTotal(): void
    {
        // Authorization recorded as 8.00; grand total is 10.50. Reverse must
        // release 800 tetri (the held authorization), not 1050.
        $payment = $this->voidablePayment(authorized: 8.00);
        $order = $this->voidableOrder($payment, 10.50);
        $order->method('cancel')->willReturnSelf();

        $this->orderRepository->method('get')->willReturn($order);

        $this->voidClient->expects(self::once())
            ->method('reverse')
            ->with('duka_000042_1234', 800, 'GEL', 1)
            ->willReturn(['response' => ['reverse_status' => 'approved']]);

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedSuccesses);
    }

    /**
     * Already-captured order → guard blocks, NO reverse attempted, NO cancel,
     * error surfaced.
     */
    public function testCapturedOrderIsRejectedWithoutReverse(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $k): mixed => match ($k) {
                'flitt_order_id' => 'duka_000042_1234',
                'preauth_approved' => true,
                'capture_status' => 'captured',
                default => null,
            }
        );

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->expects(self::never())->method('cancel');

        $this->orderRepository->method('get')->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');
        $this->voidClient->expects(self::never())->method('reverse');

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedErrors, 'A captured order must be rejected.');
        self::assertStringContainsString('cannot be voided', $this->capturedErrors[0]);
    }

    /**
     * Non-preauth order (no held funds) → guard blocks, no reverse, no cancel.
     */
    public function testNonPreauthOrderIsRejected(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $k): mixed => $k === 'flitt_order_id' ? 'duka_000042_1234' : null
        );

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->expects(self::never())->method('cancel');

        $this->orderRepository->method('get')->willReturn($order);
        $this->voidClient->expects(self::never())->method('reverse');

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedErrors);
    }

    /**
     * Not-in-processing order (e.g. already complete/closed) → guard blocks.
     */
    public function testNonProcessingOrderIsRejected(): void
    {
        $payment = $this->voidablePayment();

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_COMPLETE);
        $order->expects(self::never())->method('cancel');

        $this->orderRepository->method('get')->willReturn($order);
        $this->voidClient->expects(self::never())->method('reverse');

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedErrors);
    }

    /** Wrong payment method → rejected, no reverse. */
    public function testWrongPaymentMethodIsRejected(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('checkmo');

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);

        $this->orderRepository->method('get')->willReturn($order);
        $this->voidClient->expects(self::never())->method('reverse');

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedErrors);
    }

    /** Reverse API throws → local cancel still proceeds, warning surfaced. */
    public function testOrderStillCancelledWhenReverseApiThrows(): void
    {
        $payment = $this->voidablePayment();
        $order = $this->voidableOrder($payment);
        $order->expects(self::once())->method('cancel')->willReturnSelf();

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->voidClient->expects(self::once())
            ->method('reverse')
            ->willThrowException(new FlittApiException(__('Flitt API returned HTTP 500')));

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedWarnings, 'Warning expected when reverse fails.');
        self::assertStringContainsString('hold could not be released', $this->capturedWarnings[0]);
    }

    /** reverse_status copied to payment additional info on success. */
    public function testReverseStatusCopiedToPaymentAdditionalInfo(): void
    {
        $captured = [];
        $payment = $this->voidablePayment();
        $payment->method('setAdditionalInformation')->willReturnCallback(
            function (string $k, mixed $v) use (&$captured, $payment): Payment {
                $captured[$k] = $v;
                return $payment;
            }
        );

        $order = $this->voidableOrder($payment);
        $order->method('cancel')->willReturnSelf();

        $this->orderRepository->method('get')->willReturn($order);

        $this->voidClient->method('reverse')
            ->willReturn(['response' => ['reverse_status' => 'approved']]);

        $this->controller()->execute();

        self::assertArrayHasKey('reverse_status', $captured);
        self::assertSame('approved', $captured['reverse_status']);
    }

    /** Declined reverse_status still cancels locally with a warning. */
    public function testReverseDeclinedStillCancelsLocally(): void
    {
        $payment = $this->voidablePayment();
        $order = $this->voidableOrder($payment);
        $order->expects(self::once())->method('cancel')->willReturnSelf();

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->voidClient->expects(self::once())
            ->method('reverse')
            ->willReturn([
                'response' => [
                    'reverse_status' => 'declined',
                    'error_message' => 'Already reversed',
                ],
            ]);

        $this->controller()->execute();

        self::assertNotEmpty(
            $this->capturedWarnings,
            'A warning is expected when reverse_status is not approved/success.'
        );
    }
}
