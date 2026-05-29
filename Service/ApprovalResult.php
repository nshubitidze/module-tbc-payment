<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Service;

/**
 * Outcome of {@see OrderApprovalApplier::apply()} so callers can react.
 *
 * IMPROVE-8: the applier guards the direct-sale capture against a Flitt /
 * Magento amount divergence (a cart edited mid-flow). When the signed Flitt
 * amount disagrees with the order grand total by more than 1 tetri, the applier
 * refuses to capture and returns {@see self::RefusedAmountMismatch} instead of
 * silently charging the wrong total. Each capture path maps the result to its
 * own response:
 *   - Callback        → HTTP 400 do-not-retry sentinel, skip save-as-processed
 *   - Confirm         → log + leave order for admin reconcile, no capture
 *   - ReturnAction    → log + leave order for admin reconcile, no capture
 *   - CheckStatus     → admin message + leave order, no capture
 *   - Reconciler      → log + leave order for admin reconcile, no capture
 *
 * The happy results distinguish the two approve branches so callers (notably
 * the reconciler) keep their existing "settle only on direct-sale capture"
 * decision without re-reading config.
 */
enum ApprovalResult
{
    /** Direct-sale (auto-capture) branch ran: registerCaptureNotification fired. */
    case Captured;

    /** Preauth branch ran: funds held only, no capture. */
    case PreauthHeld;

    /**
     * Already-PROCESSING idempotent no-op: a concurrent capture path already
     * promoted this order. Nothing was mutated.
     */
    case AlreadyProcessed;

    /**
     * Direct-sale capture refused: the Flitt-signed amount diverged from the
     * order grand total by more than 1 tetri. NOTHING was mutated — the order
     * stays in its pre-capture state for admin reconciliation.
     */
    case RefusedAmountMismatch;
}
