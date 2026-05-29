<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Service;

/**
 * Identifies which capture path is applying a Flitt approval.
 *
 * The five paths converge on the same order mutation (see OrderApprovalApplier)
 * but each leaves a slightly different human-facing breadcrumb in the order
 * status history so support can tell at a glance HOW an order was finalised
 * (server callback, embed confirm, redirect return, manual admin check, or the
 * cron reconciler). This enum carries that single distinguishing fact.
 */
enum ApprovalContext
{
    /** Server-to-server Flitt callback. */
    case Callback;

    /** Customer-driven embed confirmation (Confirm controller). */
    case Confirm;

    /** Customer return from the hosted page (ReturnAction controller). */
    case Redirect;

    /** Admin "Check Status" button. */
    case ManualStatusCheck;

    /** Cron safety-net reconciler. */
    case Reconciler;
}
