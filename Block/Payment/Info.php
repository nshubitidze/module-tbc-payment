<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Block\Payment;

use Magento\Payment\Block\Info as PaymentInfo;
use Magento\Sales\Model\Order\Payment;

/**
 * Payment info block for admin order view.
 */
class Info extends PaymentInfo
{
    /**
     * ECI value to human-readable 3DS status mapping.
     */
    private const ECI_LABELS = [
        '5' => 'Full 3DS',
        '05' => 'Full 3DS',
        '6' => 'Attempted',
        '06' => 'Attempted',
        '7' => 'No 3DS',
        '07' => 'No 3DS',
    ];

    /**
     * Get specific payment information for display in admin.
     *
     * @return array<string, string>
     */
    public function getSpecificInformation(): array
    {
        $info = [];
        $payment = $this->getInfo();

        $additionalData = [
            'Payment ID' => 'payment_id',
            'Order Status' => 'order_status',
            'Masked Card' => 'masked_card',
            'Card Type' => 'card_type',
            'RRN' => 'rrn',
            'Approval Code' => 'approval_code',
            'Transaction Type' => 'tran_type',
        ];

        foreach ($additionalData as $label => $key) {
            $value = $payment->getAdditionalInformation($key);
            if (!empty($value)) {
                $info[(string) __($label)] = (string) $value;
            }
        }

        // ECI / 3DS status with human-readable label
        $eci = $payment->getAdditionalInformation('eci');
        if (!empty($eci)) {
            $eciLabel = self::ECI_LABELS[(string) $eci] ?? (string) $eci;
            $info[(string) __('3DS Status')] = $eciLabel . ' (ECI ' . $eci . ')';
        }

        // Fee — Flitt sends in minor units, display as major units
        $fee = $payment->getAdditionalInformation('fee');
        if (!empty($fee) && is_numeric($fee)) {
            $currency = $payment->getAdditionalInformation('actual_currency') ?: 'GEL';
            $info[(string) __('Fee')] = number_format((float) $fee / 100, 2) . ' ' . $currency;
        }

        // Response code — useful when payment is declined
        $responseCode = $payment->getAdditionalInformation('response_code');
        if (!empty($responseCode)) {
            $responseDesc = $payment->getAdditionalInformation('response_description');
            $display = (string) $responseCode;
            if (!empty($responseDesc)) {
                $display .= ' — ' . $responseDesc;
            }
            $info[(string) __('Response Code')] = $display;
        }

        // Settlement information
        $settlement = $this->getSettlementInfo();
        if ($settlement !== null) {
            $info[(string) __('Settlement Status')] = ucfirst($settlement['status']);
            /** @var Payment $payment */
            $currencyCode = $payment->getOrder()->getOrderCurrencyCode();
            foreach ($settlement['receivers'] as $i => $receiver) {
                $label = (string) __('Receiver %1', $i + 1);
                $desc = $receiver['description'] !== ''
                    ? ' (' . $receiver['description'] . ')'
                    : '';
                $value = sprintf(
                    '%s — %s %s%s',
                    $receiver['merchant_id'],
                    $receiver['amount'],
                    $currencyCode,
                    $desc,
                );
                $info[$label] = $value;
            }
        }

        return $info;
    }

    /**
     * Get settlement information for display.
     *
     * @return array{
     *     status: string,
     *     receivers: list<array{
     *         merchant_id: string,
     *         amount: string,
     *         description: string
     *     }>
     * }|null Settlement data with status and receivers
     */
    public function getSettlementInfo(): ?array
    {
        $info = $this->getInfo();
        $status = $info->getAdditionalInformation('settlement_status');

        if (empty($status)) {
            return null;
        }

        $receivers = [];
        $receiversJson = $info->getAdditionalInformation('settlement_receivers');
        if (!empty($receiversJson)) {
            try {
                $receiversData = is_string($receiversJson)
                    ? json_decode($receiversJson, true)
                    : $receiversJson;
                if (is_array($receiversData)) {
                    foreach ($receiversData as $r) {
                        $requisites = $r['requisites'] ?? $r;
                        $amountMinor = (int) ($requisites['amount'] ?? 0);
                        $receivers[] = [
                            'merchant_id' => (string) ($requisites['merchant_id'] ?? ''),
                            'amount' => number_format($amountMinor / 100, 2),
                            'description' => (string) ($requisites['settlement_description'] ?? ''),
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Invalid JSON — settlement_receivers data is malformed, skip gracefully
                unset($e);
            }
        }

        return [
            'status' => $status,
            'receivers' => $receivers,
        ];
    }
}
