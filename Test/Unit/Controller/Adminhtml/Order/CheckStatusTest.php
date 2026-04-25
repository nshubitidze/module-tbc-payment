<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Controller\Adminhtml\Order\CheckStatus;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Http\Client\StatusClient;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Session 3 Pass 4 — reviewer-signoff §S-2 + §S-4 regressions for the
 * admin {@see CheckStatus} controller.
 *
 *   - S-2 (architect-scope §3.1.4): the controller's processApproval branch
 *     used to call `$payment->setParentTransactionId('{increment_id}-auth')`.
 *     The setter is gone; this test asserts it never reappears on either
 *     direct-sale or preauth branches.
 *   - S-4 (architect-scope §2.2.4): the catch-all error path used to leak
 *     `$e->getMessage()` straight to the admin UI. Pass 4 replaced that
 *     with a bland but safe friendly message; this test pins the new
 *     contract by asserting the captured admin-message text never contains
 *     the raw exception text.
 */
class CheckStatusTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private StatusClient&MockObject $statusClient;
    private CallbackValidator&MockObject $callbackValidator;
    private Config&MockObject $config;
    private SettlementService&MockObject $settlementService;
    private LoggerInterface&MockObject $logger;
    private Context&MockObject $context;
    private HttpRequest&MockObject $request;
    private RedirectFactory&MockObject $redirectFactory;
    private RedirectResult&MockObject $redirectResult;
    private MessageManagerInterface&MockObject $messageManager;
    private AuthorizationInterface&MockObject $authorization;

    /** @var list<string> */
    private array $errorMessages = [];

    /** @var list<string> */
    private array $successMessages = [];

    /** @var list<string> */
    private array $warningMessages = [];

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->statusClient = $this->createMock(StatusClient::class);
        $this->callbackValidator = $this->createMock(CallbackValidator::class);
        $this->config = $this->createMock(Config::class);
        $this->settlementService = $this->createMock(SettlementService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->request = $this->createMock(HttpRequest::class);
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $k): mixed => $k === 'order_id' ? 42 : null
        );

        $this->redirectResult = $this->createMock(RedirectResult::class);
        $this->redirectResult->method('setPath')->willReturnSelf();
        $this->redirectFactory = $this->createMock(RedirectFactory::class);
        $this->redirectFactory->method('create')->willReturn($this->redirectResult);

        $this->messageManager = $this->createMock(MessageManagerInterface::class);
        $this->messageManager->method('addErrorMessage')
            ->willReturnCallback(function (string $m): MessageManagerInterface {
                $this->errorMessages[] = $m;
                return $this->messageManager;
            });
        $this->messageManager->method('addSuccessMessage')
            ->willReturnCallback(function (string $m): MessageManagerInterface {
                $this->successMessages[] = $m;
                return $this->messageManager;
            });
        $this->messageManager->method('addWarningMessage')
            ->willReturnCallback(function (string $m): MessageManagerInterface {
                $this->warningMessages[] = $m;
                return $this->messageManager;
            });

        $this->authorization = $this->createMock(AuthorizationInterface::class);
        $this->authorization->method('isAllowed')->willReturn(true);

        $this->context = $this->createMock(Context::class);
        $this->context->method('getRequest')->willReturn($this->request);
        $this->context->method('getMessageManager')->willReturn($this->messageManager);
        $this->context->method('getResultRedirectFactory')->willReturn($this->redirectFactory);
        $this->context->method('getAuthorization')->willReturn($this->authorization);
    }

    /**
     * Architect-scope §3.1.4 / reviewer-signoff §S-2 — when Flitt reports
     * "approved" and CheckStatus drives the processApproval branch in
     * direct-sale mode, the dropped setParentTransactionId must never
     * reappear.
     */
    public function testProcessApprovalDirectSaleNeverCallsSetParentTransactionId(): void
    {
        [$order, $payment] = $this->makeApprovableOrder();
        $this->orderRepository->method('get')->with(42)->willReturn($order);

        $this->statusClient->method('checkStatus')->willReturn([
            'response' => [
                'order_status' => 'approved',
                'payment_id'   => 'pay-77',
                'amount'       => 5000,
                'masked_card'  => '444455XXXXXX5555',
            ],
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);
        $this->config->method('isPreauth')->willReturn(false);

        $payment->expects(self::never())->method('setParentTransactionId');

        $this->buildController()->execute();

        // Sanity: success messaging fired (we actually walked the approval
        // branch, the assertion is meaningful).
        self::assertNotEmpty($this->successMessages);
    }

    /**
     * Same regression but with isPreauth=true — the preauth branch in
     * processApproval also previously stored a phantom parent_txn_id and
     * must never reintroduce the setter.
     */
    public function testProcessApprovalPreauthNeverCallsSetParentTransactionId(): void
    {
        [$order, $payment] = $this->makeApprovableOrder();
        $this->orderRepository->method('get')->with(42)->willReturn($order);

        $this->statusClient->method('checkStatus')->willReturn([
            'response' => [
                'order_status' => 'approved',
                'payment_id'   => 'pay-78',
                'amount'       => 5000,
                'masked_card'  => '444455XXXXXX5555',
            ],
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);
        $this->config->method('isPreauth')->willReturn(true);

        $payment->expects(self::never())->method('setParentTransactionId');

        $this->buildController()->execute();

        self::assertNotEmpty($this->successMessages);
    }

    /**
     * Architect-scope §2.2.4 / reviewer-signoff §S-4 — when something
     * blows up inside execute(), the admin message MUST NOT include the
     * raw exception text. Pass 4 replaces the previous
     *   __('Status check failed: %1', $e->getMessage())
     * with a bland friendly copy. The raw triple still goes to the TBC
     * logger so ops can correlate.
     */
    public function testRawExceptionMessageIsNotLeakedToAdminUi(): void
    {
        $secret = 'INTERNAL: stack trace with /var/lib path and merchant_password=hunter2';
        $this->orderRepository->method('get')->with(42)
            ->willThrowException(new \RuntimeException($secret));

        // Logger MUST still see the raw message at error level (ops needs
        // it to debug). The test pins the log boundary too.
        $loggedRawMessage = false;
        $this->logger->expects(self::atLeastOnce())
            ->method('error')
            ->willReturnCallback(function (string $msg, array $ctx) use (&$loggedRawMessage, $secret): void {
                if (($ctx['error'] ?? '') === $secret) {
                    $loggedRawMessage = true;
                }
            });

        $this->buildController()->execute();

        self::assertTrue(
            $loggedRawMessage,
            'Logger must still receive the raw exception text for ops correlation.'
        );
        self::assertNotEmpty($this->errorMessages, 'Admin must see SOME error message.');
        foreach ($this->errorMessages as $msg) {
            self::assertStringNotContainsString(
                $secret,
                $msg,
                'Raw exception text leaked into admin-facing message: ' . $msg
            );
            self::assertStringNotContainsString(
                'merchant_password',
                $msg,
                'Sensitive fragment leaked into admin message.'
            );
            self::assertStringNotContainsString(
                'INTERNAL',
                $msg,
                'Internal-only fragment leaked into admin message.'
            );
        }
    }

    private function buildController(): CheckStatus
    {
        return new CheckStatus(
            $this->context,
            $this->orderRepository,
            $this->statusClient,
            $this->callbackValidator,
            $this->config,
            $this->settlementService,
            $this->logger,
        );
    }

    /**
     * @return array{0: Order&MockObject, 1: Payment&MockObject}
     */
    private function makeApprovableOrder(): array
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getMethod',
                'getAdditionalInformation',
                'setAdditionalInformation',
                'setTransactionId',
                'setParentTransactionId',
                'setIsTransactionPending',
                'setIsTransactionClosed',
                'registerCaptureNotification',
            ])
            ->getMock();
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $k): mixed
                => $k === 'flitt_order_id' ? 'duka_000000042_1700' : null
        );
        $payment->method('setAdditionalInformation')->willReturnSelf();
        $payment->method('setTransactionId')->willReturnSelf();
        $payment->method('setIsTransactionPending')->willReturnSelf();
        $payment->method('setIsTransactionClosed')->willReturnSelf();

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getPayment', 'getState', 'getStoreId', 'getIncrementId',
                'setState', 'setStatus', 'addCommentToStatusHistory',
                'getGrandTotal', 'cancel',
            ])
            ->getMock();
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getGrandTotal')->willReturn(50.00);
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        return [$order, $payment];
    }
}
