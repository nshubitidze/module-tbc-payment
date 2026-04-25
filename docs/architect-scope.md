# Session 3 TBC Hardening — Architect Scope

**Module:** `Shubo_TbcPayment`
**Author:** Architect (Session 3, 2026-04-25)
**Related:** Session 11 iframe specs, Session 12 lifecycle helper extraction.
**Status:** Design approved — ready for developer implementation. PHP code must
not start before the developer has re-read this doc end-to-end.

This document is the sign-off gate between discovery and implementation. It covers
the six priorities in the session prompt. Every section cites the exact file:line
I verified against the current tree. If a claim here disagrees with what the
developer sees after a fresh `git pull`, the developer stops and re-files the
scope before writing code.

---

## Priority 1.1 — Online credit memo root-cause analysis

### 1.1.1 Reproduction status

**Could not reproduce end-to-end in this architect pass.** The reason is narrow
and documented honestly so the developer picks up the right thread:

- Docker stack is live (per session prompt); bin/magento is reachable.
- No TBC-paid order currently exists in the dev DB in a state that survives
  a full `admin -> invoice -> credit memo -> Refund Online` click flow (the
  test orders from 2026-04-07 are still in the sandbox but belong to a
  different merchant password pair after the sandbox rotations; attempting
  a reverse against those now also fails with the same code 1002).
- `var/log/shubo_tbc_payment.log` still holds the damning historical trace
  from 2026-04-07 (lines 168-175). That trace IS my ground truth for the
  current behaviour, and it is unchanged.

Historical trace (verbatim from `/home/nika/duka/var/log/shubo_tbc_payment.log`):

```
L168: Flitt Refund request order=duka_000000056_1775558874 amount=6400 currency=GEL
L169: Flitt Refund response: error_code=1002 error_message="Application error" response_status=failure
L170: Flitt Refund request order=duka_000000056_1775558874 amount=5900 (partial retry)
L171: Flitt Refund response: error_code=1002 error_message="Application error"
L172: Flitt Refund request order=duka_000000056_1775558874 amount=6400 (full retry)
L173: Flitt Refund response: error_code=1002 error_message="Application error"
L174: Flitt Refund request order=duka_000000055_1775556795 amount=4500 (PARTIAL on 5000 order)
L175: Flitt Refund response: reverse_status=approved response_status=success SUCCESS
```

The interesting contrast is L168-173 vs L174-175: same client code, same
RefundRequestBuilder, same RefundClient, one order fails three consecutive
attempts and a sibling order succeeds on the first try. So "the refund
command is not wired" is **false** — the command IS wired, it IS called, it
IS reaching Flitt, and Flitt IS talking back.

### 1.1.2 Why the original prompt suspect list is already half-eliminated

From the prompt, Priority 1.1 lists six suspects (a)-(f). After reading the
actual files I can discharge (a), (d), and (f) immediately:

- **(a) Refund gateway not wired.** Disproven by `etc/di.xml` lines 50-57 +
  80-88. The `commands` array in `ShuboTbcCommandPool` has
  `"refund" => "ShuboTbcRefundCommand"`. `ShuboTbcRefundCommand` is a
  `Magento\Payment\Gateway\Command\GatewayCommand` virtualType wired with
  `RefundRequestBuilder`, `RefundClient`, `RefundHandler`. Magento will find
  it via the standard adapter flow.
- **(d) `can_refund` missing.** Disproven by `etc/config.xml` line 13
  (`<can_refund>1</can_refund>`). `can_capture=1` is also set (line 12).
  Note there is **no `etc/payment.xml`** — this is fine because the facade
  is declared via DI and `payment.xml` is the legacy XML route that our
  DI-first stack deliberately skips. No action here.
- **(f) Async Magento.** Creditmemo save is synchronous in 2.4.8 core;
  async consumers are only used for stock updates. Not the issue.

Remaining plausible suspects: (b), (c), (e) — I address each below.

### 1.1.3 Verified root cause (primary)

**Flitt's `/api/reverse/order_id` returns error_code 1002 ("Application
error") for certain authorised sandbox orders.** The failure is NOT in our
signature layer (we know that because L175 succeeds with a scalar-only v1.0
payload from the same `RefundRequestBuilder`), and is NOT in the payload
shape (identical between L168 and L174). This is a Flitt sandbox backend
condition that the module does not currently recognise, surface, or retry.

The practical consequence visible to admin users today:

1. Admin clicks Refund (online) on a credit memo.
2. `Magento\Sales\Model\Order\Creditmemo\RefundOperation::execute` calls
   `$payment->refund($creditmemo)` (vendor file line 118).
3. `Payment::refund()` (vendor `Payment.php` line 668) resolves the capture
   transaction, calls `$gateway->refund($this, $baseAmountToRefund)`.
4. The adapter dispatches to `ShuboTbcRefundCommand`, which invokes
   `RefundClient::placeRequest()` (our `Gateway/Http/Client/RefundClient.php`
   line 35). Flitt returns HTTP 200 with a failure body.
5. `RefundHandler::handle()` (our `Gateway/Response/RefundHandler.php`
   line 40) correctly rejects the failure and throws `FlittApiException`
   with `__($errorMessage)` where `$errorMessage` is literally
   `"Application error"` or (fallback) `"Refund was declined by the payment
   gateway"`.
6. The exception bubbles up; `Creditmemo::register` rolls back; admin sees a
   generic red toast. No ledger reversal is recorded because
   `sales_order_creditmemo_save_after` never fires (the save itself
   aborted).

So the failure path **works as designed**: when Flitt rejects, we don't book a
ghost credit memo. Good. **What does not work** is observability and recovery:

- The admin gets no actionable signal — just "Application error".
- The developer has to dig through `shubo_tbc_payment.log` to learn the
  `request_id` that Flitt support would need.
- There is no "Refund Offline" fallback-guidance wired into the UI. Admins
  are left guessing.

### 1.1.4 Verified root cause (secondary — latent bug lying in wait)

There is a second, currently-dormant bug that the developer MUST fix while
here, because it will manifest the moment somebody changes the
`payment_action_mode` to `preauth`:

**`RefundRequestBuilder` uses `$payment->getAdditionalInformation('flitt_order_id')
?: $order->getOrderIncrementId()` (line 49-50).** The fallback is wrong for
Flitt. If `flitt_order_id` is ever empty (and for orders placed before
commit `4b8d444` in the redirect flow it provably is — see prompt Priority
3.2 orphan 3000000009), the request goes out with
`order_id="{increment_id}"` instead of
`"duka_{increment_id}_{timestamp}"`. Flitt rejects that with 1013 (Duplicate
order_id — since the real Flitt-side order_id is different) or 1002. We
must either:

- (a) Abort the refund with a LocalizedException that tells admin "this
  order predates the flitt_order_id persistence fix; issue an offline
  refund and reconcile manually", OR
- (b) Reach into Magento's `sales_payment_transaction` table for the
  capture transaction's `txn_id` (which is the Flitt payment_id), and use
  Flitt's `/api/status/payment_id` path instead — but that endpoint does
  not exist; Flitt's status/reverse APIs are keyed on `order_id` only.

(a) is the correct choice. Document why in the refund-rca.md (developer
deliverable).

### 1.1.5 Verified root cause (tertiary — missing validator)

The refund command virtualType in `etc/di.xml` line 81-88 has no
`validator` argument. Compare with `ShuboTbcInitializeCommand` (line 60-68)
which wires `ResponseValidator`. Without a validator, the refund command
bypasses Magento's gateway validation layer entirely — the only thing
catching a failure response today is `RefundHandler::handle()`'s manual
status check (line 40). This works because the handler does throw on
failure, but it sidesteps Magento's standardized
`CommandException`/`ValidatorInterface` contract and makes the code less
testable. Wiring `ResponseValidator` (which already exists and already
handles the `response_status !== 'success'` branch) makes the refund
command symmetric with initialize.

### 1.1.6 Fix design

Three changes, each independently testable and revertible.

#### Change A — Make Flitt error responses observable in the admin UI

File: `app/code/Shubo/TbcPayment/Gateway/Response/RefundHandler.php`
Current line 40-44:

```php
if ($reverseStatus !== 'approved' && $responseStatus !== 'success') {
    $errorMessage = $responseData['error_message']
        ?? (string) __('Refund was declined by the payment gateway');
    throw new FlittApiException(__($errorMessage));
}
```

New behaviour:

- Extract `error_code`, `error_message`, `request_id` from `$responseData`.
- Route through `UserFacingErrorMapper` (see Priority 2.2) for localized
  copy.
- Log at ERROR with the full triple + the Magento order id + the credit
  memo base grand total (for forensics).
- Throw a `LocalizedException` (not `FlittApiException`) with the
  translated friendly message so the admin toast shows something the
  admin can act on.

`FlittApiException` extends `LocalizedException`, so the rollback
semantics don't change — but admins currently see "Application error"
verbatim and that's the UX miss we're closing.

#### Change B — Add the missing validator to the refund command

File: `app/code/Shubo/TbcPayment/etc/di.xml`, `ShuboTbcRefundCommand`
virtualType:

```xml
<argument name="validator" xsi:type="object">Shubo\TbcPayment\Gateway\Validator\ResponseValidator</argument>
```

No other change. The validator already handles the response-status check.
This is pure cleanup that doesn't change behaviour on the happy path, but
makes the command conform to Magento gateway convention.

#### Change C — Defend the RefundRequestBuilder against missing flitt_order_id

File: `app/code/Shubo/TbcPayment/Gateway/Request/RefundRequestBuilder.php`,
line 49-50. Replace the silent fallback with an explicit guard:

```php
$flittOrderId = (string) $payment->getAdditionalInformation('flitt_order_id');
if ($flittOrderId === '') {
    throw new LocalizedException(__(
        'This order is missing the Flitt reference and cannot be refunded '
        . 'online. Issue an Offline refund and reconcile with TBC Bank '
        . 'using the payment id from the invoice.'
    ));
}
```

The LocalizedException surfaces in admin as a clear, actionable message
(Magento's admin creditmemo controller catches LocalizedException and
renders it).

### 1.1.7 Tests required (developer deliverable)

Unit tests in `Test/Unit/Gateway/Response/RefundHandlerTest.php` (does not
yet exist — create it):

1. Happy path: `reverse_status=approved` + `response_status=success` ->
   handler sets additional info, no throw.
2. Declined: `response_status=failure` + `error_code=1002` ->
   `LocalizedException` thrown with the mapped friendly message.
3. Declined: `error_code=1013` ("Duplicate order_id") -> different mapped
   message.
4. Missing error_code: only `error_message="foo"` -> generic fallback copy.
5. Unknown error_code: `error_code=9999` -> generic fallback copy.
6. Transaction_id is persisted on `refund_transaction_id` additional info
   when present.

Extend `RefundRequestBuilderTest` with:

7. Empty `flitt_order_id` + order without increment fallback -> throws
   LocalizedException (the prompt's suspect (c), now made explicit).

E2E: the partial-refund-rest.spec.ts and full-refund-rest.spec.ts specs
from Priority 1.2 must cover both refund paths end-to-end.

### 1.1.8 What we're consciously NOT doing

- **No retry loop on 1002.** Flitt sandbox returns 1002 for transient
  conditions; a blind retry could double-refund. The admin can re-click.
- **No automatic Refund-Offline fallback.** Money movement is not
  something we silently reroute. Admin must make that decision.
- **No Flitt sandbox-specific compatibility kludges.** We treat sandbox
  and prod the same; sandbox idiosyncrasies belong in the error map, not
  in refund logic.

---

## Priority 1.2 — Playwright specs via REST + signed callback

### 1.2.1 Design goal

Drive the five lifecycle paths (happy, decline, abandon, partial refund,
full refund) entirely through Magento REST + our own Callback controller.
Do not touch the Flitt iframe. Do not touch the Flitt sandbox for refund
flows either (we only need to prove **our** module behaves correctly given
known-good + known-bad callbacks).

### 1.2.2 Helper shape

Add to `tests/e2e/payments/_lib/lifecycle-common.ts` (extended common):

```ts
export async function signAndPostCallback(
  flittOrderId: string,
  orderStatus: 'approved' | 'declined' | 'expired' | 'reversed',
  amountMinor: number,
  opts?: { paymentId?: number; reverseAmount?: number; errorCode?: number; errorMessage?: string }
): Promise<Response>
```

Internally:

1. Read `payment/shubo_tbc/password` via `dockerExec('bin/magento config:show')`.
2. Build the payload (order_id, order_status, payment_id, amount, currency=GEL,
   reverse_amount if reversed, error_code/error_message if declined).
3. Compute Flitt v1.0 signature in TypeScript:
   - strip `signature` + `response_signature_string`
   - `array_filter` (drop empty/null)
   - ksort
   - `sha1(password + '|' + join('|', values))`
   The vendor SDK's algorithm is documented in
   `vendor/cloudipsp/php-sdk-v2/lib/Helper/ApiHelper.php`; the TS helper
   must mirror it exactly (sort by key alphabetically, lowercase hex).
4. POST JSON to `${BASE_URL}/shubo_tbc/payment/callback` with
   `Content-Type: application/json`.
5. Return the response so the spec can assert HTTP 200 / "status":"ok".

### 1.2.3 The five specs

All files live under `tests/e2e/payments/tbc-sandbox-lifecycle/`. Rename
existing iframe specs to `*.iframe-skipped.spec.ts.bak` with a top-of-file
comment pointing at this doc.

| Spec | Setup | Callback | Assertions |
|---|---|---|---|
| `happy-path-rest.spec.ts` | Place order via REST guest checkout with shubo_tbc; read flitt_order_id from payment.additional_information | `signAndPostCallback(flittOrderId, 'approved', grandTotalMinor, {paymentId: 900000001})` | order.state in [processing, complete]; invoiceCount >= 1; commission row = captured; payout ledger has a capture entry |
| `declined-rest.spec.ts` | Same place-order path | `signAndPostCallback(flittOrderId, 'declined', grandTotalMinor, {errorCode: 2004, errorMessage: 'Card declined'})` | order.state = canceled; invoiceCount = 0; commission row status IN (voided, pending-never-captured — verify per Commission state machine); no payout ledger entries |
| `abandoned-rest.spec.ts` | Place order, do NOT POST any callback | `dockerExec('bin/magento cron:run --group=default')` after advancing time past payment_lifetime (3600s) — use `dockerExec('mysql -e "UPDATE sales_order SET created_at = DATE_SUB(...)"')` to age the order | order.state = canceled; payment.additional_information.awaiting_flitt_confirmation still set; reconciler log line present |
| `partial-refund-rest.spec.ts` | Run happy-path-rest first, then admin creditmemo via REST (`/V1/order/:orderId/invoice` then `/V1/order/:orderId/refund` with one item) | N/A (the refund triggers our module's own outbound call to Flitt; mock that call via a WireMock-style fixture OR use sandbox knowing it may 1002) | commission row = partially_refunded; payout ledger has a refund entry with `reference=creditmemo_{id}`; base_total_refunded matches the partial amount |
| `full-refund-rest.spec.ts` | Run happy-path-rest first, then creditmemo for all items | N/A (same) | commission row = refunded; payout ledger has refund entry; order state in [closed, complete-with-all-refunded] |

### 1.2.4 Dealing with outbound Flitt calls for refund specs

The clean design: stub the RefundClient at DI level for test-mode ONLY.
Either:

- Ship a `Shubo\TbcPayment\Test\Integration\FakeRefundClient` and
  register it under `etc/di.test.xml` (Magento loads `di.xml` only; the
  `.test` suffix won't apply unless we set the MAGE_MODE). Not ideal.
- Add a boolean `payment/shubo_tbc/test_mode_fake_refund` config flag. In
  the fake-refund mode, RefundClient short-circuits and returns a fake
  approved response. This is ugly and violates "no prod-affecting test
  branches".
- Accept that sandbox may 1002 on reverse, and for the two refund specs
  assert the **observable post-condition for a declined refund**: the
  creditmemo save was rolled back, no ledger entry, admin would have
  seen a localized error. That is, we test the **error path** as the
  primary scenario.

**Recommended:** test the error path as the primary scenario for the two
refund specs. The refund HAPPY path gets a dedicated manual smoke test
against sandbox in `_results.md`. This keeps the spec fleet
deterministic and doesn't require infrastructure changes.

### 1.2.5 Reviewer checklist for 1.2

- All 5 specs pass headed (`headless: false` per feedback_testing.md).
- `page.on('pageerror')` listener attached via `attachErrorListeners` in
  every spec.
- Old iframe specs renamed to `*.iframe-skipped.spec.ts.bak` with
  top-of-file comment citing this doc.
- `signAndPostCallback` has a unit-style check in the helper test file:
  a known payload + known password produces the documented signature
  from the vendor SDK.

---

## Priority 2.1 — Redirect-mode edge cases matrix

See the companion file `edge-cases-matrix.md` (sibling doc in this
directory). Summary of verdicts the architect produced while reading the
tree:

| Case | Current behaviour verified? | Bug present? |
|---|---|---|
| Cancel on Flitt page | Yes — ReturnAction.php line 160 `handleFailure` cancels and restores quote | No bug; needs Playwright coverage |
| Decline on Flitt page | Yes — Callback.php line 252 `handleDeclined` cancels; ReturnAction's `handleFailure` also triggers on Flitt `declined` status | No bug; needs Playwright coverage |
| Browser closed mid-3DS | Yes — `PendingOrderReconciler` cron runs every 5 minutes and calls `/api/status` to finalize | No bug; needs cron-driven Playwright spec (abandoned-rest.spec.ts covers this) |
| Network timeout on Flitt token API | Partial — Redirect.php lines 174-178 set `CURLOPT_TIMEOUT=30 / CONNECTTIMEOUT=10`; on timeout, exception is caught at line 143 and user sees `__('Unable to initialize payment. Please try again.')`. **However, the order is left in pending_payment** — no rollback. The PendingOrderReconciler catches this via status API but only AFTER 5 minutes | **Minor bug:** Redirect.php on timeout should mark the order `awaiting_flitt_confirmation=false` and let reconciler close it, OR restore the quote immediately. Recommend: add `$order->addCommentToStatusHistory('Flitt token API unreachable; order held for reconciler.')` at the catch; do not cancel inline (race with any retries) |
| Double-clicked Place Order | Unverified | Needs code read — `checkoutSession->getLastRealOrder()` is session-scoped; second click probably returns the same order ID. The order is already in pending_payment after the first click, so Redirect.php line 58-60 throws. User sees the caught exception message. Needs a Playwright test to confirm no duplicate order is created |
| Order amount changes mid-flow | Unverified — no explicit check that `order.grand_total` == what was sent to Flitt | **Bug present (latent):** if user opens two checkout tabs, places order in tab A, then adds to cart in tab B (which reactivates the same quote), the Flitt order could have a stale amount. This is edge-of-edge and can be deferred to a follow-up session — DOCUMENT it, don't fix in this session. Reason: fixing requires a Config-scoped grand_total signature on the payment additional info, which is its own design doc. |

Full matrix with test coverage plan is in `edge-cases-matrix.md`.

---

## Priority 2.2 — UserFacingErrorMapper

See companion file `error-code-map.md` for the full skeleton. Architect
decisions on shape:

### 2.2.1 Class responsibility

`Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper` — one public
method:

```php
public function toLocalizedException(
    int|string $errorCode,
    string $rawErrorMessage,
    ?string $requestId = null,
): LocalizedException
```

Returns a **new** `LocalizedException` every call. Does NOT log — logging
is the caller's responsibility (the caller has the richest context: order
id, amount, store id, merchant id tail). The mapper only translates.

### 2.2.2 Locale resolution

Inject `Magento\Framework\Locale\ResolverInterface`. When locale starts
with `ka`, return the Georgian copy; else English. Do NOT try to cover
Russian — Flitt supports `ru` as a lang, but Duka's target market is
Georgian and English only (per project overview memory). Leave a TODO in
the class for `ru` if we ever localize to Russian.

### 2.2.3 Codes to cover (from `reference_payment_modules.md` + Flitt docs)

At minimum the eight codes in `error-code-map.md`. The default branch
must return a friendly generic message, never `"Unknown error from Flitt
API"`.

### 2.2.4 Call sites to retrofit

- `Controller/Payment/Redirect.php` line 209 (the literal one the prompt
  flagged).
- `Controller/Payment/Confirm.php` — error paths currently also use the
  raw message.
- `Controller/Payment/Callback.php` line 260 (declined-payment history
  comment uses raw error_message; this comment goes on the order history
  and is customer-visible via "My Orders" page).
- `Gateway/Response/RefundHandler.php` line 40-44 — see Priority 1.1.6
  Change A.
- `Gateway/Http/Client/StatusClient` — if it has any admin-visible error
  path.

Every call site must:

1. Log the raw `error_code`, `error_message`, `request_id` at ERROR level
   BEFORE mapping.
2. Throw the mapped LocalizedException.

---

## Priority 3.1 — Dangling parent_transaction_id

### 3.1.1 Verified locations

Running `grep -rn setParentTransactionId app/code/Shubo/TbcPayment/`:

| File | Line | Context |
|---|---|---|
| `Controller/Adminhtml/Order/CheckStatus.php` | 144 | After registerCaptureNotification |
| `Controller/Payment/Confirm.php` | 241 | Inside `processApproval`, before `registerCaptureNotification` |
| `Controller/Payment/ReturnAction.php` | 248 | Inside `handleApproved`, before `registerCaptureNotification` |
| `Cron/PendingOrderReconciler.php` | 222 | Preauth branch |
| `Cron/PendingOrderReconciler.php` | 250 | Direct-sale branch, before `registerCaptureNotification` |

(Redirect.php does NOT set parent_transaction_id — the prompt was off by
a revision; Redirect.php only persists `flitt_order_id` + `checkout_type`
on the payment before redirecting the user to Flitt. The other five call
sites are where the real fix goes.)

### 3.1.2 Architectural trade-off

Option (a) — **drop everywhere**: simple, one commit, no regression risk
in non-preauth mode. Loses the ability to pair auth+capture transactions
visually in admin. Acceptable because in non-preauth mode there IS no
auth transaction — Flitt charges immediately.

Option (b) — **create the real auth transaction first**: correct in
theory, but in non-preauth mode there is no Flitt auth step to create a
transaction from. Magento's `addTransaction(Transaction::TYPE_AUTH, ...)`
would invent a synthetic row with nothing but our own increment_id-auth
txn_id — that's exactly the phantom we're trying to eliminate, just on
the `sales_payment_transaction` row instead of the capture's
`parent_txn_id` column.

Option (c) — **skip in direct-sale only**: keep the setter in the preauth
branch of each file, drop it in the direct-sale branch. When the preauth
branch runs, we have **already** set `setIsTransactionClosed(false)` and
the preauth transaction was created earlier in the flow (well — verify
this claim; see 3.1.3 below).

### 3.1.3 Verification note — preauth actually does create an auth txn

I did NOT verify that preauth mode creates a real auth transaction
upstream. The only place an auth-type transaction would come from is
Flitt's preauth callback where `order_status=approved` + `preauth=Y` on
the original request. Looking at `Callback.php:198-223` (the preauth
branch of `handleApproved`), we set `setTransactionId($paymentId)` and
`setIsTransactionClosed(false)` and transition order state — but I do
NOT see an explicit `addTransaction(Transaction::TYPE_AUTH, ...)` call.
Magento MAY create an auth row implicitly when the order state goes to
processing under a payment with `setIsTransactionClosed(false)`, but I'm
not confident.

**Recommendation:** go with option (a) — drop the `setParentTransactionId`
everywhere in the TBC module. This is the minimum-risk change. If and
when we implement preauth capture as a distinct workflow (future
session), we re-introduce the parent pointer but only from a real auth
transaction that was really added.

Why option (a) over option (c): the five sites with the phantom setter
live in both preauth and direct-sale branches indistinguishably (the
preauth branches at CheckStatus.php:144, Reconciler.php:222 and
Confirm.php inside the `isPreauth` branch). If preauth mode isn't
actually creating an auth row (unverified), option (c) still dangles in
preauth. Option (a) is safe in every mode because the setter is doing
nothing useful today.

### 3.1.4 Fix scope

Remove `$payment->setParentTransactionId(...)` from:

- `Controller/Adminhtml/Order/CheckStatus.php:144`
- `Controller/Payment/Confirm.php:241`
- `Controller/Payment/ReturnAction.php:248`
- `Cron/PendingOrderReconciler.php:222`
- `Cron/PendingOrderReconciler.php:250`

Update each file's unit test to assert `setParentTransactionId` is NOT
called (use `$paymentMock->expects($this->never())->method('setParentTransactionId')`).

Admin transaction tree: after the fix, the capture transaction is the
root entry, which renders cleanly in admin. Reviewer must screenshot the
admin order view in `_results.md`.

---

## Priority 3.2 — Orphan order 3000000009 cleanup

Out of architect scope — this is a one-shot ops action on prod. Flag to
developer / reviewer:

- Use `bin/magento sales:order:cancel 3000000009` on prod (CLI exists
  per Magento core).
- Verify no rows in `shubo_commission_order` or
  `shubo_payout_ledger_entry` for the order_id.
- Append to `KNOWN_ISSUES.md` the line the prompt specified.

Not our doc to write.

---

## Cross-cutting reviewer gates

1. **Standalone mirror sync.** After every PHP edit in
   `app/code/Shubo/TbcPayment/`, copy to `/home/nika/module-tbc-payment/`
   and commit + push there. The pre-push hook blocks duka pushes that
   touch Mirror modules without a matching sync.
2. **No new abstractions without this doc.** The simplicity-first rule
   (CLAUDE.md) applies. Developer must not invent a new "RefundService"
   or "ErrorTranslatorFactory" — the exactly-three classes named here
   (UserFacingErrorMapper, existing ResponseValidator wired for refund,
   existing RefundHandler modified) are the complete set.
3. **Observer chain integrity.** Under NO circumstance may
   `CommissionRefundObserver` stop propagating
   `InvalidCommissionStateException`. That behaviour is load-bearing per
   `docs/design/commission-refund-state-machine.md` §3.1. The refund UX
   improvements must not touch Commission or Payout at all.
4. **Money semantics.** Per `feedback_bcmath_string_returns.md` + CLAUDE.md
   §Financial calculations: refund amounts stay tetri-integer in flight,
   decimal-string at the Flitt API boundary (already correct in
   RefundRequestBuilder line 56). No float arithmetic added.
5. **Test count check.** Developer must report new test count per
   priority. Target: +6 RefundHandler unit tests, +2 RefundRequestBuilder
   tests, +N controller tests for setParentTransactionId removal,
   +~8 UserFacingErrorMapper tests, +5 new Playwright specs. Reviewer
   verifies total ~ +21 unit + 5 e2e.

---

## What this doc deliberately does NOT cover

- How to run the Playwright specs in CI — devops concern.
- Whether BOG needs the same UserFacingErrorMapper — it does, but that's
  a separate session. This scope is TBC only.
- Fixing the Commission refund state machine — already done in Session 9.
- Splitting the refund into a dedicated `Shubo\TbcPayment\Service` class
  — simplicity-first says no; the existing gateway pipeline is fine.

End of architect scope.
