<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Controller\Payment;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Controller\Payment\Callback;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Model\FlittStatus;
use Shubo\TbcPayment\Model\OrderLocator;
use Shubo\TbcPayment\Service\ApprovalContext;
use Shubo\TbcPayment\Service\ApprovalResult;
use Shubo\TbcPayment\Service\OrderApprovalApplier;
use Shubo\TbcPayment\Service\PaymentLock;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * IMPROVE-2 / IMPROVE-8 / IMPROVE-9 coverage for the server-to-server
 * {@see Callback} controller — the previously-unlocked capture path.
 *
 * The capture body (re-read state, apply, save, commit) now runs inside a
 * {@see PaymentLock}. These tests pin:
 *   - approved → applier runs once and captures (HTTP 200 ok)
 *   - a second concurrent entry that sees state===PROCESSING does NOT re-capture
 *     (the applier returns AlreadyProcessed; registerCaptureNotification semantics
 *     are pinned in OrderApprovalApplierTest)
 *   - lock contention (withLock → null) → benign HTTP 200, applier never runs
 *   - amount mismatch (applier → RefusedAmountMismatch) → HTTP 400, rolled back
 *   - IP allowlist: allowed passes, disallowed → 403, empty → allow
 *   - replay: an exact payment_id replay is a benign HTTP 200 no-op
 */
class CallbackCaptureTest extends TestCase
{
    private HttpRequest&MockObject $request;
    private JsonFactory&MockObject $jsonFactory;
    private Json&MockObject $jsonSerializer;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private OrderLocator&MockObject $orderLocator;
    private CallbackValidator&MockObject $callbackValidator;
    private OrderApprovalApplier&MockObject $approvalApplier;
    private SettlementService&MockObject $settlementService;
    private LoggerInterface&MockObject $logger;
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private JsonResult&MockObject $jsonResult;
    private UserFacingErrorMapper&MockObject $userFacingErrorMapper;
    private PaymentLock&MockObject $paymentLock;
    private Config&MockObject $config;

    /** @var array<string, mixed> */
    private array $lastResultData = [];
    private ?int $lastHttpCode = null;

    protected function setUp(): void
    {
        $this->request              = $this->createMock(HttpRequest::class);
        $this->jsonFactory          = $this->createMock(JsonFactory::class);
        $this->jsonSerializer       = $this->createMock(Json::class);
        $this->orderRepository      = $this->createMock(OrderRepositoryInterface::class);
        $this->orderLocator         = $this->createMock(OrderLocator::class);
        $this->callbackValidator    = $this->createMock(CallbackValidator::class);
        $this->approvalApplier      = $this->createMock(OrderApprovalApplier::class);
        $this->settlementService    = $this->createMock(SettlementService::class);
        $this->logger               = $this->createMock(LoggerInterface::class);
        $this->resourceConnection   = $this->createMock(ResourceConnection::class);
        $this->connection           = $this->createMock(AdapterInterface::class);
        $this->jsonResult           = $this->createMock(JsonResult::class);
        $this->userFacingErrorMapper = $this->createMock(UserFacingErrorMapper::class);
        $this->paymentLock          = $this->createMock(PaymentLock::class);
        $this->config               = $this->createMock(Config::class);

        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->method('setData')->willReturnCallback(function (array $data): JsonResult {
            $this->lastResultData = $data;
            return $this->jsonResult;
        });
        $this->jsonResult->method('setHttpResponseCode')->willReturnCallback(function (int $code): JsonResult {
            $this->lastHttpCode = $code;
            return $this->jsonResult;
        });

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->callbackValidator->method('validate')->willReturn(true);

        // Default: empty allowlist → all IPs allowed (fail-open).
        $this->config->method('getCallbackIpAllowlist')->willReturn([]);

        // Default lock behaviour: acquired, runs the callable. Contention tests
        // override with willReturn(null).
        $this->paymentLock->method('withLock')->willReturnCallback(
            static fn (string $key, callable $cb): mixed => $cb()
        );
    }

    public function testApprovedCallbackCapturesAndReturns200(): void
    {
        $order = $this->primeApprovedCallback(state: Order::STATE_PENDING_PAYMENT);

        $this->approvalApplier->expects(self::once())
            ->method('apply')
            ->with($order, self::isType('array'), ApprovalContext::Callback)
            ->willReturn(ApprovalResult::Captured);

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())->method('commit');
        $this->connection->expects(self::never())->method('rollBack');

        // Direct-sale capture → settlement runs.
        $this->settlementService->expects(self::once())->method('settle')->with($order);

        $this->buildController()->execute();

        self::assertSame('ok', $this->lastResultData['status'] ?? null);
        self::assertSame(200, $this->lastHttpCode);
    }

    /**
     * IMPROVE-2: a second concurrent callback that sees state===PROCESSING inside
     * the lock must NOT re-capture. The applier short-circuits to
     * AlreadyProcessed and no settlement runs. (The "exactly once across the
     * pair" contract is pinned by combining this with
     * testApprovedCallbackCapturesAndReturns200.)
     */
    public function testSecondConcurrentCallbackSeesProcessingAndDoesNotRecapture(): void
    {
        $order = $this->primeApprovedCallback(state: Order::STATE_PROCESSING);

        // Applier is still called, but with an already-processing order it
        // returns AlreadyProcessed (idempotent no-op — registerCaptureNotification
        // never fires; that contract lives in OrderApprovalApplierTest).
        $this->approvalApplier->expects(self::once())
            ->method('apply')
            ->willReturn(ApprovalResult::AlreadyProcessed);

        $this->connection->expects(self::once())->method('commit');
        $this->connection->expects(self::never())->method('rollBack');

        // No capture → no settlement.
        $this->settlementService->expects(self::never())->method('settle');

        $this->buildController()->execute();

        self::assertSame('ok', $this->lastResultData['status'] ?? null);
        self::assertSame(200, $this->lastHttpCode);
    }

    /**
     * IMPROVE-2: lock contention → withLock returns null. The applier must NEVER
     * run, no transaction work happens, and we answer a benign HTTP 200 so Flitt
     * doesn't hammer retries (the other holder / a retry finalises).
     */
    public function testLockContentionReturnsBenign200AndSkipsCapture(): void
    {
        $this->primeApprovedCallback(state: Order::STATE_PENDING_PAYMENT);

        // Override the default: lock is contended.
        $contendedLock = $this->createMock(PaymentLock::class);
        $contendedLock->method('withLock')->willReturn(null);

        $this->approvalApplier->expects(self::never())->method('apply');
        $this->connection->expects(self::never())->method('beginTransaction');
        $this->settlementService->expects(self::never())->method('settle');

        $this->buildController($contendedLock)->execute();

        self::assertSame('deferred', $this->lastResultData['status'] ?? null);
        self::assertSame(200, $this->lastHttpCode);
    }

    /**
     * IMPROVE-8: an amount mismatch (applier → RefusedAmountMismatch) is a
     * do-not-retry situation → HTTP 400, transaction rolled back, no settlement.
     */
    public function testAmountMismatchRollsBackAndReturns400(): void
    {
        $this->primeApprovedCallback(state: Order::STATE_PENDING_PAYMENT);

        $this->approvalApplier->expects(self::once())
            ->method('apply')
            ->willReturn(ApprovalResult::RefusedAmountMismatch);

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())->method('rollBack');
        $this->connection->expects(self::never())->method('commit');
        $this->settlementService->expects(self::never())->method('settle');

        $this->buildController()->execute();

        self::assertSame('amount_mismatch', $this->lastResultData['status'] ?? null);
        self::assertSame(400, $this->lastHttpCode);
    }

    /**
     * IMPROVE-9a: a configured allowlist that includes the source IP passes.
     */
    public function testAllowlistAllowsListedSourceIp(): void
    {
        $allowConfig = $this->createMock(Config::class);
        $allowConfig->method('getCallbackIpAllowlist')->willReturn(['54.154.216.60', '3.75.125.89']);
        $this->request->method('getHeader')->willReturn('');
        $this->request->method('getClientIp')->willReturn('3.75.125.89');

        $order = $this->primeApprovedCallback(state: Order::STATE_PENDING_PAYMENT);
        $this->approvalApplier->expects(self::once())->method('apply')->willReturn(ApprovalResult::Captured);
        $this->settlementService->method('settle')->with($order);

        $this->buildController(null, $allowConfig)->execute();

        self::assertSame('ok', $this->lastResultData['status'] ?? null);
        self::assertSame(200, $this->lastHttpCode);
    }

    /**
     * IMPROVE-9a: a configured allowlist that excludes the source IP → HTTP 403,
     * and the body is never even parsed.
     */
    public function testAllowlistRejectsUnlistedSourceIpWith403(): void
    {
        $denyConfig = $this->createMock(Config::class);
        $denyConfig->method('getCallbackIpAllowlist')->willReturn(['54.154.216.60']);
        $this->request->method('getHeader')->willReturn('');
        $this->request->method('getClientIp')->willReturn('203.0.113.7');

        // Rejected before any body parse / order lookup / capture.
        $this->jsonSerializer->expects(self::never())->method('unserialize');
        $this->approvalApplier->expects(self::never())->method('apply');

        $this->buildController(null, $denyConfig)->execute();

        self::assertSame('error', $this->lastResultData['status'] ?? null);
        self::assertSame(403, $this->lastHttpCode);
    }

    /**
     * IMPROVE-9a: the forwarded header is honoured so the real Flitt egress IP
     * (behind a proxy) is matched, not the proxy's own address.
     */
    public function testAllowlistHonoursForwardedHeader(): void
    {
        $allowConfig = $this->createMock(Config::class);
        $allowConfig->method('getCallbackIpAllowlist')->willReturn(['54.154.216.60']);
        // Proxy chain: real client first, proxy hops after.
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $h): string => $h === 'X-Forwarded-For' ? '54.154.216.60, 10.0.0.1' : ''
        );
        $this->request->method('getClientIp')->willReturn('10.0.0.1');

        $this->primeApprovedCallback(state: Order::STATE_PENDING_PAYMENT);
        $this->approvalApplier->expects(self::once())->method('apply')->willReturn(ApprovalResult::Captured);

        $this->buildController(null, $allowConfig)->execute();

        self::assertSame('ok', $this->lastResultData['status'] ?? null);
        self::assertSame(200, $this->lastHttpCode);
    }

    /**
     * IMPROVE-9b: replaying the exact same approved payment_id is a benign no-op.
     * The first delivery captures; the second (same payment_id already recorded
     * on the payment) commits with NO applier call and answers HTTP 200.
     */
    public function testReplayedPaymentIdIsBenignNoOp(): void
    {
        // The payment already carries the processed payment_id from a prior delivery.
        $this->primeApprovedCallback(
            state: Order::STATE_PENDING_PAYMENT,
            processedPaymentId: 'pay-1',
        );

        // The applier must NOT run on a recognised replay.
        $this->approvalApplier->expects(self::never())->method('apply');
        $this->connection->expects(self::once())->method('commit');
        $this->connection->expects(self::never())->method('rollBack');
        $this->settlementService->expects(self::never())->method('settle');

        $this->buildController()->execute();

        self::assertSame('ok', $this->lastResultData['status'] ?? null);
        self::assertSame(200, $this->lastHttpCode);
    }

    /**
     * T-3: a forged / tampered callback whose signature fails validation must be
     * rejected with HTTP 403 BEFORE any order mutation or settlement. The applier
     * never runs, no transaction is opened, and the order is not saved. The
     * failure is logged at ERROR so a probing wave alerts.
     */
    public function testForgedSignatureRejectedWith403AndNoMutation(): void
    {
        $this->primeApprovedCallback(state: Order::STATE_PENDING_PAYMENT);

        // The signature check fails for this delivery.
        $forgedValidator = $this->createMock(CallbackValidator::class);
        $forgedValidator->method('validate')->willReturn(false);

        $this->logger->expects(self::atLeastOnce())
            ->method('error')
            ->with(
                'Flitt callback: signature validation failed',
                self::callback(static fn (array $ctx): bool => isset($ctx['order_id']))
            );

        // The lock body is never entered on a rejected signature.
        $untouchedLock = $this->createMock(PaymentLock::class);
        $untouchedLock->expects(self::never())->method('withLock');

        // No mutation, no settlement, no DB transaction, no save.
        $this->approvalApplier->expects(self::never())->method('apply');
        $this->settlementService->expects(self::never())->method('settle');
        $this->connection->expects(self::never())->method('beginTransaction');
        $this->orderRepository->expects(self::never())->method('save');

        $this->buildController($untouchedLock, null, $forgedValidator)->execute();

        self::assertSame('error', $this->lastResultData['status'] ?? null);
        self::assertSame(403, $this->lastHttpCode);
    }

    /**
     * Wire a canned approved callback. The order is resolved twice (pre-lock and
     * inside the lock) via OrderLocator::byIncrementId.
     */
    private function primeApprovedCallback(
        string $state,
        string $processedPaymentId = '',
    ): Order&MockObject {
        $callbackData = [
            'order_id'     => 'duka_000000042_1700',
            'order_status' => FlittStatus::APPROVED,
            'payment_id'   => 'pay-1',
            'amount'       => 5000,
        ];

        $rawBody = '{"canned":true}';
        $this->request->method('getContent')->willReturn($rawBody);
        $this->jsonSerializer->method('unserialize')->with($rawBody)->willReturn($callbackData);

        $payment = $this->createMock(Payment::class);
        $payment->method('setAdditionalInformation')->willReturnSelf();
        $payment->method('setTransactionId')->willReturnSelf();
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (?string $key = null): mixed
                => $key === 'flitt_processed_payment_id' && $processedPaymentId !== ''
                    ? $processedPaymentId
                    : null
        );

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn($state);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getGrandTotal')->willReturn(50.00);
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        $this->orderLocator->method('extractIncrementId')
            ->willReturnCallback(static function (string $flittOrderId): string {
                if (preg_match('/^duka_(.+)_\d+$/', $flittOrderId, $m)) {
                    return $m[1];
                }
                return $flittOrderId;
            });
        $this->orderLocator->method('byIncrementId')->willReturn($order);

        return $order;
    }

    private function buildController(
        ?PaymentLock $lock = null,
        ?Config $config = null,
        ?CallbackValidator $validator = null,
    ): Callback {
        return new Callback(
            $this->request,
            $this->jsonFactory,
            $this->jsonSerializer,
            $this->orderRepository,
            $this->orderLocator,
            $validator ?? $this->callbackValidator,
            $this->approvalApplier,
            $this->settlementService,
            $this->logger,
            $this->resourceConnection,
            $this->userFacingErrorMapper,
            $lock ?? $this->paymentLock,
            $config ?? $this->config,
        );
    }
}
