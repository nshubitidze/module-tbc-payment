<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Service;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Service\ApprovalContext;
use Shubo\TbcPayment\Service\ApprovalResult;
use Shubo\TbcPayment\Service\OrderApprovalApplier;

/**
 * Batch 3 SIMPLIFY-3 canary: the five capture paths collapse onto this one
 * applier. The MONEY-CRITICAL behaviour is pinned here once:
 *
 *  - direct-sale (auto-capture) → registerCaptureNotification EXACTLY ONCE with
 *    the integer-tetri-derived amount → fires sales_order_payment_pay → the
 *    Commission/Payout chain. THIS IS THE CANARY.
 *  - preauth → NO capture (funds held only).
 *  - already-PROCESSING → idempotent no-op (re-delivered callback / cron).
 *  - state → PROCESSING and the per-context history comment.
 */
class OrderApprovalApplierTest extends TestCase
{
    private Config&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private OrderApprovalApplier $applier;

    /** @var list<array{0: string, 1: string}> */
    private array $stateTransitions = [];

    /** @var list<string> */
    private array $comments = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->applier = new OrderApprovalApplier($this->config, $this->logger);
    }

    public function testDirectSaleRegistersCaptureExactlyOnceAndPromotesToProcessing(): void
    {
        $this->config->method('isPreauth')->willReturn(false);

        $payment = $this->makePayment();
        // THE CANARY: registerCaptureNotification fires exactly once, with the
        // Flitt minor-unit amount (5000 tetri) divided by 100 = 50.00. This is
        // the sales_order_payment_pay trigger for the Commission/Payout chain.
        $payment->expects(self::once())
            ->method('registerCaptureNotification')
            ->with(50.0);
        // Direct-sale closes the transaction (full capture).
        $payment->expects(self::once())->method('setIsTransactionClosed')->with(true);

        $order = $this->makeOrder($payment, Order::STATE_PENDING_PAYMENT, grandTotal: 50.00);

        $result = $this->applier->apply(
            $order,
            ['order_status' => 'approved', 'payment_id' => 'pay-1', 'amount' => 5000],
            ApprovalContext::Callback,
        );

        self::assertSame(ApprovalResult::Captured, $result);
        self::assertSame([[Order::STATE_PROCESSING, Order::STATE_PROCESSING]], $this->stateTransitions);
        self::assertNotEmpty($this->comments);
        self::assertStringContainsString('Payment approved by TBC Bank', $this->comments[0]);
    }

    public function testDirectSaleAmountFallsBackToGrandTotalWhenAmountMissing(): void
    {
        $this->config->method('isPreauth')->willReturn(false);

        $payment = $this->makePayment();
        // No `amount` in payload → derive from grand total: round(10.50 * 100) = 1050 tetri → 10.50.
        $payment->expects(self::once())
            ->method('registerCaptureNotification')
            ->with(10.5);

        $order = $this->makeOrder($payment, Order::STATE_PENDING_PAYMENT, grandTotal: 10.50);

        $this->applier->apply(
            $order,
            ['order_status' => 'approved', 'payment_id' => 'pay-2'],
            ApprovalContext::Redirect,
        );

        self::assertSame([[Order::STATE_PROCESSING, Order::STATE_PROCESSING]], $this->stateTransitions);
    }

    public function testPreauthDoesNotCapture(): void
    {
        $this->config->method('isPreauth')->willReturn(true);

        $additionalInfo = [];
        $payment = $this->makePayment();
        $payment->method('setAdditionalInformation')->willReturnCallback(
            function (string $key, mixed $value) use ($payment, &$additionalInfo): Payment {
                $additionalInfo[$key] = $value;
                return $payment;
            }
        );
        // Preauth holds funds only — NEVER captures.
        $payment->expects(self::never())->method('registerCaptureNotification');
        $payment->expects(self::once())->method('setIsTransactionClosed')->with(false);

        $order = $this->makeOrder($payment, Order::STATE_PENDING_PAYMENT, grandTotal: 50.00);

        $result = $this->applier->apply(
            $order,
            ['order_status' => 'approved', 'payment_id' => 'pay-3', 'amount' => 5000],
            ApprovalContext::Callback,
        );

        self::assertSame(ApprovalResult::PreauthHeld, $result);
        self::assertSame(true, $additionalInfo['preauth_approved'] ?? null);
        self::assertSame(false, $additionalInfo['awaiting_flitt_confirmation'] ?? null);
        self::assertSame([[Order::STATE_PROCESSING, Order::STATE_PROCESSING]], $this->stateTransitions);
        self::assertNotEmpty($this->comments);
        self::assertStringContainsString('Funds held by TBC Bank', $this->comments[0]);
    }

    public function testIdempotentNoOpWhenAlreadyProcessing(): void
    {
        // isPreauth must never even be consulted on the no-op path.
        $this->config->expects(self::never())->method('isPreauth');

        $payment = $this->makePayment();
        $payment->expects(self::never())->method('registerCaptureNotification');
        $payment->expects(self::never())->method('setTransactionId');
        $payment->expects(self::never())->method('setIsTransactionClosed');

        $order = $this->makeOrder($payment, Order::STATE_PROCESSING, grandTotal: 50.00);
        $order->expects(self::never())->method('setState');
        $order->expects(self::never())->method('addCommentToStatusHistory');

        $result = $this->applier->apply(
            $order,
            ['order_status' => 'approved', 'payment_id' => 'pay-4', 'amount' => 5000],
            ApprovalContext::Reconciler,
        );

        self::assertSame(ApprovalResult::AlreadyProcessed, $result);
        self::assertSame([], $this->stateTransitions);
        self::assertSame([], $this->comments);
    }

    /**
     * IMPROVE-8: a divergence of exactly 1 tetri (benign rounding) still
     * captures — the tolerance must not be so tight it rejects legitimate
     * half-up rounding at the float→minor boundary.
     */
    public function testDirectSaleCapturesWhenAmountDiffersByOneTetri(): void
    {
        $this->config->method('isPreauth')->willReturn(false);
        $this->logger->expects(self::never())->method('critical');

        $payment = $this->makePayment();
        // Flitt says 5001 tetri, order grand total is 50.00 (5000 tetri): diff = 1 → within tolerance.
        $payment->expects(self::once())->method('registerCaptureNotification')->with(50.01);

        $order = $this->makeOrder($payment, Order::STATE_PENDING_PAYMENT, grandTotal: 50.00);

        $result = $this->applier->apply(
            $order,
            ['order_status' => 'approved', 'payment_id' => 'pay-5', 'amount' => 5001],
            ApprovalContext::Callback,
        );

        self::assertSame(ApprovalResult::Captured, $result);
        self::assertSame([[Order::STATE_PROCESSING, Order::STATE_PROCESSING]], $this->stateTransitions);
    }

    /**
     * IMPROVE-8: a divergence over 1 tetri (the canonical cart-edit-mid-flow
     * scenario) MUST refuse the capture — no registerCaptureNotification, no
     * state→PROCESSING, no comment, a critical log, and a RefusedAmountMismatch
     * return so the caller can leave the order for admin reconcile.
     */
    public function testDirectSaleRefusesWhenAmountMismatchExceedsTolerance(): void
    {
        $this->config->method('isPreauth')->willReturn(false);

        $this->logger->expects(self::once())
            ->method('critical')
            ->with(
                self::stringContains('amount mismatch'),
                self::callback(static function (array $ctx): bool {
                    return ($ctx['flitt_amount_minor'] ?? null) === 9999
                        && ($ctx['order_amount_minor'] ?? null) === 5000
                        && ($ctx['difference_minor'] ?? null) === 4999;
                })
            );

        $payment = $this->makePayment();
        // Cart was re-priced: Flitt charged 99.99 but the order is now 50.00.
        $payment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->makeOrder($payment, Order::STATE_PENDING_PAYMENT, grandTotal: 50.00);

        $result = $this->applier->apply(
            $order,
            ['order_status' => 'approved', 'payment_id' => 'pay-6', 'amount' => 9999],
            ApprovalContext::Callback,
        );

        self::assertSame(ApprovalResult::RefusedAmountMismatch, $result);
        self::assertSame([], $this->stateTransitions, 'A refused capture must not promote the order');
        self::assertSame([], $this->comments, 'A refused capture must not add a status-history comment');
    }

    /**
     * IMPROVE-8: the mismatch guard is scoped to the auto-capture branch.
     * Preauth only HOLDS funds (no charge), so even a large amount divergence
     * must NOT block holding the funds — capture (and any reconciliation) happens
     * later via the admin "Capture Payment" button against the real auth.
     */
    public function testPreauthDoesNotApplyAmountMismatchGuard(): void
    {
        $this->config->method('isPreauth')->willReturn(true);
        $this->logger->expects(self::never())->method('critical');

        $payment = $this->makePayment();
        $payment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->makeOrder($payment, Order::STATE_PENDING_PAYMENT, grandTotal: 50.00);

        $result = $this->applier->apply(
            $order,
            ['order_status' => 'approved', 'payment_id' => 'pay-7', 'amount' => 9999],
            ApprovalContext::Callback,
        );

        self::assertSame(ApprovalResult::PreauthHeld, $result);
        self::assertSame([[Order::STATE_PROCESSING, Order::STATE_PROCESSING]], $this->stateTransitions);
    }

    /**
     * IMPROVE-8: a missing Flitt `amount` is NOT a mismatch — we cannot compare,
     * so the guard defers to the grand-total fallback (historical behaviour) and
     * captures.
     */
    public function testDirectSaleCapturesWhenAmountFieldAbsent(): void
    {
        $this->config->method('isPreauth')->willReturn(false);
        $this->logger->expects(self::never())->method('critical');

        $payment = $this->makePayment();
        // No `amount` → derive from grand total: 50.00.
        $payment->expects(self::once())->method('registerCaptureNotification')->with(50.0);

        $order = $this->makeOrder($payment, Order::STATE_PENDING_PAYMENT, grandTotal: 50.00);

        $result = $this->applier->apply(
            $order,
            ['order_status' => 'approved', 'payment_id' => 'pay-8'],
            ApprovalContext::Reconciler,
        );

        self::assertSame(ApprovalResult::Captured, $result);
    }

    public function testTransactionIdSetFromPaymentIdOnDirectSale(): void
    {
        $this->config->method('isPreauth')->willReturn(false);

        $payment = $this->makePayment();
        $payment->expects(self::once())->method('setTransactionId')->with('pay-77');

        $order = $this->makeOrder($payment, Order::STATE_PENDING_PAYMENT, grandTotal: 50.00);

        $this->applier->apply(
            $order,
            ['order_status' => 'approved', 'payment_id' => 'pay-77', 'amount' => 5000],
            ApprovalContext::ManualStatusCheck,
        );
    }

    public function testDoesNotSetTransactionIdWhenPaymentIdMissing(): void
    {
        $this->config->method('isPreauth')->willReturn(false);

        $payment = $this->makePayment();
        $payment->expects(self::never())->method('setTransactionId');

        $order = $this->makeOrder($payment, Order::STATE_PENDING_PAYMENT, grandTotal: 50.00);

        $this->applier->apply(
            $order,
            ['order_status' => 'approved', 'amount' => 5000],
            ApprovalContext::Confirm,
        );
    }

    /**
     * @dataProvider directSaleCommentProvider
     */
    public function testDirectSaleCommentPerContext(ApprovalContext $context, string $needle): void
    {
        $this->config->method('isPreauth')->willReturn(false);

        $order = $this->makeOrder($this->makePayment(), Order::STATE_PENDING_PAYMENT, grandTotal: 50.00);

        $this->applier->apply(
            $order,
            ['order_status' => 'approved', 'payment_id' => 'pay-9', 'amount' => 5000],
            $context,
        );

        self::assertNotEmpty($this->comments);
        self::assertStringContainsString($needle, $this->comments[0]);
    }

    /**
     * @return array<string, array{0: ApprovalContext, 1: string}>
     */
    public static function directSaleCommentProvider(): array
    {
        return [
            'callback'   => [ApprovalContext::Callback, 'Payment approved by TBC Bank. Payment ID: pay-9'],
            'confirm'    => [ApprovalContext::Confirm, 'Payment approved by TBC Bank. Payment ID: pay-9'],
            'redirect'   => [ApprovalContext::Redirect, '(redirect)'],
            'manual'     => [ApprovalContext::ManualStatusCheck, '(manual status check)'],
            'reconciler' => [ApprovalContext::Reconciler, '(reconciled by cron)'],
        ];
    }

    private function makePayment(): Payment&MockObject
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setAdditionalInformation',
                'setTransactionId',
                'setParentTransactionId',
                'setIsTransactionPending',
                'setIsTransactionClosed',
                'registerCaptureNotification',
            ])
            ->getMock();
        $payment->method('setAdditionalInformation')->willReturnSelf();
        $payment->method('setTransactionId')->willReturnSelf();
        $payment->method('setIsTransactionPending')->willReturnSelf();
        $payment->method('setIsTransactionClosed')->willReturnSelf();

        // The dropped synthetic parent_txn_id must never reappear on any branch.
        $payment->expects(self::never())->method('setParentTransactionId');

        return $payment;
    }

    private function makeOrder(
        Payment&MockObject $payment,
        string $state,
        float $grandTotal,
    ): Order&MockObject {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getState', 'getStoreId', 'getGrandTotal', 'getIncrementId',
                'getPayment', 'setState', 'setStatus', 'addCommentToStatusHistory',
            ])
            ->getMock();
        $order->method('getState')->willReturn($state);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getPayment')->willReturn($payment);

        $order->method('setState')->willReturnCallback(
            function (string $newState) use ($order): Order {
                $this->stateTransitions[] = [$newState, ''];
                return $order;
            }
        );
        $order->method('setStatus')->willReturnCallback(
            function (string $newStatus) use ($order): Order {
                $idx = count($this->stateTransitions) - 1;
                if ($idx >= 0) {
                    $this->stateTransitions[$idx][1] = $newStatus;
                }
                return $order;
            }
        );
        $order->method('addCommentToStatusHistory')->willReturnCallback(
            function (string $comment) use ($order): \Magento\Sales\Model\Order\Status\History {
                $this->comments[] = $comment;
                /** @var \Magento\Sales\Model\Order\Status\History&MockObject $history */
                $history = $this->createMock(\Magento\Sales\Model\Order\Status\History::class);
                return $history;
            }
        );

        return $order;
    }
}
