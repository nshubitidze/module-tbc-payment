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
use Shubo\TbcPayment\Service\ApprovalContext;
use Shubo\TbcPayment\Service\ApprovalResult;
use Shubo\TbcPayment\Service\OrderApprovalApplier;
use Shubo\TbcPayment\Service\PaymentLock;
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
    private OrderApprovalApplier&MockObject $approvalApplier;
    private SettlementService&MockObject $settlementService;
    private Config&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private ResourceConnection&MockObject $resourceConnection;
    private AppState&MockObject $appState;
    private AdapterInterface&MockObject $adapter;
    private PaymentLock&MockObject $paymentLock;

    private int $reconcileMaxAttempts = 12;
    private int $backlogAlertThreshold = 50;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->sortOrderBuilder = $this->createMock(SortOrderBuilder::class);
        $this->statusClient = $this->createMock(StatusClient::class);
        $this->callbackValidator = $this->createMock(CallbackValidator::class);
        $this->approvalApplier = $this->createMock(OrderApprovalApplier::class);
        $this->settlementService = $this->createMock(SettlementService::class);
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->appState = $this->createMock(AppState::class);
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->paymentLock = $this->createMock(PaymentLock::class);

        // Default: the lock is acquired and runs the wrapped callable.
        $this->paymentLock->method('withLock')->willReturnCallback(
            static fn (string $key, callable $cb): mixed => $cb()
        );

        $this->resourceConnection->method('getConnection')->willReturn($this->adapter);

        $this->sortOrderBuilder->method('setField')->willReturnSelf();
        $this->sortOrderBuilder->method('setAscendingDirection')->willReturnSelf();
        $this->sortOrderBuilder->method('create')->willReturn($this->createMock(SortOrder::class));

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setSortOrders')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $this->config->method('getPaymentLifetime')->willReturn(3600);
        // IMPROVE-5/6: read from mutable properties so individual tests can
        // tune the cap / threshold without re-stubbing (PHPUnit forbids
        // re-configuring an already-stubbed method). Defaults keep existing
        // tests non-terminal under the new recordAttempt path.
        $this->config->method('getReconcileMaxAttempts')
            ->willReturnCallback(fn (): int => $this->reconcileMaxAttempts);
        $this->config->method('getBacklogAlertThreshold')
            ->willReturnCallback(fn (): int => $this->backlogAlertThreshold);

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

        // StatusClient::checkStatus returns the already-unwrapped `response` payload.
        $this->statusClient->method('checkStatus')
            ->with('duka_000000055_1700000000', 1)
            ->willReturn([
                'response_status' => 'failure',
                'error_code'      => 1011,
                'error_message'   => 'Order not found',
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

        // IMPROVE-5: two saves now — recordAttempt persists the bumped
        // reconcile_attempts counter, then handleOrderNotFound persists the
        // cancel. Both target the same order.
        $this->orderRepository->expects(self::exactly(2))->method('save')->with($order);

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
        [$order] = $this->primeOrder(
            flittOrderId: 'duka_000000055_1700000000',
            createdAt: $createdAtFresh,
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'response_status' => 'failure',
            'error_code'      => 1011,
            'error_message'   => 'Order not found',
        ]);

        $this->callbackValidator->expects(self::never())->method('validate');
        $order->expects(self::never())->method('cancel');
        $order->expects(self::never())->method('addCommentToStatusHistory');
        // IMPROVE-5: the order is kept (not terminal), but recordAttempt now
        // persists the bumped reconcile_attempts counter — exactly one save,
        // no transaction (the counter save is a plain repository write).
        $this->orderRepository->expects(self::once())->method('save')->with($order);
        $this->adapter->expects(self::never())->method('beginTransaction');
        $this->adapter->expects(self::never())->method('commit');

        // The not-found-within-lifetime branch never reaches handleApproved, so
        // the shared approve applier must not run on this code path.
        $this->approvalApplier->expects(self::never())->method('apply');

        $this->makeReconciler()->execute();
    }

    /**
     * Direct-sale approval: the reconciler delegates the shared mutation to the
     * applier with the Reconciler context, persists, and (because it is NOT
     * preauth) runs settlement. The preauth-vs-direct capture decision and the
     * dropped-setParentTransactionId regression now live in OrderApprovalApplierTest.
     */
    public function testApprovedDirectSaleDelegatesToApplierAndSettles(): void
    {
        [$order] = $this->primeOrder(
            flittOrderId: 'duka_000000055_1700000000',
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
            grandTotal: 50.00,
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status'    => 'approved',
            'response_status' => 'success',
            'payment_id'      => 'pay-77',
            'amount'          => 5000,
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);

        // Direct-sale branch.
        $this->config->method('isPreauth')->willReturn(false);

        $this->approvalApplier->expects(self::once())
            ->method('apply')
            ->with($order, self::isType('array'), ApprovalContext::Reconciler)
            ->willReturn(ApprovalResult::Captured);

        // Direct-sale path settles after capture.
        $this->settlementService->expects(self::once())->method('settle')->with($order);

        $this->makeReconciler()->execute();
    }

    /**
     * Preauth approval: still delegates to the applier with the Reconciler
     * context, but funds are only held — so the reconciler must NOT settle.
     */
    public function testApprovedPreauthDelegatesToApplierAndDoesNotSettle(): void
    {
        [$order] = $this->primeOrder(
            flittOrderId: 'duka_000000055_1700000000',
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
            grandTotal: 50.00,
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status'    => 'approved',
            'response_status' => 'success',
            'payment_id'      => 'pay-78',
            'amount'          => 5000,
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);

        // Preauth branch.
        $this->config->method('isPreauth')->willReturn(true);

        $this->approvalApplier->expects(self::once())
            ->method('apply')
            ->with($order, self::isType('array'), ApprovalContext::Reconciler)
            ->willReturn(ApprovalResult::PreauthHeld);

        // Preauth holds funds only — no settlement.
        $this->settlementService->expects(self::never())->method('settle');

        $this->makeReconciler()->execute();
    }

    /**
     * IMPROVE-2: when a concurrent capture path already moved the order to
     * PROCESSING by the time the reconciler acquires the lock, the reconciler
     * must NOT re-capture — the locked re-check short-circuits before the
     * applier runs. This is the previously-unlocked path that could
     * double-credit the Payout ledger.
     */
    public function testApprovedOrderAlreadyProcessingInsideLockIsNotRecaptured(): void
    {
        // A concurrent path won: the order is already PROCESSING.
        [$order] = $this->primeOrder(
            flittOrderId: 'duka_000000055_1700000000',
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
            grandTotal: 50.00,
            state: Order::STATE_PROCESSING,
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status'    => 'approved',
            'response_status' => 'success',
            'payment_id'      => 'pay-77',
            'amount'          => 5000,
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);

        // CRITICAL: the locked re-check sees PROCESSING and skips — the applier
        // never runs, so registerCaptureNotification cannot fire a second time.
        $this->approvalApplier->expects(self::never())->method('apply');
        $this->settlementService->expects(self::never())->method('settle');
        // The skip happens before any transaction is opened for the capture.
        $this->adapter->expects(self::never())->method('beginTransaction');

        $this->makeReconciler()->execute();
    }

    /**
     * IMPROVE-2: lock contention (withLock → null) → the reconciler skips this
     * order entirely. No transaction, no applier, no settlement; the next tick
     * sees it already processing and short-circuits.
     */
    public function testLockContentionSkipsOrderWithoutCapturing(): void
    {
        [$order] = $this->primeOrder(
            flittOrderId: 'duka_000000055_1700000000',
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
            grandTotal: 50.00,
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status'    => 'approved',
            'response_status' => 'success',
            'payment_id'      => 'pay-77',
            'amount'          => 5000,
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);

        // Contended lock: withLock never runs the callable.
        $contendedLock = $this->createMock(PaymentLock::class);
        $contendedLock->method('withLock')->willReturn(null);
        $this->paymentLock = $contendedLock;

        $this->approvalApplier->expects(self::never())->method('apply');
        $this->settlementService->expects(self::never())->method('settle');
        $this->adapter->expects(self::never())->method('beginTransaction');

        $this->makeReconciler()->execute();
    }

    /**
     * IMPROVE-8: the reconciler honours a refused capture (amount mismatch). The
     * applier returns RefusedAmountMismatch (mutating nothing); the reconciler
     * must NOT persist and must NOT settle — the order is left for admin reconcile.
     */
    public function testApprovedAmountMismatchLeavesOrderForAdminReconcile(): void
    {
        [$order] = $this->primeOrder(
            flittOrderId: 'duka_000000055_1700000000',
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
            grandTotal: 50.00,
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status'    => 'approved',
            'response_status' => 'success',
            'payment_id'      => 'pay-99',
            'amount'          => 9999,
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);
        $this->config->method('isPreauth')->willReturn(false);

        $this->approvalApplier->expects(self::once())
            ->method('apply')
            ->with($order, self::isType('array'), ApprovalContext::Reconciler)
            ->willReturn(ApprovalResult::RefusedAmountMismatch);

        // Refused capture → applier mutates nothing and the refused branch does
        // not persist the order. The ONLY save is recordAttempt persisting the
        // bumped reconcile_attempts counter (durable across ticks); settlement
        // is never triggered.
        $this->orderRepository->expects(self::once())->method('save')->with($order);
        $this->settlementService->expects(self::never())->method('settle');

        $this->makeReconciler()->execute();
    }

    /**
     * IMPROVE-5: a still-in-progress order increments its reconcile_attempts
     * counter and the bumped counter is persisted (save) so the next tick sees
     * it. The order is NOT made terminal while under the cap.
     */
    public function testReconcileAttemptCounterIncrementsAndPersists(): void
    {
        $info = ['flitt_order_id' => 'duka_000000055_1700000000'];
        [$order] = $this->primeStatefulOrder(
            $info,
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
        );
        $this->primeOrderSearch([$order]);

        // Flitt still processing → non-resolving → counter bumps, order kept.
        $this->statusClient->method('checkStatus')->willReturn([
            'order_status'    => 'processing',
            'response_status' => 'success',
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);

        // Counter persisted at least once (recordAttempt save).
        $this->orderRepository->expects(self::atLeastOnce())->method('save')->with($order);
        $order->expects(self::never())->method('cancel');

        $this->makeReconciler()->execute();

        self::assertSame(1, $info['reconcile_attempts']);
    }

    /**
     * IMPROVE-5: once the attempt cap is exceeded the order is moved to a
     * terminal "needs manual review" outcome (cancelled + flagged) and an ERROR
     * alert is emitted. Flitt is never even queried on the terminating run.
     */
    public function testTerminalAfterAttemptCapWithAlert(): void
    {
        // Already at the cap (12) — this run pushes to 13 (> cap) → terminal.
        $info = ['flitt_order_id' => 'duka_000000055_1700000000', 'reconcile_attempts' => 12];
        [$order, $payment] = $this->primeStatefulOrder(
            $info,
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
        );
        $this->primeOrderSearch([$order]);

        // Terminal short-circuits BEFORE the Flitt call.
        $this->statusClient->expects(self::never())->method('checkStatus');

        $order->method('canCancel')->willReturn(true);
        $order->expects(self::once())->method('cancel');
        $this->orderRepository->expects(self::once())->method('save')->with($order);
        $this->adapter->expects(self::atLeastOnce())->method('beginTransaction');
        $this->adapter->expects(self::atLeastOnce())->method('commit');

        $this->logger->expects(self::atLeastOnce())
            ->method('error')
            ->with(self::stringContains('exhausted retries'), self::isType('array'));

        $this->makeReconciler()->execute();

        self::assertSame(13, $info['reconcile_attempts']);
        self::assertSame('attempt_cap_exceeded', $info['reconcile_terminal']);
    }

    /**
     * IMPROVE-5: an order older than 2x the payment lifetime is made terminal
     * even if the attempt cap has not been reached.
     */
    public function testTerminalAfterAgeExceedsTwiceLifetime(): void
    {
        // payment_lifetime default 3600 → 2x = 7200s. created 3 hours ago.
        $info = ['flitt_order_id' => 'duka_000000055_1700000000', 'reconcile_attempts' => 1];
        [$order] = $this->primeStatefulOrder(
            $info,
            createdAt: (new \DateTimeImmutable('-3 hours'))->format('Y-m-d H:i:s'),
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->expects(self::never())->method('checkStatus');
        $order->method('canCancel')->willReturn(true);
        $order->expects(self::once())->method('cancel');

        $this->logger->expects(self::atLeastOnce())
            ->method('error')
            ->with(self::stringContains('exhausted retries'), self::isType('array'));

        $this->makeReconciler()->execute();

        self::assertSame('age_exceeded_2x_lifetime', $info['reconcile_terminal']);
    }

    /**
     * IMPROVE-6: a candidate count at or above the threshold emits an ERROR
     * backlog alert after the pass.
     */
    public function testBacklogOverThresholdEmitsError(): void
    {
        $orders = [];
        for ($i = 0; $i < 3; $i++) {
            $info = ['flitt_order_id' => 'duka_' . $i];
            [$order] = $this->primeStatefulOrder(
                $info,
                createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
            );
            $orders[] = $order;
        }
        $this->primeOrderSearch($orders);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status'    => 'processing',
            'response_status' => 'success',
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);
        // Threshold of 3 → backlog of 3 trips the alert.
        $this->backlogAlertThreshold = 3;

        $this->logger->expects(self::atLeastOnce())
            ->method('error')
            ->with(
                self::stringContains('backlog over threshold'),
                self::callback(static fn (array $ctx): bool => ($ctx['pending_count'] ?? null) === 3)
            );

        $this->makeReconciler()->execute();
    }

    /**
     * IMPROVE-6: under threshold emits no ERROR (only the INFO metric).
     */
    public function testBacklogUnderThresholdEmitsNoError(): void
    {
        $info = ['flitt_order_id' => 'duka_solo'];
        [$order] = $this->primeStatefulOrder(
            $info,
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status'    => 'processing',
            'response_status' => 'success',
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);

        // No backlog-over-threshold error must be raised for a single order.
        $this->logger->expects(self::never())
            ->method('error')
            ->with(self::stringContains('backlog over threshold'), self::anything());

        $this->makeReconciler()->execute();
    }

    /**
     * T-8: Flitt status `declined` → the reconciler cancels the order, leaves a
     * localized history comment, and persists. No capture, no settlement.
     */
    public function testDeclinedStatusCancelsOrder(): void
    {
        $info = ['flitt_order_id' => 'duka_000000055_1700000000'];
        [$order] = $this->primeStatefulOrder(
            $info,
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status'    => 'declined',
            'response_status' => 'success',
            'error_message'   => 'Insufficient funds',
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);

        $order->expects(self::once())->method('cancel');
        $order->expects(self::atLeastOnce())
            ->method('addCommentToStatusHistory')
            ->with(self::callback(static fn ($c): bool => str_contains((string) $c, 'declined by TBC Bank')));
        $this->approvalApplier->expects(self::never())->method('apply');
        $this->settlementService->expects(self::never())->method('settle');

        $this->makeReconciler()->execute();
    }

    /**
     * T-8: Flitt status `expired` → the reconciler cancels the order with the
     * session-expired comment. No capture, no settlement.
     */
    public function testExpiredStatusCancelsOrder(): void
    {
        $info = ['flitt_order_id' => 'duka_000000055_1700000000'];
        [$order] = $this->primeStatefulOrder(
            $info,
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status'    => 'expired',
            'response_status' => 'success',
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);

        $order->expects(self::once())->method('cancel');
        $order->expects(self::atLeastOnce())
            ->method('addCommentToStatusHistory')
            ->with(self::callback(static fn ($c): bool => str_contains((string) $c, 'session expired')));
        $this->approvalApplier->expects(self::never())->method('apply');
        $this->settlementService->expects(self::never())->method('settle');

        $this->makeReconciler()->execute();
    }

    /**
     * T-8: Flitt status `created` (intermediate) → no mutation. The order is
     * neither cancelled nor captured; the attempt counter just advances so a
     * later tick re-checks. (Companion to the existing `processing` counter test.)
     */
    public function testCreatedStatusIsNonMutatingRetry(): void
    {
        $info = ['flitt_order_id' => 'duka_000000055_1700000000'];
        [$order] = $this->primeStatefulOrder(
            $info,
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status'    => 'created',
            'response_status' => 'success',
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);

        $order->expects(self::never())->method('cancel');
        $this->approvalApplier->expects(self::never())->method('apply');
        $this->settlementService->expects(self::never())->method('settle');

        $this->makeReconciler()->execute();

        // Counter advanced (non-resolving) so the order is re-checked next tick.
        self::assertSame(1, $info['reconcile_attempts']);
    }

    /**
     * T-8: an UNKNOWN/unhandled Flitt status → log a warning, mutate nothing.
     * The match() default arm fires; no cancel, no capture, no settlement.
     */
    public function testUnknownStatusLogsWarningAndDoesNotMutate(): void
    {
        $info = ['flitt_order_id' => 'duka_000000055_1700000000'];
        [$order] = $this->primeStatefulOrder(
            $info,
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status'    => 'some_unmapped_status',
            'response_status' => 'success',
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);

        $this->logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                self::stringContains('unknown Flitt status'),
                self::callback(static fn (array $ctx): bool => ($ctx['flitt_status'] ?? null) === 'some_unmapped_status')
            );
        $order->expects(self::never())->method('cancel');
        $this->approvalApplier->expects(self::never())->method('apply');
        $this->settlementService->expects(self::never())->method('settle');

        $this->makeReconciler()->execute();
    }

    /**
     * T-8: approved + settlement throws. The applier captures (order → PROCESSING)
     * and is persisted, but SettlementService::settle() throws. The reconciler
     * must SWALLOW the settlement failure (logged at ERROR) and NOT roll back the
     * capture — the order stays captured/PROCESSING and settlement is retried by
     * the settlement-recovery cron. The callback response equivalent here is "the
     * exception never escapes execute()".
     */
    public function testApprovedSettlementThrowsKeepsCaptureAndLogs(): void
    {
        [$order] = $this->primeOrder(
            flittOrderId: 'duka_000000055_1700000000',
            createdAt: (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
            grandTotal: 50.00,
        );
        $this->primeOrderSearch([$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status'    => 'approved',
            'response_status' => 'success',
            'payment_id'      => 'pay-77',
            'amount'          => 5000,
        ]);
        $this->callbackValidator->method('validate')->willReturn(true);
        $this->config->method('isPreauth')->willReturn(false);

        // Capture succeeds…
        $this->approvalApplier->expects(self::once())
            ->method('apply')
            ->with($order, self::isType('array'), ApprovalContext::Reconciler)
            ->willReturn(ApprovalResult::Captured);

        // …but settlement blows up.
        $this->settlementService->expects(self::once())
            ->method('settle')
            ->with($order)
            ->willThrowException(new \RuntimeException('Flitt settlement API returned HTTP 502'));

        // The settlement failure is logged at ERROR and does NOT escape execute()
        // (no exception bubbles out → the capture transaction already committed).
        $this->logger->expects(self::atLeastOnce())
            ->method('error')
            ->with(
                self::stringContains('settlement failed'),
                self::callback(static fn (array $ctx): bool => str_contains((string) ($ctx['error'] ?? ''), '502'))
            );

        // The capture committed before settlement ran — never rolled back here.
        $this->adapter->expects(self::never())->method('rollBack');

        // Must not throw.
        $this->makeReconciler()->execute();
    }

    /**
     * @param array<string, mixed> $info Mutable payment additional_information (by reference)
     * @return array{0: Order&MockObject, 1: Payment&MockObject}
     */
    private function primeStatefulOrder(
        array &$info,
        string $createdAt,
        float $grandTotal = 50.00,
        string $state = Order::STATE_PENDING_PAYMENT,
    ): array {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAdditionalInformation', 'setAdditionalInformation', 'getMethod'])
            ->getMock();
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (?string $key = null): mixed => $key === null ? $info : ($info[$key] ?? null)
        );
        $payment->method('setAdditionalInformation')->willReturnCallback(
            static function (string $key, mixed $value) use (&$info, $payment): Payment {
                $info[$key] = $value;
                return $payment;
            }
        );

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getPayment', 'getIncrementId', 'getStoreId', 'getCreatedAt',
                'cancel', 'canCancel', 'addCommentToStatusHistory', 'getState', 'getGrandTotal',
            ])
            ->getMock();
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIncrementId')->willReturn('000000055');
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCreatedAt')->willReturn($createdAt);
        $order->method('getState')->willReturn($state);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('canCancel')->willReturn(true);
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        return [$order, $payment];
    }

    private function makeReconciler(): PendingOrderReconciler
    {
        return new PendingOrderReconciler(
            $this->orderRepository,
            $this->searchCriteriaBuilder,
            $this->sortOrderBuilder,
            $this->statusClient,
            $this->callbackValidator,
            $this->approvalApplier,
            $this->settlementService,
            $this->config,
            $this->logger,
            $this->resourceConnection,
            $this->appState,
            $this->paymentLock,
        );
    }

    /**
     * @return array{0: Order&MockObject, 1: Payment&MockObject}
     */
    private function primeOrder(
        string $flittOrderId,
        string $createdAt,
        float $grandTotal = 50.00,
        string $state = Order::STATE_PENDING_PAYMENT,
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
        $order->method('getState')->willReturn($state);
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
