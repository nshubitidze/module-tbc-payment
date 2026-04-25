<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Error;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Phrase;

/**
 * Translates raw Flitt (TBC) error codes + messages into friendly,
 * locale-aware copy suitable for surfacing to storefront and admin users.
 *
 * Responsibilities:
 *   - Map known Flitt error codes (scalar int or numeric string) to the copy
 *     documented in `docs/error-code-map.md` §2.
 *   - Resolve between Georgian (`ka*` locales) and English (everything else).
 *   - Produce a fresh {@see LocalizedException} per call; callers decide
 *     whether to throw, use ->getMessage() for a history comment, etc.
 *
 * Non-responsibilities:
 *   - NO logging. Callers own the raw-triple log line (they have the richest
 *     context: order id, creditmemo id, store id, etc.).
 *   - NO translation of free-form Flitt `error_message` strings. The raw
 *     message is never surfaced to users — only the mapped copy is.
 *   - NO retry/escalation logic. Pure function input -> exception.
 *
 * @see \Shubo\TbcPayment\Test\Unit\Gateway\Error\UserFacingErrorMapperTest
 * @see docs/error-code-map.md
 */
class UserFacingErrorMapper
{
    public function __construct(
        private readonly ResolverInterface $localeResolver,
    ) {
    }

    /**
     * Map a Flitt error code + raw message into a localized exception.
     *
     * `$requestId` is accepted so call sites can thread through correlation
     * identifiers if they want to include them in surrounding log lines, but
     * the mapper itself NEVER leaks the raw message, code, or request_id
     * into the user-facing copy — that would defeat the whole point.
     */
    public function toLocalizedException(
        int|string $errorCode,
        string $rawErrorMessage,
        ?string $requestId = null,
    ): LocalizedException {
        $code = $this->normalizeCode($errorCode);
        $phrase = $this->resolvePhrase($code);

        return new LocalizedException($phrase);
    }

    /**
     * Flitt returns codes as int or numeric string depending on endpoint.
     * Normalize to int; unparseable values become 0 (default bucket).
     */
    private function normalizeCode(int|string $errorCode): int
    {
        if (is_int($errorCode)) {
            return $errorCode;
        }

        $trimmed = trim($errorCode);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return 0;
        }

        return (int) $trimmed;
    }

    /**
     * Resolve the user-facing {@see Phrase} for the given normalized code.
     *
     * Ordering: explicit code match first, then code-range buckets, then the
     * default generic fallback.
     */
    private function resolvePhrase(int $code): Phrase
    {
        $isKa = $this->isGeorgianLocale();

        // ---- Row 1: 1002 Application error.
        if ($code === 1002) {
            return $isKa
                ? __('სისტემური შეცდომა. გთხოვთ, სცადოთ ცოტა ხანში ხელახლა.')
                : __('System error. Please try again in a moment.');
        }

        // ---- Row 2: 1006 / 2003 Merchant misconfiguration.
        if ($code === 1006 || $code === 2003) {
            return $isKa
                ? __('გადახდის სისტემის კონფიგურაციის შეცდომა. დაუკავშირდით მხარდაჭერას.')
                : __('Payment system configuration error. Please contact support.');
        }

        // ---- Row 3: 1011 Parameter missing / order not found.
        if ($code === 1011) {
            return $isKa
                ? __('გადახდის ინფორმაცია ვერ მოიძებნა. დაუკავშირდით მხარდაჭერას.')
                : __('Payment information not found. Please contact support.');
        }

        // ---- Row 4: 1013 / 2004 Duplicate order_id.
        if ($code === 1013 || $code === 2004) {
            return $isKa
                ? __('გადახდა უკვე დამუშავებულია. შეამოწმეთ თქვენი შეკვეთები.')
                : __('This payment has already been processed. Please check your orders.');
        }

        // ---- Row 5: 1014 / 2007 Invalid signature.
        if ($code === 1014 || $code === 2007) {
            return $isKa
                ? __('გადახდის სისტემის კონფიგურაციის შეცდომა. დაუკავშირდით მხარდაჭერას.')
                : __('Payment system configuration error. Please contact support.');
        }

        // ---- Row 6: 1016 Merchant not found.
        if ($code === 1016) {
            return $isKa
                ? __('გადახდის სისტემა დროებით მიუწვდომელია. სცადეთ მოგვიანებით.')
                : __('Payment system temporarily unavailable. Please try later.');
        }

        // ---- Row 7: 1027 Preauth not allowed for merchant.
        if ($code === 1027) {
            return $isKa
                ? __('გადახდის მეთოდი ამ შეკვეთისთვის არ არის ხელმისაწვდომი.')
                : __('This payment option is not available for this order.');
        }

        // ---- Row 8: 2001-2099 Bank-declined card (catch known + range).
        if ($code >= 2001 && $code <= 2099) {
            return $isKa
                ? __('ბანკმა უარყო გადახდა. სცადეთ სხვა ბარათით ან დაუკავშირდით თქვენს ბანკს.')
                : __('Your bank declined the payment. Please try another card or contact your bank.');
        }

        // ---- Row 9: 1000-1099 remaining auth / signature / config errors.
        if ($code >= 1000 && $code <= 1099) {
            return $isKa
                ? __('გადახდა ვერ მოხერხდა. სცადეთ ხელახლა.')
                : __('Payment couldn\'t be completed. Please try again.');
        }

        // ---- Row 10: 2100-2999 remaining card / issuer decline range.
        if ($code >= 2100 && $code <= 2999) {
            return $isKa
                ? __('ბანკმა უარყო გადახდა. სცადეთ სხვა ბარათით.')
                : __('Bank declined the payment. Try another card.');
        }

        // ---- Row 11: 3000-3999 3DS / strong customer authentication.
        if ($code >= 3000 && $code <= 3999) {
            return $isKa
                ? __('ბანკის დადასტურება ვერ მოხერხდა. სცადეთ ხელახლა.')
                : __('Bank verification failed. Please try again.');
        }

        // ---- Row 12: 4000-5999 system / infrastructure.
        if ($code >= 4000 && $code <= 5999) {
            return $isKa
                ? __('სისტემური შეცდომა. სცადეთ ცოტა ხანში.')
                : __('System error. Please try again in a moment.');
        }

        // ---- Row 13: default — unmapped or zero.
        return $isKa
            ? __('გადახდა ვერ მოხერხდა. სცადეთ ხელახლა ან დაუკავშირდით მხარდაჭერას.')
            : __('Payment couldn\'t be completed. Please try again or contact support.');
    }

    /**
     * Treat any locale whose language tag starts with `ka` as Georgian.
     * Everything else (including `ru_RU`) falls through to English per
     * architect-scope §2.2.2.
     */
    private function isGeorgianLocale(): bool
    {
        $locale = (string) $this->localeResolver->getLocale();

        return str_starts_with($locale, 'ka');
    }
}
