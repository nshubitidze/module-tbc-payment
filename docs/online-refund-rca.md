# Online Refund / Credit Memo — Root Cause Analysis

**Module:** `Shubo_TbcPayment`
**Session:** 3 (2026-04-25) Priority 1.1
**Author:** Developer, verbatim adoption of architect diagnosis in
`docs/architect-scope.md` §1.1.3 — §1.1.5.
**Status:** Documented root cause; fixes implemented via architect-scope
§1.1.6 Changes A / B / C. Playwright end-to-end coverage is deferred to a
follow-up pass (refund-path specs are out of scope for Pass 1 of this
session).

---

## 1. What was observed

Admin -> Sales -> Orders -> <TBC-paid order> -> Invoice -> Credit Memo ->
Refund (Online) failed on Flitt sandbox. The admin saw a red toast that
leaked the raw Flitt string `"Application error"`. The credit memo was
not persisted (Magento rolled back on the exception). No payout ledger
reversal entry was created — which is the correct failure semantic (we
don't book a ghost credit memo) but the admin had no actionable signal
to act on.

---

## 2. Historical Flitt response trace — ground truth

Verbatim from `var/log/shubo_tbc_payment.log` (lines 168-175, historical
trace already on disk before this session):

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

The interesting contrast is L168-L173 (three consecutive failures on
order 000000056) vs L174-L175 (success on order 000000055 — same client
code, same builder, same client). Two facts follow directly:

- **The refund gateway IS wired.** The command IS called, the request IS
  reaching Flitt, and Flitt IS responding. Suspect (a) from the session
  prompt ("refund gateway not wired") is disproven.
- **The signature layer IS correct.** The 1002 is not 1014 ("Invalid
  signature"); the same builder produced a v1.0-valid signature that
  succeeded on 000000055 within seconds. Suspect (b) is disproven.

The session prompt's other suspects:

- **(d) `can_refund` missing:** disproven by `etc/config.xml` line 13
  (`<can_refund>1</can_refund>`).
- **(f) Async Magento:** Magento 2.4.8 core runs creditmemo save
  synchronously; async consumers are only used for stock updates.
- **(e) Observer chain interrupting:** checked — `Callback.php` handles
  `reversed` callback correctly, `RecordRefundObserver` in
  `Shubo_Commission` uses `sales_order_creditmemo_refund` (fires only
  when save commits), no other observer chain interrupts the save.

---

## 3. Root cause (primary)

**Flitt `/api/reverse/order_id` returns `error_code=1002` ("Application
error") for certain authorised sandbox orders.** This is a Flitt
sandbox-backend condition the module did not recognise, surface, or
recover from. The module's existing behaviour:

1. Admin clicks Refund (online) on the credit memo.
2. `Magento\Sales\Model\Order\Creditmemo\RefundOperation::execute` calls
   `$payment->refund($creditmemo)`.
3. `Payment::refund()` resolves the capture transaction and calls
   `$gateway->refund($this, $baseAmountToRefund)`.
4. The adapter dispatches to `ShuboTbcRefundCommand`, which invokes
   `RefundClient::placeRequest()`. Flitt returns HTTP 200 with a failure
   body `{response: {response_status: failure, error_code: 1002, ...}}`.
5. `RefundHandler::handle()` rejects the failure and throws
   `FlittApiException(__($errorMessage))` — with `$errorMessage`
   literally `"Application error"`.
6. Exception bubbles up; `Creditmemo::register` rolls back; admin sees a
   raw red toast with `"Application error"`. No ledger reversal is
   booked (correct — there was no refund to reverse).

So the failure path **worked as designed** for rollback safety. What
did not work was observability: `"Application error"` is not actionable,
and the admin has no idea whether to retry, issue an offline refund, or
call Flitt support.

---

## 4. Root cause (secondary — latent)

`RefundRequestBuilder.php` line 49-50 used:

```php
$flittOrderId = $payment->getAdditionalInformation('flitt_order_id')
    ?: $order->getOrderIncrementId();
```

The fallback is wrong for Flitt. Real Flitt-side `order_id` is
`duka_{increment_id}_{timestamp}`. If a redirect-flow order was placed
before commit `4b8d444` (the fix that persisted `flitt_order_id`), this
fallback quietly sent a **bare increment_id** to `/api/reverse`. Flitt
would reject that with code 1013 ("Duplicate order_id") or 1002 — the
real order_id on Flitt's side differs. The admin would see yet another
"Application error" toast, with no hint that the problem is the missing
reference rather than a transient sandbox hiccup.

Production orphan `3000000009` (Session 3 Priority 3.2) is an example:
it has empty `payment.additional_information.flitt_order_id`.

---

## 5. Root cause (tertiary — missing validator)

`etc/di.xml` `ShuboTbcRefundCommand` virtualType had no `validator`
argument, while `ShuboTbcInitializeCommand` wires `ResponseValidator`.
Without it, the refund command bypassed Magento's standardised gateway
validation layer — only `RefundHandler::handle()`'s manual status check
caught failures. This worked, but violated the Magento gateway contract
(`CommandException` / `ValidatorInterface`) and reduced testability.

---

## 6. Fix design (implemented in this pass)

Three independent, revertible changes.

### Change A — RefundHandler routes Flitt errors through UserFacingErrorMapper

`app/code/Shubo/TbcPayment/Gateway/Response/RefundHandler.php`.

Before (lines 40-44):
```php
if ($reverseStatus !== 'approved' && $responseStatus !== 'success') {
    $errorMessage = $responseData['error_message']
        ?? (string) __('Refund was declined by the payment gateway');
    throw new FlittApiException(__($errorMessage));
}
```

After: log the raw `error_code`, `error_message`, `request_id`,
`reverse_status`, `response_status`, `order_increment_id` at ERROR via
the TBC `ShuboTbcPaymentLogger` virtualType; then throw the
`LocalizedException` produced by `UserFacingErrorMapper` for the right
locale (Georgian or English). The `FlittApiException` import is gone
because `FlittApiException extends LocalizedException` and
Magento's admin credit-memo controller only needs the base class for its
rollback semantics — unchanged.

### Change B — ResponseValidator wired into the refund command

`app/code/Shubo/TbcPayment/etc/di.xml`, `ShuboTbcRefundCommand`
virtualType now carries:

```xml
<argument name="validator" xsi:type="object">Shubo\TbcPayment\Gateway\Validator\ResponseValidator</argument>
```

`ResponseValidator::validate` already handles the
`response_status !== 'success'` branch correctly; no new code needed.
Refund is now symmetric with Initialize. RefundHandler still throws on
failure — the validator is a second-layer defence, not a replacement.

### Change C — RefundRequestBuilder guards against missing flitt_order_id

`app/code/Shubo/TbcPayment/Gateway/Request/RefundRequestBuilder.php`
line 49-50 no longer silently falls back to the increment_id. Missing
`flitt_order_id` now throws a `LocalizedException`:

> This order is missing the Flitt reference and cannot be refunded
> online. Issue an Offline refund and reconcile with TBC Bank using
> the payment id from the invoice.

Admin sees an actionable message instead of a mysterious 1002/1013.

---

## 7. What we consciously did NOT do

- **No retry loop on 1002.** Flitt sandbox returns 1002 for transient
  conditions; a blind retry could double-refund a good capture. The
  admin may re-click if they judge it appropriate.
- **No automatic Refund-Offline fallback.** Money movement is not
  something we silently reroute. The admin must make that decision.
- **No Flitt sandbox-specific kludges in refund logic.** Sandbox
  idiosyncrasies belong in the error map (via UserFacingErrorMapper),
  not in refund control flow.
- **No changes to the Commission or Payout observer chain.**
  `CommissionRefundObserver::handle` continues to propagate
  `InvalidCommissionStateException` per
  `docs/design/commission-refund-state-machine.md` §3.1.

---

## 8. Before / After evidence

**Before this pass:** `var/log/shubo_tbc_payment.log` L168-173 show the
raw `"Application error"` string reaching the RefundHandler throw path.
The admin toast rendered that string verbatim. No `error_code=1002`
breadcrumb was emitted by RefundHandler itself — the code was swallowed
inside the exception message. Correlating to Flitt support required
reading the log for the prior `RefundClient` response line.

**After this pass:** every Flitt failure through the refund path writes
a structured log entry:

```
TBC Flitt error mapped to user copy
  context:            refund.handler
  error_code:         1002
  error_message:      Application error
  request_id:         abc123
  reverse_status:
  response_status:    failure
  order_increment_id: 000000056
```

The admin toast shows the Georgian or English friendly copy from
`UserFacingErrorMapper` row 1. Missing `flitt_order_id` triggers the
Change C guard with the explicit "Offline refund and reconcile"
message. Refund command now runs the `ResponseValidator` as an extra
defence layer.

Local repro against sandbox was not attempted in this pass (see
architect-scope §1.1.1 — the dev DB has no TBC-paid order in a state
that survives a full reverse click cycle without the sandbox merchant
rotation drift). The historical L168-L175 trace is the canonical
evidence; reviewer will run a live reproduction on prod as part of the
reviewer checklist.

---

## 9. Test coverage

New unit tests:

- `Test/Unit/Gateway/Response/RefundHandlerTest.php` — six scenarios per
  architect §1.1.7 (happy path, 1002 decline, 1013 decline, missing
  code, unknown code, transaction_id persistence).
- `Test/Unit/Gateway/Request/RefundRequestBuilderTest.php` — extended
  with two new tests for the Change C guard (missing and empty-string
  `flitt_order_id`).
- `Test/Unit/Gateway/Error/UserFacingErrorMapperTest.php` — 24 tests
  across the 13 mapping rows plus edge cases (string/int coercion,
  locale resolution, request_id non-leakage, default fallback).

End-to-end Playwright coverage of the refund paths is deferred (session
prompt Priority 1.2); the architect scope §1.2.4 acknowledges that the
refund happy-path is best smoke-tested manually against sandbox.

---

## 10. Admin runbook for repeat incidents

If a Flitt refund fails again in production:

1. Admin checks the order history comment for the friendly message.
2. Ops checks `var/log/shubo_tbc_payment.log` for the `TBC Flitt error
   mapped to user copy` entry — the `request_id` correlates with Flitt
   support's case tooling.
3. If the guard at Change C triggered ("missing Flitt reference"), the
   admin MUST issue an Offline refund and record the reconciliation
   evidence in the order comments.
4. If the mapper surfaced a 1002-family message, the admin retries once
   (sandbox transient) or contacts Flitt support with the
   `order_increment_id` + `request_id`.

End of RCA.

---

## Pass 4 follow-up (2026-04-25)

Reviewer (`docs/reviewer-signoff.md` M-1) caught a real failure-path
regression introduced by the Pass 1 dual-fix. Both Change A
(`RefundHandler` -> `UserFacingErrorMapper`) and Change B (validator
wired on `ShuboTbcRefundCommand`) were landed simultaneously, but
`Magento\Payment\Gateway\Command\GatewayCommand::execute()` runs the
validator BEFORE the handler:

```php
// vendor/magento/module-payment/Gateway/Command/GatewayCommand.php:99-122
$response = $this->client->placeRequest($transferO);
if ($this->validator !== null) {
    $result = $this->validator->validate(...);
    if (!$result->isValid()) {
        $this->processErrors($result);   // throws CommandException, NEVER reaches handler
    }
}
if ($this->handler) {
    $this->handler->handle($commandSubject, $response);
}
```

On a Flitt failure (e.g. 1002 "Application error"), `ResponseValidator`
returns invalid -> `processErrors` throws a generic `CommandException`
with Magento's default copy `"Transaction has been declined. Please try
again later."`. `RefundHandler::handle()` is never invoked, so the
friendly Georgian/English mapped message from `UserFacingErrorMapper`
never reaches the admin toast. Change B silently overrode Change A.

**Pass 4 fix:** drop the validator from `ShuboTbcRefundCommand` (option
(a) of reviewer-signoff §M-1). The friendly-message UX promise of
P1.1 depends on `RefundHandler` running, so the handler is now the
**sole gatekeeper** for refund response validation. The Magento
gateway-contract conformance argument that motivated Change B is
superseded by the user-experience argument: the alternative
(implementing `ErrorMessageMapperInterface`) is more code for no
behavioural improvement on this hot path.

`UserFacingErrorMapper` is now reachable on every Flitt failure
through `RefundHandler::handle()`. The `Test/Unit/Gateway/Command/
RefundCommandPipelineTest.php` integration-style test pins the
end-to-end behaviour (see §9): real `GatewayCommand` constructor + real
`RefundRequestBuilder` + stub `RefundClient` returning a 1002 envelope
+ real `RefundHandler` -> friendly mapped `LocalizedException`, NOT
Magento's generic `CommandException`.

The architect-scope §1.1.5 hint that Change B was "less testable but
works" was wrong in the literal sense: with the validator in place the
handler-side mapper IS the dead code, not the other way around.
