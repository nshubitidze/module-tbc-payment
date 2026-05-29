# Shubo_TbcPayment — Test Coverage Matrix

Honest map of what is exercised in CI versus what is not. Written at the close
of the TBC payment hardening sweep (Batch 7). Do not infer coverage from file
names alone — read this table.

## How to run

```bash
# Unit suite (fast, no DB) — the CI gate for this module.
docker compose --env-file .env.docker exec php \
    vendor/bin/phpunit --configuration phpunit.xml app/code/Shubo/TbcPayment/Test/Unit

# Static analysis + style.
docker compose --env-file .env.docker exec php \
    vendor/bin/phpstan analyse --level=8 app/code/Shubo/TbcPayment
docker compose --env-file .env.docker exec php \
    vendor/bin/phpcs --standard=phpcs.xml app/code/Shubo/TbcPayment
```

Playwright e2e lives outside the module at `tests/e2e/payments/tbc-sandbox-lifecycle/`
(REST + signed-callback specs) and is NOT part of the per-module CI gate; it runs
against a real Flitt sandbox on demand.

## Test-item coverage (T-* from the hardening plan)

| Item | What it asserts | Level | Where | Status |
|------|-----------------|-------|-------|--------|
| **T-3** | Forged / missing / empty callback signature → rejected, logged at ERROR | unit (validator) | `Gateway/Validator/CallbackValidatorTest` | COVERED |
| **T-3** | Forged signature at the controller → HTTP 403, no order mutation, no settlement, lock body never entered | unit (controller) | `Controller/Payment/CallbackCaptureTest::testForgedSignatureRejectedWith403AndNoMutation` | COVERED (added Batch 7) |
| **T-4** | Approved callback double-delivery → capture once; second is a benign no-op (state===PROCESSING short-circuit + persisted payment_id replay guard) | unit | `Controller/Payment/CallbackCaptureTest` (`testApprovedCallbackCapturesAndReturns200`, `testSecondConcurrentCallbackSeesProcessingAndDoesNotRecapture`, `testReplayedPaymentIdIsBenignNoOp`); `Service/OrderApprovalApplierTest` (registerCaptureNotification once) | COVERED |
| **T-5** | Settlement boundary money — 33.33/33.33/33.34→1001, 99.99%, 0%, fixed==total, percent>100 rejected, 50/50 remainder, mixed fixed+percent | unit (data provider) | `Service/SettlementServiceTest::percentageSplitProvider` | COVERED |
| **T-6** | Settlement defensive guards — empty receivers configured, fixed > total, all-zero percent | unit | `Service/SettlementServiceTest` (`testEmptyReceiversConfiguredSkipsSettlement`, `testFixedAmountExceedingTotalIsRejected`, `testAllZeroPercentReceiversSkipSettlement`) | COVERED (added Batch 7) |
| **T-7** | `SplitDataBuilder::build()` producer contract — split disabled→`[]`; no receivers→`[]`; receivers→payload; amount is INTEGER tetri (10.50→1050, never float) | unit | `Gateway/Request/SplitDataBuilderTest` | COVERED (added Batch 7) |
| **T-8** | Reconciler every status — approved (direct + preauth), declined→cancel, expired→cancel, created/processing→retry no-mutation, unknown→warn, approved + settlement-throws→capture kept + logged; plus attempt-counter / terminal-cap / terminal-age / backlog alerts | unit | `Cron/PendingOrderReconcilerTest` | COVERED (declined/expired/created/unknown/settlement-throws added Batch 7) |

### Carve-outs (deliberately NOT tested)

- **`InitializeRequestBuilder`** was DELETED in Batch 1. The original T-7 mentioned
  asserting "callback/response URLs present" — that was this deleted builder's
  concern. `SplitDataBuilder` only emits the receiver payload, so the URL
  assertions are intentionally absent.
- **`SplitDataBuilder` + `SplitPaymentData*` are KEPT but UNWIRED** (IMPROVE-1
  carve-out): no `di.xml` entry currently feeds `build()` into a live gateway
  command. `SplitDataBuilderTest` documents the producer contract so a future
  rewire is safe — it does not assert a live request path, because there isn't one.

## What is NOT exercised in CI (integration / e2e gaps)

The module's integration-test infrastructure is thin (a CI integration-install fix
is in flight on a separate branch). The following need a real DB / framework and
are stated as **deferred-integration** with the precise assertion each must make
when the harness lands. None of these are faked at unit level.

| Item | Precise assertion to wire | Why it needs real-DB integration | Status |
|------|---------------------------|-----------------------------------|--------|
| **T-2** | Fire Callback + Confirm + Cron concurrently for one approved order → exactly ONE invoice row, exactly ONE Payout ledger entry, order ends PROCESSING once. | The `PaymentLock` is a real `GET_LOCK`/advisory lock + `SELECT … FOR UPDATE`; unit tests stub `withLock` to run the callable inline, so true serialization across processes is unprovable without a real DB and concurrent connections. | DEFERRED-INTEGRATION |
| **T-9** | After `SetPendingPaymentState` runs in the place-order flow, reload the order from the DB and assert `state === pending_payment` and `status` persisted (not just set in memory). | The observer mutates the order; only a real save + reload proves persistence. `SetPendingPaymentStateTest` (unit) proves the in-memory mutation only. | DEFERRED-INTEGRATION |
| **T-11** | Flitt token endpoint down at place-order → customer sees the friendly "Payment initialization failed. Your order has been cancelled — please try again." message AND no order is left stranded in `pending_payment` (it is cancelled, or never created). | Requires the real checkout → quote → order pipeline plus a stubbed-down Flitt HTTP client at the framework layer. The friendly-copy mapping is unit-covered (`UserFacingErrorMapperTest`); the end-to-end "no stranded order" guarantee is not. | DEFERRED-INTEGRATION |

When the integration harness lands, add these under
`Test/Integration/` as `@magentoDataFixture`-driven tests
(`Test/Integration/Controller/Adminhtml/Order/ViewTest.php` is the existing
seed for the integration directory layout).

## Card entry / 3DS / iframe (T-10) — covered ONLY at the callback-contract level

Real card entry, the 3DS challenge, and the Flitt embedded iframe are **NOT**
driven by an automated test. Headless iframe automation of the Flitt embed proved
brittle (selector churn, cross-origin 3DS frames), so the lifecycle is instead
covered by REST + signed-callback specs that bypass the iframe:

`tests/e2e/payments/tbc-sandbox-lifecycle/*-rest.spec.ts`
- `happy-path-rest` — guest → REST init → signed approved callback → order PROCESSING + invoice + commission row
- `declined-rest` — declined callback → order cancelled, friendly copy
- `full-refund-rest` / `partial-refund-rest` — credit-memo → Flitt reverse → ledger reversal
- `abandoned-rest` — no callback → reconciler cancels after lifetime
- `double-click-rest` — concurrent place-order → single order/invoice

The old iframe-driven specs are retained for historical reference as
`*.iframe-skipped.spec.ts.bak` (the `.bak` extension means Playwright never
collects them — they imply NO running coverage). They are superseded by the
`*-rest.spec.ts` files above.

### MANUAL pre-launch checklist (real card / 3DS)

Because the iframe + 3DS challenge is not automated, the following MUST be run by
hand against the Flitt **sandbox** before any production cutover, and once more as
a smoke against production (using a real card — see
`Console/Command/SwitchToProdCommand` warning copy):

1. Storefront checkout → select **TBC Bank (Flitt)** → the embedded card form
   renders (no console errors, branding/theme per config).
2. Enter the Flitt **3DS-frictionless** success card → order moves to PROCESSING,
   invoice created (Authorize & Capture) or funds held (Authorize Only).
3. Enter a Flitt **3DS-challenge** card → the challenge frame appears, completes,
   and the order finalises.
4. Enter a Flitt **decline** card → storefront shows the friendly localized error
   (no raw Flitt `error_message` leaks), order is cancelled.
5. **Apple Pay / Google Pay** wallet buttons render when enabled and complete a
   wallet payment.
6. Admin → order view shows the payment-info block (Payment ID, masked card, RRN,
   3DS status, fee, settlement details) and the correct action button
   (Capture / Void / Settle) per payment action + split config.
7. Production smoke: run `tests/e2e/payment-prod-cutover-smoke.spec.ts` immediately
   after `tbc:switch-to-prod` (the command prints this reminder).
