<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Controller\Adminhtml\Order\Capture;
use Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;
use Shubo\TbcPayment\Gateway\Http\Client\CaptureClient;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;
use Shubo\TbcPayment\Service\PaymentLock;

/**
 * IMPROVE-3 — admin Capture idempotency + concurrency hardening.
 *
 *  - A pre-API guard short-circuits on local capture_status === 'captured'
 *    (no API call, no second registerCaptureNotification).
 *  - The capture runs inside PaymentLock::withLock keyed on flitt_order_id and
 *    RE-checks the sentinel inside the lock (TOCTOU). Lock contention surfaces a
 *    retry message and touches nothing.
 *  - The legitimate FIRST capture calls registerCaptureNotification exactly once.
 *  - A benign "already captured" Flitt reply sets the sentinel + saves WITHOUT a
 *    second registerCaptureNotification (no duplicate-invoice exception).
 *  - A real failure is fail-closed: state + capture_status untouched, mapped
 *    retry copy.
 *
 * SIMPLIFY-5 — the controller hands order-level inputs (flitt_order_id, amount,
 * currency, store) to CaptureClient, which builds + signs internally.
 */
class CaptureTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private MessageManagerInterface&MockObject $messageManager;
    private RedirectFactory&MockObject $redirectFactory;
    private RedirectResult&MockObject $redirectResult;
    private HttpRequest&MockObject $request;
    private LoggerInterface&MockObject $logger;
    private Context&MockObject $context;
    private CaptureClient&MockObject $captureClient;
    private UserFacingErrorMapper&MockObject $errorMapper;
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $adapter;

    /** @var list<string> */
    private array $capturedErrors = [];

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
        $this->captureClient   = $this->createMock(CaptureClient::class);
        $this->errorMapper     = $this->createMock(UserFacingErrorMapper::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->adapter         = $this->createMock(AdapterInterface::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->adapter);

        $this->redirectResult->method('setPath')->willReturnSelf();
        $this->redirectFactory->method('create')->willReturn($this->redirectResult);
        $this->request->method('getParam')->willReturnCallback(static fn (string $k): mixed
            => $k === 'order_id' ? 42 : null);

        $this->messageManager->method('addErrorMessage')
            ->willReturnCallback(function (string $m): MessageManagerInterface {
                $this->capturedErrors[] = $m;
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

    /**
     * Make GET_LOCK return $granted ('1' = granted, '0' = contended). RELEASE_LOCK
     * is a no-op query.
     */
    private function lockGranting(bool $granted): PaymentLock
    {
        $this->adapter->method('fetchOne')->willReturn($granted ? '1' : '0');
        $this->adapter->method('query');
        return new PaymentLock($this->resourceConnection, $this->logger);
    }

    private function controller(PaymentLock $lock): Capture
    {
        return new Capture(
            $this->context,
            $this->orderRepository,
            $this->captureClient,
            $lock,
            $this->errorMapper,
            $this->logger,
        );
    }

    /**
     * @param string|null $captureStatus value of the capture_status info key
     */
    private function tbcPayment(?string $captureStatus = null): Payment&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('setAdditionalInformation')->willReturnSelf();
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $k): mixed => match ($k) {
                'flitt_order_id' => 'duka_000042_1234',
                'capture_status' => $captureStatus,
                default => null,
            }
        );
        return $payment;
    }

    private function tbcOrder(Payment $payment, float $grandTotal = 10.50): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getOrderCurrencyCode')->willReturn('GEL');
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('addCommentToStatusHistory')->willReturnSelf();
        return $order;
    }

    /**
     * Legitimate first capture: one client.capture(), one
     * registerCaptureNotification, order saved, success message.
     */
    public function testFirstCaptureRegistersExactlyOneCapture(): void
    {
        $payment = $this->tbcPayment();
        $payment->expects(self::once())->method('registerCaptureNotification')->with(10.50);

        $order = $this->tbcOrder($payment, 10.50);

        // get() is called pre-lock (execute) AND again inside the lock (doCapture
        // reloads for the fresh capture_status re-check).
        $this->orderRepository->expects(self::atLeastOnce())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        // SIMPLIFY-5: controller passes order-level inputs; client signs.
        $this->captureClient->expects(self::once())
            ->method('capture')
            ->with('duka_000042_1234', 1050, 'GEL', 1)
            ->willReturn(['response' => ['capture_status' => 'captured']]);

        $this->controller($this->lockGranting(true))->execute();

        self::assertEmpty($this->capturedErrors);
        self::assertNotEmpty($this->capturedSuccesses);
    }

    /**
     * Pre-API idempotency: when capture_status is already 'captured', NO API
     * call and NO registerCaptureNotification fire.
     */
    public function testAlreadyCapturedShortCircuitsBeforeApi(): void
    {
        $payment = $this->tbcPayment(captureStatus: 'captured');
        $payment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->tbcOrder($payment);

        // Pre-lock get short-circuits (capture_status already 'captured'), so the
        // body never runs and the in-lock reload never happens — exactly one get.
        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');
        $this->captureClient->expects(self::never())->method('capture');

        $this->controller($this->lockGranting(true))->execute();

        self::assertEmpty($this->capturedErrors);
        self::assertNotEmpty($this->capturedSuccesses);
    }

    /**
     * Lock contention (a concurrent click holds the lock): the body never runs,
     * no API call, no capture, no save — admin sees a retry error.
     */
    public function testLockContentionSkipsCaptureEntirely(): void
    {
        $payment = $this->tbcPayment();
        $payment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->tbcOrder($payment);

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');
        $this->captureClient->expects(self::never())->method('capture');

        $this->controller($this->lockGranting(false))->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('in progress', $this->capturedErrors[0]);
    }

    /**
     * Benign "already captured" Flitt reply (HTTP 2xx, non-success status whose
     * text mentions "already captured"): sentinel set + save, but NO second
     * registerCaptureNotification (invoice already exists → no duplicate-invoice
     * exception). Success-style copy.
     */
    public function testBenignAlreadyCapturedReplyDoesNotDoubleRegister(): void
    {
        $payment = $this->tbcPayment();
        $payment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->tbcOrder($payment);

        $this->orderRepository->method('get')->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->captureClient->expects(self::once())
            ->method('capture')
            ->willReturn([
                'response' => [
                    'capture_status' => 'failure',
                    'error_message' => 'Order already captured',
                ],
            ]);

        $this->controller($this->lockGranting(true))->execute();

        self::assertEmpty($this->capturedErrors);
        self::assertNotEmpty($this->capturedSuccesses);
    }

    /**
     * Benign "already captured" surfacing as a thrown FlittApiException whose
     * message mentions "already captured": same idempotent handling, no second
     * registerCaptureNotification.
     */
    public function testBenignAlreadyCapturedThrownExceptionDoesNotDoubleRegister(): void
    {
        $payment = $this->tbcPayment();
        $payment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->tbcOrder($payment);

        $this->orderRepository->method('get')->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->captureClient->expects(self::once())
            ->method('capture')
            ->willThrowException(new FlittApiException(__('Payment already captured')));

        $this->controller($this->lockGranting(true))->execute();

        self::assertEmpty($this->capturedErrors);
        self::assertNotEmpty($this->capturedSuccesses);
    }

    /**
     * Real failure (non-2xx that is NOT benign): fail-closed. No
     * registerCaptureNotification, no save (state + capture_status untouched),
     * mapped retry copy.
     */
    public function testRealFailureIsFailClosed(): void
    {
        $payment = $this->tbcPayment();
        $payment->expects(self::never())->method('registerCaptureNotification');
        // capture_status must NOT be promoted to captured on a real failure.
        $payment->expects(self::never())
            ->method('setAdditionalInformation')
            ->with('capture_status', 'captured');

        $order = $this->tbcOrder($payment);

        $this->orderRepository->method('get')->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $this->captureClient->expects(self::once())
            ->method('capture')
            ->willThrowException(new FlittApiException(__('Flitt API returned HTTP 500')));

        $this->errorMapper->method('toLocalizedException')
            ->willReturn(new \Magento\Framework\Exception\LocalizedException(__('Please try again.')));

        $this->controller($this->lockGranting(true))->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertEmpty($this->capturedSuccesses);
    }

    /**
     * A non-success body that is NOT "already captured" is a real failure too:
     * fail-closed, no capture registration, no save.
     */
    public function testNonSuccessBodyThatIsNotBenignFailsClosed(): void
    {
        $payment = $this->tbcPayment();
        $payment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->tbcOrder($payment);

        $this->orderRepository->method('get')->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $this->captureClient->expects(self::once())
            ->method('capture')
            ->willReturn([
                'response' => [
                    'capture_status' => 'declined',
                    'error_message' => 'Insufficient funds',
                    'error_code' => 2004,
                ],
            ]);

        $this->errorMapper->method('toLocalizedException')
            ->willReturn(new \Magento\Framework\Exception\LocalizedException(__('Bank declined.')));

        $this->controller($this->lockGranting(true))->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertEmpty($this->capturedSuccesses);
    }

    /**
     * Finding #2 — serialized two-click race. Click A captures, saves and
     * releases the lock; click B then acquires the lock. B's PRE-LOCK snapshot
     * still has an empty capture_status (it was loaded before A committed), but
     * the IN-LOCK reload returns the committed 'captured' state, so B
     * short-circuits: across the A→B pair there is exactly ONE
     * captureClient->capture() and ONE registerCaptureNotification.
     */
    public function testSerializedSecondClickReloadsCapturedAndDoesNotRecapture(): void
    {
        // Click A: fresh order, capture succeeds → exactly one capture + notify.
        $paymentA = $this->tbcPayment();
        $paymentA->expects(self::once())->method('registerCaptureNotification')->with(10.50);
        $orderA = $this->tbcOrder($paymentA, 10.50);

        // Click B: PRE-LOCK payment has empty capture_status, but the IN-LOCK
        // reloaded payment reports 'captured' (A committed it). B must NOT call
        // the API or registerCaptureNotification again.
        $paymentBPre = $this->tbcPayment();
        $orderBPre = $this->tbcOrder($paymentBPre, 10.50);
        $paymentBReloaded = $this->tbcPayment(captureStatus: 'captured');
        $paymentBReloaded->expects(self::never())->method('registerCaptureNotification');
        $orderBReloaded = $this->tbcOrder($paymentBReloaded, 10.50);

        // get() call sequence: A.pre, A.in-lock, B.pre, B.in-lock(reloaded).
        $this->orderRepository->method('get')->with(42)->willReturnOnConsecutiveCalls(
            $orderA,
            $orderA,
            $orderBPre,
            $orderBReloaded,
        );

        // Exactly ONE real capture across the pair (A only).
        $this->captureClient->expects(self::once())
            ->method('capture')
            ->with('duka_000042_1234', 1050, 'GEL', 1)
            ->willReturn(['response' => ['capture_status' => 'captured']]);

        // Both clicks share one granting lock (serialized, not contended).
        $lock = $this->lockGranting(true);
        $this->controller($lock)->execute();
        $this->controller($lock)->execute();

        self::assertEmpty($this->capturedErrors);
    }

    /** Missing flitt_order_id → LocalizedException surfaced, no API call. */
    public function testMissingFlittOrderIdIsRejected(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('getAdditionalInformation')->willReturn('');

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);

        $this->orderRepository->method('get')->willReturn($order);
        $this->captureClient->expects(self::never())->method('capture');

        $this->controller($this->lockGranting(true))->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('No Flitt order ID', $this->capturedErrors[0]);
    }
}
