<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Response;

use Magento\Sales\Model\Order\Payment;

/**
 * Single source of truth for Flitt response fields copied onto order payment
 * additional_information.
 *
 * Five confirmation paths (Controller/Payment/Callback, Controller/Payment/Confirm,
 * Controller/Payment/ReturnAction, Controller/Adminhtml/Order/CheckStatus,
 * Cron/PendingOrderReconciler) each used to maintain their own inline $infoKeys
 * list. The lists drifted: 'fee' / 'sender_email' / 'response_code' /
 * 'response_description' were present in some paths but missing in others, so
 * the same Flitt payment stored different sets of metadata depending on which
 * path finalised the order. That broke admin order-view reports and made
 * post-sale audits unreliable (BUG-15).
 *
 * This class is the superset: every Flitt field we want on payment info,
 * regardless of which source (JSON callback, status-API response, return-
 * redirect GET response) delivered it. The write is idempotent — only non-
 * empty values are copied — so adding a field here is safe for all paths.
 */
class PaymentInfoKeys
{
    /**
     * Keys Flitt may send back in a status/callback/confirm payload that
     * we want persisted on the sales_order_payment.additional_information
     * blob. Union of all sources.
     *
     * @var list<string>
     */
    public const KEYS = [
        'payment_id',
        'order_status',
        'masked_card',
        'rrn',
        'approval_code',
        'tran_type',
        'sender_email',
        'card_type',
        'card_bin',
        'eci',
        'fee',
        'response_code',
        'response_description',
        'actual_amount',
        'actual_currency',
    ];

    /**
     * Copy every known Flitt response field onto the order payment
     * additional_information, skipping empty values. Safe to call multiple
     * times from different confirmation paths — later calls only overwrite
     * with fresh non-empty data, so partial responses never wipe fields
     * a prior path already stored.
     *
     * @param array<string, mixed> $responseData Flitt payload (status/callback/confirm response)
     */
    public static function apply(Payment $payment, array $responseData): void
    {
        foreach (self::KEYS as $key) {
            if (!empty($responseData[$key])) {
                $payment->setAdditionalInformation($key, $responseData[$key]);
            }
        }
    }
}
