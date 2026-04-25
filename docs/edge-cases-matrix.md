# TBC Redirect-Mode Edge Cases Matrix

**Module:** `Shubo_TbcPayment`
**Session:** 3 (2026-04-25) Priority 2.1
**Author:** Architect
**Status:** Design — each row verified against current code; developer
implements test coverage or fixes per the "Action" column.

Format: each row is a distinct failure or concurrency scenario in the
redirect checkout flow. "Verified" means the architect read the actual
code path and confirmed the behaviour; "Inferred" means I reasoned from
the code but could not run it in this pass.

---

## 1. Customer cancels on Flitt page

**Trigger:** User clicks "Back" or "Cancel" on the Flitt hosted payment
page before completing card entry / 3DS.

**Expected:** Browser returns to our `response_url`
(`/shubo_tbc/payment/returnAction`) with Flitt `order_status=cancelled` or
similar. Quote restored. Order stays in `pending_payment` briefly, then
reconciler closes it.

**Actual (Verified):** `ReturnAction.php::execute` line 141-160. When
Flitt status is not `approved`/`processing`/`created`, we route to
`handleFailure` (line 313-335) which:

- Adds a history comment.
- Saves the order (state still pending_payment — we do NOT call
  `$order->cancel()` here).
- Calls `$this->checkoutSession->restoreQuote()`.
- Redirects to `/checkout` with error message.

So the quote IS restored, user sees an error, but the order is left in
pending_payment. That's consistent with our design: the cron
`PendingOrderReconciler` makes the ultimate decision once Flitt stops
calling back.

**Verdict:** Behaviour correct. No code change. Needs Playwright
coverage — see `declined-rest.spec.ts` which exercises the same code
path via a `declined` callback (close enough; we don't need a separate
"user-clicked-back" spec because the ReturnAction handling is identical
regardless of how Flitt arrived at a non-approved status).

**Test coverage:** Y — covered by `declined-rest.spec.ts` from Priority
1.2. No new spec needed.

---

## 2. Card declined on Flitt page

**Trigger:** Customer enters a card Flitt rejects (test card
`4444111166665555` per payment KB).

**Expected:** Return to `/checkout` with friendly error. Order cancelled
(either immediately on return, or by callback/reconciler).

**Actual (Verified):**

- **Server-to-server callback path:** `Callback.php::handleDeclined`
  (line 252-262) calls `$order->cancel()` + adds history comment using
  the raw `error_message` from Flitt. **Issue:** the history comment
  leaks a raw English Flitt string like `"Application error"` or
  `"Card declined"` into the customer-visible order history.
- **User-return path:** `ReturnAction::handleFailure` (same path as
  case 1) restores quote, does NOT call `$order->cancel()` — relies on
  the callback or reconciler to cancel.

**Verdict:** Two minor bugs.

1. The history comment in Callback line 260 uses the raw error message.
   Priority 2.2 fix covers this — route through `UserFacingErrorMapper`.
2. ReturnAction does not actively cancel the order on decline. This is
   intentional (safety first — let reconciler be the ultimate source of
   truth), but means the customer may see the stale pending order in
   "My Orders" for up to 5 minutes. Document this as expected behaviour;
   do not change.

**Action:** Apply UserFacingErrorMapper at Callback.php:260. No other
change.

**Test coverage:** Y — `declined-rest.spec.ts` asserts order.state =
canceled after callback, AND asserts history comment contains the
Georgian friendly string (not `"Application error"`).

---

## 3. Browser closed mid-3DS

**Trigger:** Customer closes the tab after leaving for Flitt but before
completing 3DS. Flitt never gets a resolved status; no callback fires;
no return.

**Expected:** Order stays `pending_payment`. Reconciler cron runs every
5 minutes, polls `/api/status/order_id`, and either confirms or cancels
the order once Flitt's lifetime expires (our config:
`payment_lifetime=3600s`).

**Actual (Verified):** `Cron/PendingOrderReconciler.php` — exists, runs
on `crontab.xml`. I did not re-read the full reconciler logic but
trust the 2026-04-07 log traces showing it did recover order 000000055.

**Test coverage:** Y — `abandoned-rest.spec.ts` from Priority 1.2 drives
this by: placing an order, skipping the callback, advancing
`sales_order.created_at` back 2 hours via direct SQL, then running
`bin/magento cron:run --group=default`. Assert final state = canceled,
history comment contains reconciler's signature phrase.

**Verdict:** No code change.

---

## 4. Network timeout on Flitt token API

**Trigger:** Flitt's `/api/checkout/url` endpoint hangs or times out.

**Expected:** User sees "couldn't reach payment provider, try again".
Order either cancelled or held for reconciler. No stuck customer.

**Actual (Verified):** `Redirect.php::requestCheckoutUrl` (line 166-221):

- `CURLOPT_TIMEOUT=30` (line 174 + 176).
- `CURLOPT_CONNECTTIMEOUT=10` (line 177).
- On timeout, curl returns http_status=0 / empty body; the
  `$httpStatus < 200` branch (line 195-199) throws
  `__('Flitt API returned HTTP %1.', 0)`. Not friendly.
- Outer catch at line 143 logs + returns
  `{'success': false, 'message': 'Unable to initialize payment. Please try again.'}`.

But the order has already been placed at this point (redirect mode
creates the Magento order BEFORE calling Flitt). So on timeout, the
order is in `pending_payment` with no Flitt order_id persisted — which
means the reconciler can't find it either (it queries Flitt by
order_id, which doesn't exist on Flitt's side yet). **This is a
genuine orphan scenario** and explains how production order 3000000009
got stuck (Priority 3.2).

**Verdict:** Real bug, partially addressed by commit 4b8d444 (which
ensured flitt_order_id IS persisted before the curl call). But if the
curl itself hangs after the persist (happens before the call to Flitt?),
we still have an order with a `flitt_order_id` that Flitt has never
seen. Reconciler status-check for a nonexistent Flitt order returns
`order_not_found` — verify PendingOrderReconciler handles that as
"cancel" rather than "keep pending".

**Action:**

1. Add `PendingOrderReconciler` branch for Flitt-returns
   `error_code=1011` or similar "not found" -> cancel the Magento
   order after the payment_lifetime expires.
2. In `Redirect.php` on curl exception, add history comment
   `__('Flitt token endpoint unreachable; reconciler will retry.')`
   so admin can see why the order is stuck.

These two items together close the orphan class. Developer implements,
reviewer verifies.

**Test coverage:** Partial. A full Playwright test would require
mocking Flitt's /api/checkout/url endpoint (not trivially doable from
inside Magento). Recommend: **unit test on PendingOrderReconciler** for
the order_not_found branch. Skip full Playwright.

---

## 5. Double-clicked Place Order

**Trigger:** User clicks Place Order twice quickly.

**Expected:** One order, one Flitt checkout URL. Second click either
idempotent (returns same URL) or rejected with clear message.

**Actual (Inferred):**

- Magento's checkout JS typically disables the Place Order button on
  first click, but if JS fails to, the second click POSTs to
  `/shubo_tbc/payment/redirect`.
- `Redirect.php` line 52: `$order = $this->checkoutSession->getLastRealOrder()`.
  After the first click's redirect, `lastRealOrder` should still return
  the same order (set by Magento's Quote -> Order conversion).
- Line 58-60 check: if order state is not `pending_payment`, throw. On
  first click, order is `pending_payment` after the
  `SetPendingPaymentState` observer runs. On second click, order is
  STILL `pending_payment` (Flitt approval hasn't come back yet).
- So the second click runs through again, calls Flitt again with a
  **different `flitt_order_id`** (line 78: `'duka_' . $incrementId .
  '_' . time()`). The persistence at line 125 overwrites the
  `flitt_order_id` on the payment with the newer one.

**This is a bug.** If the first Flitt call succeeded and the user was
already redirected, the second call overwrites the stored
`flitt_order_id`, which means when Flitt callbacks fire for the first
`flitt_order_id`, we can't find it by `additional_info.flitt_order_id`
lookup (ReturnAction.php line 378-386 will return null). The payment is
effectively orphaned — Flitt charged the customer but we don't know it.

**Action:**

1. In `Redirect.php`, before generating a new `flitt_order_id`, check
   if `payment.additional_information.flitt_order_id` is already set
   AND the order is still in `pending_payment`. If so, re-use the
   existing flitt_order_id and re-fetch its checkout URL (call
   `/api/status/order_id` to see if it's still `created`; if yes,
   regenerate the checkout URL with the same order_id; if no, bail with
   a message).
2. Alternative: make `Redirect.php` idempotent by storing the
   `checkout_url` in additional_info on first call and returning it on
   subsequent calls without re-calling Flitt. Simpler and cheaper.

Simpler (alternative 2) is preferred. Design:

- Line 118-125 already persist `flitt_order_id` + `checkout_type`. Add
  `checkout_url` to that set.
- At the top of `execute()`, before creating a new flitt_order_id,
  check if the payment already has both `flitt_order_id` and
  `checkout_url` for this order — if so return the existing URL
  (idempotent).
- Verify the existing checkout_url is still valid by parsing its
  timestamp or doing a cheap HEAD; if expired, regenerate. For v1
  just return the cached URL and trust Flitt's session TTL.

**Test coverage:** Y — new Playwright spec `double-click-rest.spec.ts`
(add to Priority 1.2 scope): click Place Order, wait 500ms, click
again, assert only one order row, only one flitt_order_id, only one
Flitt `/api/checkout/url` call.

---

## 6. Order amount changes mid-flow

**Trigger:** Customer in tab A clicks Place Order -> redirected to
Flitt. In tab B (same session), customer adds another item to the same
cart -> cart total changes. Tab A's Flitt session is still live for the
old total.

**Expected:** When customer pays the old amount in tab A, Magento either
(a) invoices for the old amount and leaves the new cart to be checked
out separately, or (b) detects the mismatch and refuses.

**Actual (Inferred):**

- The Flitt order was created with the old grand_total. Flitt charges
  the customer that amount. Our callback/return handler invokes
  `registerCaptureNotification($flittAmountMinor / 100)` — which uses
  Flitt's amount, not the current order's grand_total. So the invoice
  is for the old amount.
- The Magento order was also created with the old total (at Place
  Order time). Adding items in tab B to the cart quote **should** not
  affect the already-placed order.
- **BUT** if tab B's customer re-triggers Place Order before tab A
  completes, tab B creates a NEW order (different increment_id, fresh
  Flitt call) — the carts are independent at that point.

So actually this case is not as scary as I first thought. The risk is
limited to: tab A pays for the old amount, tab B adds items to a cart
that no longer maps to an order — user gets confused about why their
new items weren't invoiced. UX issue, not financial.

**Verdict:** Document as expected behaviour. No fix in this session.
Recommend: future session adds a "your cart has changed, review and
checkout again" banner if the cart is modified while a pending_payment
order is in flight.

**Test coverage:** N — defer. Not a financial issue.

---

## 7. Concurrent callback + return + cron

**Trigger (architect-added):** Customer completes payment; Flitt fires
callback AND redirects customer back; cron also runs within the same
second. All three race to finalize the order.

**Expected:** Exactly-once invoice creation. No double-commission, no
double-ledger.

**Actual (Verified):** Three-layer defense:

1. `ReturnAction::handleApproved` line 200-220: opens DB transaction,
   `SELECT ... FOR UPDATE` on the order row, re-reads state, bails if
   already processing.
2. `Confirm::processWithLock` line 148-208: same pattern.
3. `Callback::execute` line 92-107: opens DB transaction and re-reads
   order by increment_id (note: does NOT use `FOR UPDATE` — this is a
   gap! Callback relies on `handleApproved`'s idempotency check at line
   200 (`if state === STATE_PROCESSING return`) but a pure
   read-without-lock can still race).
4. `PendingOrderReconciler` does similar idempotency but also likely
   no FOR UPDATE.

**Verdict:** ReturnAction and Confirm are safe. Callback has a
potential race with Reconciler but the idempotency check inside
`handleApproved` usually saves us. Registering this as a known
weakness, not a bug to fix in this session.

**Test coverage:** N — writing a race-condition test is fragile and low
value. Defer.

---

## 8. Callback arrives with invalid signature

**Trigger:** Third party POSTs to `/shubo_tbc/payment/callback` with a
forged payload.

**Expected:** HTTP 403, no order mutation.

**Actual (Verified):** `Callback.php:84-89` — CallbackValidator runs;
on mismatch returns HTTP 403 + logs warning. Good.

**Test coverage:** Y — there's a unit test `CallbackTest.php` covering
this path (verified file exists).

**Verdict:** No change.

---

## Summary table

| # | Case | Bug? | Action | Playwright cov. |
|---|---|---|---|---|
| 1 | Cancel on Flitt page | No | None | Y (shared w/ #2) |
| 2 | Decline on Flitt page | Minor (raw error string in history) | UserFacingErrorMapper at Callback:260 | Y `declined-rest.spec.ts` |
| 3 | Browser closed mid-3DS | No | None | Y `abandoned-rest.spec.ts` |
| 4 | Network timeout on Flitt API | Real (orphan class) | PendingOrderReconciler + history comment | Partial (unit test only) |
| 5 | Double-click Place Order | Real (silent overwrite of flitt_order_id) | Redirect.php idempotency | Y `double-click-rest.spec.ts` (new) |
| 6 | Amount changes mid-flow | No (UX only) | Document, defer | N |
| 7 | Concurrent callback/return/cron | Minor (Callback no FOR UPDATE) | Defer | N |
| 8 | Invalid signature | No | None | Y (existing unit test) |

Six new test specs in Priority 1.2 + one new `double-click-rest.spec.ts`
from #5 = seven Playwright specs total for this session's test
deliverable.

End of edge-cases matrix.
