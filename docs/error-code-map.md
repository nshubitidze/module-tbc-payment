# Flitt Error Code -> User-Facing Copy Map

**Module:** `Shubo_TbcPayment`
**Session:** 3 (2026-04-25) Priority 2.2
**Author:** Architect (this doc is the SKELETON — developer finalizes the
Georgian copy with Nika before shipping.)
**Status:** Design — implementation goes in
`Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper`.

---

## 1. Principles

1. **Never leak the raw Flitt string to the user.** Raw strings always log
   at ERROR level with `error_code`, `error_message`, `request_id`, and
   the Magento order/creditmemo context.
2. **Two locales only — `ka` and `en`.** Georgian for Georgian-locale
   stores, English everywhere else. Russian is deferred (see architect
   scope §2.2.2).
3. **Actionable copy.** Each message tells the user what to do next:
   try again, try another card, contact support, etc. Never just
   "something went wrong."
4. **Buckets by code range, not one-per-code.** Flitt publishes hundreds
   of codes; mapping each is waste. Map the top 8 explicitly + 5 ranges.
5. **Default copy is NOT "Unknown error".** It is the generic
   "system-error-please-retry" copy from row #13.

---

## 2. Mapping table

The mapper method signature:

```php
public function toLocalizedException(
    int|string $errorCode,
    string $rawErrorMessage,
    ?string $requestId = null,
): \Magento\Framework\Exception\LocalizedException
```

Input `$errorCode` may be int or string (Flitt sometimes returns int,
sometimes string in different endpoints). Cast to int inside the mapper
before matching.

| # | Flitt code(s) | Flitt meaning | ka (Georgian) copy | en (English) copy | Exception class |
|---|---|---|---|---|---|
| 1 | `1002` | Application error (generic server-side fault) | სისტემური შეცდომა. გთხოვთ, სცადოთ ცოტა ხანში ხელახლა. | System error. Please try again in a moment. | `LocalizedException` |
| 2 | `1006`, `2003` | Merchant configured incorrectly | გადახდის სისტემის კონფიგურაციის შეცდომა. დაუკავშირდით მხარდაჭერას. | Payment system configuration error. Please contact support. | `LocalizedException` |
| 3 | `1011` | Parameter missing / order_id not found | გადახდის ინფორმაცია ვერ მოიძებნა. დაუკავშირდით მხარდაჭერას. | Payment information not found. Please contact support. | `LocalizedException` |
| 4 | `1013`, `2004` | Duplicate order_id | გადახდა უკვე დამუშავებულია. შეამოწმეთ თქვენი შეკვეთები. | This payment has already been processed. Please check your orders. | `LocalizedException` |
| 5 | `1014`, `2007` | Invalid signature | გადახდის სისტემის კონფიგურაციის შეცდომა. დაუკავშირდით მხარდაჭერას. | Payment system configuration error. Please contact support. | `LocalizedException` |
| 6 | `1016` | Merchant not found | გადახდის სისტემა დროებით მიუწვდომელია. სცადეთ მოგვიანებით. | Payment system temporarily unavailable. Please try later. | `LocalizedException` |
| 7 | `1027` | Preauth not allowed for merchant | გადახდის მეთოდი ამ შეკვეთისთვის არ არის ხელმისაწვდომი. | This payment option is not available for this order. | `LocalizedException` |
| 8 | `2001`-`2099` (card decline range) | Bank declined card | ბანკმა უარყო გადახდა. სცადეთ სხვა ბარათით ან დაუკავშირდით თქვენს ბანკს. | Your bank declined the payment. Please try another card or contact your bank. | `LocalizedException` |

### Range buckets (applied when no explicit code match):

| # | Code range | Category | ka copy | en copy |
|---|---|---|---|---|
| 9 | `1000`-`1099` (not matched above) | Auth / signature / config | გადახდა ვერ მოხერხდა. სცადეთ ხელახლა. | Payment couldn't be completed. Please try again. |
| 10 | `2100`-`2999` (not matched above) | Card / issuer decline | ბანკმა უარყო გადახდა. სცადეთ სხვა ბარათით. | Bank declined the payment. Try another card. |
| 11 | `3000`-`3999` | 3DS / strong customer auth | ბანკის დადასტურება ვერ მოხერხდა. სცადეთ ხელახლა. | Bank verification failed. Please try again. |
| 12 | `4000`-`5999` | System / infrastructure | სისტემური შეცდომა. სცადეთ ცოტა ხანში. | System error. Please try again in a moment. |
| 13 | default (unmapped or zero) | Unknown | გადახდა ვერ მოხერხდა. სცადეთ ხელახლა ან დაუკავშირდით მხარდაჭერას. | Payment couldn't be completed. Please try again or contact support. |

---

## 3. Call-site logging contract

Before throwing the mapped exception, every caller MUST log the raw
context at ERROR level using the TBC-dedicated logger
(`ShuboTbcPaymentLogger` virtualType in di.xml). Log line shape:

```
TBC Flitt error mapped to user copy
  error_code: 2004
  error_message: "Duplicate order_id"
  request_id: "abc123"
  order_increment_id: "000000042"
  creditmemo_id: 17         # if refund context
  user_locale: "ka_GE"
  mapped_row: 4             # the row # from this table
```

Logging is done BY THE CALLER, not the mapper. The mapper is a pure
function (input -> LocalizedException). This keeps it dead-simple to
unit-test.

---

## 4. Call sites to retrofit

From `architect-scope.md` §2.2.4 (restated for reviewer convenience):

1. `Controller/Payment/Redirect.php:209` — replace
   `__('Unknown error from Flitt API.')` with a
   `UserFacingErrorMapper->toLocalizedException(...)` call.
2. `Controller/Payment/Confirm.php` — error paths that currently
   surface raw message.
3. `Controller/Payment/Callback.php:260` — `handleDeclined` history
   comment (this one is customer-visible in "My Orders").
4. `Gateway/Response/RefundHandler.php:40-44` — Priority 1.1.6 Change A.
5. `Controller/Adminhtml/Order/CheckStatus.php` (if any error paths
   surface raw copy).

Each retrofit:
- Logs the raw triple (code, message, request_id) at ERROR.
- Calls `$mapper->toLocalizedException($code, $message, $requestId)`.
- Throws the returned exception (or uses its `getMessage()` for a
  history comment / admin toast).

---

## 5. Testing requirements

Unit test file:
`Test/Unit/Gateway/Error/UserFacingErrorMapperTest.php` (new).

One test per mapping row (13 tests) + edge cases:

- `testCastsStringErrorCodeToInt`: input `"1002"` as string -> matches row 1.
- `testZeroOrNullCodeFallsThroughToDefault`: input `0` / `null` -> row 13.
- `testLocaleResolutionKa`: locale `ka_GE` -> Georgian copy.
- `testLocaleResolutionEn`: locale `en_US` -> English copy.
- `testLocaleResolutionOther`: locale `ru_RU` -> English copy (Russian deferred).
- `testRequestIdDoesNotLeakIntoUserMessage`: request_id is never
  concatenated into the thrown message.

Total: ~18 new tests.

Integration/smoke: the five lifecycle Playwright specs already assert
that the Georgian copy (when locale=ka) appears in admin / order-history
views, not the raw Flitt string. Reviewer spot-checks one spec per locale.

---

## 6. Nika sign-off required before ship

The Georgian copy above is my best-effort architect translation. Nika
(native Georgian speaker) must review row 1, 3, 8, and 11 at minimum —
these are the highest-volume production cases and must read naturally.
Developer includes a `[ ] Nika approved ka copy` checkbox in
`session-3-tbc-hardening_results.md`.

End of error-code-map.
