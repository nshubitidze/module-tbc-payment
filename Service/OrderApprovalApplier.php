<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Service;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Response\PaymentInfoKeys;

/**
 * Applies a Flitt "approved" result to a Magento order's in-memory state.
 *
 * Five capture paths converge on the identical state transition because Flitt
 * can deliver "approved" through any of them — the server callback, the embed
 * Confirm, the redirect ReturnAction, the admin CheckStatus, and the cron
 * reconciler. This service owns that single shared mutation so the money-path
 * capture logic lives in one place (SIMPLIFY-3).
 *
 * RESPONSIBILITY BOUNDARY (deliberate, per the design doc):
 *  - This class mutates ONLY the in-memory $order / $payment.
 *  - It does NOT open DB transactions, acquire locks, call orderRepository->save(),
 *    or run settlement — those stay with the CALLERS, preserving the lock boundary
 *    and SRP.
 *
 * CAPTURE → COMMISSION → PAYOUT CHAIN (must stay intact):
 * On the direct-sale (auto-capture) branch this calls
 * {@see Payment::registerCaptureNotification()}, which fires Magento's
 * `sales_order_payment_pay` event and drives the locked chain:
 *   registerCaptureNotification → sales_order_payment_pay
 *     → Commission\Observer\CommissionCaptureObserver  (PENDING → CAPTURED)
 *     → Payout\Observer\RecordOnlineOrderPayoutObserver (SPLIT_PAYOUT ledger entry)
 * The preauth branch deliberately does NOT capture — funds are only held and
 * capture happens later via the admin "Capture Payment" button.
 */
class OrderApprovalApplier
{
    /**
     * IMPROVE-8: maximum tolerated divergence (in tetri) between the Flitt-signed
     * amount and the order grand total before the direct-sale capture is refused.
     * One tetri absorbs benign half-up rounding at the float→minor boundary.
     */
    private const AMOUNT_TOLERANCE_TETRI = 1;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Apply a Flitt-approved result to the order.
     *
     * Idempotent: if the order is already in PROCESSING (a concurrent path won),
     * this is a no-op so a re-delivered callback / reconciler run can't double-apply.
     *
     * Returns an {@see ApprovalResult} so callers can react — in particular to
     * {@see ApprovalResult::RefusedAmountMismatch}, where NOTHING was mutated
     * and the order must be left for admin reconciliation (IMPROVE-8).
     *
     * @param array<string, mixed> $responseData Flitt callback / status payload
     */
    public function apply(Order $order, array $responseData, ApprovalContext $context): ApprovalResult
    {
        // Idempotency guard: another capture path already promoted this order.
        if ($order->getState() === Order::STATE_PROCESSING) {
            return ApprovalResult::AlreadyProcessed;
        }

        $isPreauth = $this->config->isPreauth((int) $order->getStoreId());

        // IMPROVE-8: guard the direct-sale capture amount BEFORE any mutation.
        // Preauth only HOLDS funds (no charge), so the mismatch guard is scoped
        // to the auto-capture branch where the wrong amount would actually be
        // invoiced. We compare the Flitt-signed minor-unit amount against the
        // order grand total in tetri — integer math only (CLAUDE.md #6).
        if (!$isPreauth && !$this->captureAmountAgrees($order, $responseData)) {
            return ApprovalResult::RefusedAmountMismatch;
        }

        /** @var Payment $payment */
        $payment = $order->getPayment();

        PaymentInfoKeys::apply($payment, $responseData);

        // Preserve the previously-stored flitt_order_id rather than clobber it
        // with an empty string when the status payload omits order_id.
        $flittOrderId = (string) ($responseData['order_id'] ?? '');
        if ($flittOrderId !== '') {
            $payment->setAdditionalInformation('flitt_order_id', $flittOrderId);
        }

        $payment->setAdditionalInformation('awaiting_flitt_confirmation', false);

        $paymentId = (string) ($responseData['payment_id'] ?? '');
        if ($paymentId !== '') {
            // NOTE: We intentionally do NOT call setParentTransactionId() here.
            // In direct-sale (non-preauth) mode there is no auth transaction upstream
            // that the capture could point at, and inventing a synthetic
            // "{increment_id}-auth" parent_txn_id produced dangling parent links in the
            // admin transaction tree. When preauth capture is implemented as a
            // distinct workflow, reintroduce the parent pointer from a REAL auth row.
            $payment->setTransactionId($paymentId);
        }

        if ($isPreauth) {
            $this->applyPreauth($order, $payment, $responseData, $context);
            return ApprovalResult::PreauthHeld;
        }

        $this->applyDirectSale($order, $payment, $responseData, $context);
        return ApprovalResult::Captured;
    }

    /**
     * IMPROVE-8: does the Flitt-signed amount agree with the order grand total?
     *
     * Flitt signs `amount` in minor units (tetri). We compare it against the
     * order grand total converted to tetri; a divergence over the 1-tetri
     * tolerance means the cart was almost certainly edited in another tab
     * between Flitt initiation and capture (or a stale callback is replaying a
     * re-priced order). The signature already covers `amount`, so this is NOT a
     * forgery check — it catches a genuine Flitt/Magento divergence that would
     * otherwise invoice the wrong total and corrupt the Commission/Payout chain.
     *
     * When Flitt omits `amount` entirely we cannot compare, so we DEFER to the
     * grand-total fallback (the historical behaviour) and treat it as agreeing —
     * a missing field is not a mismatch. Integer-only math (CLAUDE.md #6).
     *
     * @param array<string, mixed> $responseData
     */
    private function captureAmountAgrees(Order $order, array $responseData): bool
    {
        $flittAmount = $responseData['amount'] ?? null;
        if ($flittAmount === null || !is_numeric($flittAmount)) {
            // No signed amount to compare — fall through to the grand-total basis.
            return true;
        }

        $flittMinor = (int) $flittAmount;
        $orderMinor = (int) round(((float) $order->getGrandTotal()) * 100);
        $diffMinor = $flittMinor - $orderMinor;

        if (abs($diffMinor) <= self::AMOUNT_TOLERANCE_TETRI) {
            return true;
        }

        $this->logger->critical(
            'TBC capture: amount mismatch — possible cart-edit mid-flow',
            [
                'order_id'           => $order->getIncrementId(),
                'flitt_amount_minor' => $flittMinor,
                'order_amount_minor' => $orderMinor,
                'difference_minor'   => $diffMinor,
            ]
        );

        return false;
    }

    /**
     * Preauth branch: hold funds only, never capture.
     *
     * @param array<string, mixed> $responseData
     */
    private function applyPreauth(
        Order $order,
        Payment $payment,
        array $responseData,
        ApprovalContext $context,
    ): void {
        $payment->setAdditionalInformation('preauth_approved', true);
        $payment->setIsTransactionPending(false);
        $payment->setIsTransactionClosed(false);

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->addCommentToStatusHistory($this->preauthComment($responseData, $context));
    }

    /**
     * Direct-sale (auto-capture) branch: register the capture and fire the
     * Commission/Payout chain.
     *
     * @param array<string, mixed> $responseData
     */
    private function applyDirectSale(
        Order $order,
        Payment $payment,
        array $responseData,
        ApprovalContext $context,
    ): void {
        $payment->setIsTransactionPending(false);
        $payment->setIsTransactionClosed(true);

        // Amount basis: prefer Flitt's signed minor-unit amount, fall back to the
        // order grand total in minor units. Integer math only (CLAUDE.md #6); the
        // /100 divisor is the exact call the five sites made today.
        $amountMinor = (int) ($responseData['amount'] ?? (int) round($order->getGrandTotal() * 100));
        $payment->registerCaptureNotification($amountMinor / 100);

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->addCommentToStatusHistory($this->directSaleComment($responseData, $context));
    }

    /**
     * Build the preauth status-history comment, byte-identical per capture path.
     *
     * @param array<string, mixed> $responseData
     */
    private function preauthComment(array $responseData, ApprovalContext $context): string
    {
        $rawLabel = $responseData['payment_id'] ?? 'N/A';
        $paymentId = (string) ($responseData['payment_id'] ?? '');

        return match ($context) {
            ApprovalContext::Callback => (string) __(
                'Funds held by TBC Bank (preauth). Payment ID: %1. Use "Capture Payment" button to charge.',
                $rawLabel
            ),
            ApprovalContext::Confirm => (string) __(
                'Funds held by TBC Bank. Payment ID: %1. Use "Capture Payment" to charge.',
                $paymentId
            ),
            ApprovalContext::Redirect => (string) __(
                'Funds held by TBC Bank (redirect). Payment ID: %1. Use "Capture Payment" to charge.',
                $paymentId
            ),
            ApprovalContext::ManualStatusCheck => (string) __(
                'Funds held by TBC Bank (manual status check). Payment ID: %1. Use "Capture Payment" to charge.',
                $paymentId
            ),
            ApprovalContext::Reconciler => (string) __(
                'Funds held by TBC Bank - preauth (reconciled by cron). Payment ID: %1.'
                . ' Use "Capture Payment" button to charge.',
                $rawLabel
            ),
        };
    }

    /**
     * Build the direct-sale status-history comment, byte-identical per capture path.
     *
     * @param array<string, mixed> $responseData
     */
    private function directSaleComment(array $responseData, ApprovalContext $context): string
    {
        $rawLabel = $responseData['payment_id'] ?? 'N/A';
        $paymentId = (string) ($responseData['payment_id'] ?? '');

        return match ($context) {
            ApprovalContext::Callback => (string) __(
                'Payment approved by TBC Bank. Payment ID: %1',
                $rawLabel
            ),
            ApprovalContext::Confirm => (string) __(
                'Payment approved by TBC Bank. Payment ID: %1',
                $paymentId
            ),
            ApprovalContext::Redirect => (string) __(
                'Payment approved by TBC Bank (redirect). Payment ID: %1',
                $paymentId
            ),
            ApprovalContext::ManualStatusCheck => (string) __(
                'Payment approved by TBC Bank (manual status check). Payment ID: %1',
                $paymentId
            ),
            ApprovalContext::Reconciler => (string) __(
                'Payment approved by TBC Bank (reconciled by cron). Payment ID: %1',
                $rawLabel
            ),
        };
    }
}
