<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Model;

/**
 * Central vocabulary for Flitt `order_status` values on the capture/money path.
 *
 * Flitt reports the lifecycle of an order through the `order_status` field of
 * the callback payload and the `/api/status/order_id` response. Branching on
 * these values is intrinsic to the capture flow (see Callback, Confirm,
 * ReturnAction, CheckStatus, PendingOrderReconciler). Previously each branch
 * used a bare string literal, where a single typo (`'aproved'`) would silently
 * route an approved payment into the default/warn branch and never capture.
 * Centralising the vocabulary here is a correctness measure.
 *
 * This is a pure constants holder — no behaviour.
 */
class FlittStatus
{
    /** Payment authorised/captured by the bank — funds secured. */
    public const APPROVED = 'approved';

    /** Payment refused by the bank. */
    public const DECLINED = 'declined';

    /** Payment session timed out before the customer paid. */
    public const EXPIRED = 'expired';

    /** Authorisation released, or a captured payment refunded. */
    public const REVERSED = 'reversed';

    /** Bank is still processing — not yet terminal. */
    public const PROCESSING = 'processing';

    /** Order registered with Flitt but no card interaction yet. */
    public const CREATED = 'created';
}
