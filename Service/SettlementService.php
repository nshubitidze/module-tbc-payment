<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Service;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;
use Shubo\TbcPayment\Gateway\Http\Client\SettlementClient;

/**
 * Orchestrates split payment settlement after order approval.
 *
 * Settlement is a post-payment operation: the customer pays the full amount,
 * then this service distributes funds to sub-merchants via Flitt's settlement API.
 */
class SettlementService
{
    /**
     * Flitt `response_status` values that mean the settlement genuinely
     * succeeded — the only values that make settlement terminal for the
     * already-settled guard.
     *
     * @var list<string>
     */
    private const SUCCESS_STATUSES = ['success', 'approved'];

    /**
     * @param Config $config TBC payment configuration
     * @param SettlementClient $settlementClient Flitt settlement API client
     * @param EventManagerInterface $eventManager Event manager for receiver collection
     * @param Json $json JSON serializer
     * @param UrlInterface $urlBuilder URL builder for callback URLs
     * @param LoggerInterface $logger Logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly SettlementClient $settlementClient,
        private readonly EventManagerInterface $eventManager,
        private readonly Json $json,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Settle (distribute) payment for an approved order.
     *
     * Collects receivers from event dispatchers (marketplace modules) first,
     * then falls back to admin-configured receivers. Calculates amounts and
     * sends settlement request to Flitt API.
     *
     * @param Order $order The order to settle payments for
     * @param bool $manual When true, skip the auto-settle config check (admin triggered)
     * @return bool True if settlement was sent successfully
     */
    public function settle(Order $order, bool $manual = false): bool
    {
        $storeId = (int) $order->getStoreId();

        if (!$this->config->isSplitPaymentsEnabled($storeId)) {
            return false;
        }

        if (!$manual && !$this->config->isSplitAutoSettleEnabled($storeId)) {
            return false;
        }

        /** @var Payment $payment */
        $payment = $order->getPayment();
        $flittOrderId = (string) $payment->getAdditionalInformation('flitt_order_id');

        if ($flittOrderId === '') {
            $this->logger->warning('Settlement skipped: no flitt_order_id', [
                'order_id' => $order->getIncrementId(),
            ]);
            return false;
        }

        // IMPROVE-4: only a GENUINELY successful prior settlement is terminal.
        // A failed attempt must stay retryable — previously ANY truthy
        // settlement_status (including 'failure'/'declined' persisted below
        // before the success check) short-circuited this guard FOREVER and
        // also hid the admin Settle button, leaving the merchant payout stuck.
        // We now persist the failure status under a separate, non-blocking key
        // (`settlement_last_status`) and only set the blocking `settlement_status`
        // on success — see the response handling below and AddSettleButton.
        if ($this->isAlreadySettled($payment)) {
            $this->logger->info('Settlement skipped: already settled', [
                'order_id' => $order->getIncrementId(),
            ]);
            return false;
        }

        $receivers = $this->collectReceivers($order, $storeId);

        if (empty($receivers)) {
            $this->logger->info('Settlement skipped: no receivers configured', [
                'order_id' => $order->getIncrementId(),
            ]);
            return false;
        }

        $totalAmount = (int) round((float) $order->getGrandTotal() * 100);
        $currency = (string) $order->getOrderCurrencyCode();
        $merchantId = $this->config->getMerchantId($storeId);

        $receiverData = $this->buildReceiverData($receivers, $totalAmount);

        if (empty($receiverData)) {
            $this->logger->info('Settlement skipped: all receivers resolved to zero amount', [
                'order_id' => $order->getIncrementId(),
            ]);
            return false;
        }

        // BUG-7: each attempt against Flitt must use a distinct settlement
        // order_id. On HTTP timeout the first request may have actually
        // reached Flitt — a retry with the same order_id then returns
        // error 1013/2004 ("duplicate order_id") and the merchant payout
        // stays stuck. The attempt counter is persisted on the Magento
        // payment so retries across processes / containers stay coherent.
        $attempt = (int) $payment->getAdditionalInformation('settlement_attempt') + 1;
        $payment->setAdditionalInformation('settlement_attempt', $attempt);
        $settlementOrderId = $attempt === 1
            ? 'settlement_' . $flittOrderId
            : sprintf('settlement_%s_r%d', $flittOrderId, $attempt);

        $orderData = [
            'order_type' => 'settlement',
            'order_id' => $settlementOrderId,
            'operation_id' => $flittOrderId,
            'merchant_id' => (int) $merchantId,
            'amount' => $totalAmount,
            'currency' => $currency,
            'order_desc' => (string) __('Settlement for order %1', $order->getIncrementId()),
            'server_callback_url' => $this->urlBuilder->getUrl(
                'shubo_tbc/payment/callback',
                ['_nosid' => true],
            ),
            'receiver' => $receiverData,
        ];

        try {
            $response = $this->settlementClient->settle($orderData, $storeId);
            $responseOrder = $response['order'] ?? $response['response'] ?? [];
            $status = is_array($responseOrder)
                ? (string) ($responseOrder['response_status'] ?? ($responseOrder['reverse_status'] ?? ''))
                : '';

            $payment->setAdditionalInformation(
                'settlement_receivers',
                $this->json->serialize($receiverData)
            );

            if (in_array($status, self::SUCCESS_STATUSES, true)) {
                // Only a genuine success stamps the BLOCKING terminal key. This
                // is the single place `settlement_status` is set to a value that
                // satisfies isAlreadySettled() and hides the admin Settle button.
                $payment->setAdditionalInformation('settlement_status', $status);
                $payment->setAdditionalInformation('settlement_last_status', $status);
                $order->addCommentToStatusHistory(
                    (string) __('Payment settlement sent to %1 receiver(s).', count($receiverData))
                );
                $this->logger->info('Settlement successful', [
                    'order_id' => $order->getIncrementId(),
                    'receivers' => count($receiverData),
                ]);
                return true;
            }

            // IMPROVE-4: a failed reply records its status under the
            // NON-blocking key only. settlement_status stays empty so the guard
            // does not short-circuit and the admin Settle button stays visible —
            // the failure remains retryable (a fresh distinct order_id suffix on
            // the next attempt avoids Flitt's duplicate-order_id rejection).
            $payment->setAdditionalInformation('settlement_last_status', $status !== '' ? $status : 'failure');
            $errorMsg = is_array($responseOrder)
                ? (string) ($responseOrder['error_message']
                    ?? ($responseOrder['response_description'] ?? 'Unknown error'))
                : 'Unknown error';
            $order->addCommentToStatusHistory(
                (string) __('Payment settlement failed (retryable): %1', $errorMsg)
            );
            $this->logger->error('Settlement failed', [
                'order_id' => $order->getIncrementId(),
                'attempt' => $attempt,
                'response' => $responseOrder,
            ]);
            return false;
        } catch (FlittApiException $e) {
            $this->logger->error('Settlement exception', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Whether the payment has a GENUINELY-successful, terminal settlement.
     *
     * Shared by the settle() guard, the recovery cron, and the admin
     * AddSettleButton plugin so all three agree on what "already settled"
     * means. A failure recorded under `settlement_last_status` does NOT count.
     *
     * @param Payment $payment Order payment carrying settlement state
     */
    public function isAlreadySettled(Payment $payment): bool
    {
        $status = (string) $payment->getAdditionalInformation('settlement_status');

        return in_array($status, self::SUCCESS_STATUSES, true);
    }

    /**
     * Collect receivers from event first, fall back to admin config.
     *
     * @param Order $order The order to collect receivers for
     * @param int $storeId Store ID for config scope
     * @return array<int, array{merchant_id: string, amount_type: string, amount: string, description: string}>
     */
    private function collectReceivers(Order $order, int $storeId): array
    {
        // First try event-based receivers (for marketplace modules)
        $transport = new DataObject(['receivers' => [], 'order' => $order]);
        $this->eventManager->dispatch('shubo_tbc_settlement_collect_receivers', [
            'transport' => $transport,
        ]);

        $eventReceivers = $transport->getData('receivers');
        if (!empty($eventReceivers) && is_array($eventReceivers)) {
            return $eventReceivers;
        }

        // Fall back to admin-configured receivers
        return $this->getAdminReceivers($storeId);
    }

    /**
     * Get receivers from admin config (serialized dynamic rows in split_receivers).
     *
     * @param int $storeId Store ID for config scope
     * @return array<int, array{merchant_id: string, amount_type: string, amount: string, description: string}>
     */
    private function getAdminReceivers(int $storeId): array
    {
        $receiversConfig = $this->config->getSplitReceivers($storeId);
        if (empty($receiversConfig)) {
            return [];
        }

        try {
            $receivers = $this->json->unserialize($receiversConfig);
            if (!is_array($receivers)) {
                return [];
            }
            // Dynamic rows returns associative array keyed by row ID -- re-index
            return array_values($receivers);
        } catch (\Exception) {
            $this->logger->warning('Failed to parse split_receivers config');
            return [];
        }
    }

    /**
     * Build the Flitt receiver array from config receivers.
     *
     * Handles mixed mode: fixed amounts are deducted first,
     * then percentages are applied to the remaining amount.
     *
     * @param array $receivers Receiver configurations
     * @param int $totalAmount Order amount in minor units
     * @return array
     *
     * phpcs:ignore Magento2.Annotation.MethodAnnotationStructure
     * @phpstan-param list<array<string, string>> $receivers
     * @phpstan-return list<array<string, mixed>>
     */
    private function buildReceiverData(array $receivers, int $totalAmount): array
    {
        $result = [];
        $fixedTotal = 0;
        /** @var list<array{merchant_id: int, basis_points: int, description: string}> $percentReceivers */
        $percentReceivers = [];

        // First pass: process fixed amounts
        foreach ($receivers as $receiver) {
            $merchantId = (int) ($receiver['merchant_id'] ?? 0);
            $amountType = (string) ($receiver['amount_type'] ?? 'percent');
            $amountValue = (string) ($receiver['amount'] ?? '0');
            $description = (string) ($receiver['description'] ?? '');

            if ($merchantId === 0) {
                continue;
            }

            if ($amountType === 'fixed') {
                // IMPROVE-10: convert GEL → tetri via bcmath, never float
                // multiplication. bcmul to 2dp then strip the fractional part
                // keeps the value exact for inputs like "0.10" that float would
                // store as 0.0999999…
                $amount = (int) bcmul($this->normalizeMoney($amountValue), '100', 0);
                $fixedTotal += $amount;
                $result[] = [
                    'type' => 'merchant',
                    'requisites' => [
                        'merchant_id' => $merchantId,
                        'amount' => $amount,
                        'settlement_description' => $description !== '' ? $description : (string) __('Payment split'),
                    ],
                ];
            } else {
                // IMPROVE-10: percent stored as integer basis points (1% = 100
                // bp) via bcmath so "33.33" → 3333 exactly with no float drift.
                $percentReceivers[] = [
                    'merchant_id' => $merchantId,
                    'basis_points' => (int) bcmul($this->normalizeMoney($amountValue), '100', 0),
                    'description' => $description,
                ];
            }
        }

        // Validate fixed amounts don't exceed total
        if ($fixedTotal > $totalAmount) {
            $this->logger->error('Settlement skipped: fixed amounts exceed order total', [
                'fixed_total' => $fixedTotal,
                'order_total' => $totalAmount,
            ]);
            return [];
        }

        // IMPROVE-10: validate the percentage sum does not exceed 100%
        // (10000 basis points). Previously only the fixed-amount overflow was
        // checked, so a 60/60 split silently over-allocated the remainder.
        $totalBasisPoints = array_sum(array_column($percentReceivers, 'basis_points'));
        if ($totalBasisPoints > 10000) {
            $this->logger->error('Settlement skipped: percentage receivers exceed 100%', [
                'total_percent' => bcdiv((string) $totalBasisPoints, '100', 2),
                'order_total' => $totalAmount,
            ]);
            return [];
        }

        return array_merge(
            $result,
            $this->allocatePercentReceivers($percentReceivers, $totalAmount - $fixedTotal)
        );
    }

    /**
     * Allocate the post-fixed remainder across percentage receivers using
     * integer tetri math with a deterministic rounding remainder.
     *
     * Each receiver's share is floor(remaining * basisPoints / 10000) in
     * integer tetri (no float). The cumulative floor loses up to (n-1) tetri to
     * truncation; that residue is added to the LAST positive receiver so the
     * sum of percentage shares reconciles EXACTLY to the intended slice of the
     * order — Σreceivers never drifts ±1 and never exceeds the total. The
     * "intended slice" is remaining * ΣbasisPoints / 10000 (== remaining when
     * the percentages sum to 100%), so a partial split leaves the rest with the
     * main merchant by design.
     *
     * @param list<array{merchant_id: int, basis_points: int, description: string}> $percentReceivers
     * @param int $remaining Tetri left after fixed receivers
     * @return list<array<string, mixed>>
     */
    private function allocatePercentReceivers(array $percentReceivers, int $remaining): array
    {
        if ($remaining <= 0 || $percentReceivers === []) {
            return [];
        }

        $totalBasisPoints = array_sum(array_column($percentReceivers, 'basis_points'));
        if ($totalBasisPoints <= 0) {
            return [];
        }

        // Intended total to distribute across percentage receivers (integer
        // tetri). For a full 100% split this equals $remaining exactly.
        $intendedTotal = intdiv($remaining * $totalBasisPoints, 10000);

        $shares = [];
        $allocated = 0;
        $lastPositiveIndex = null;
        foreach ($percentReceivers as $index => $pr) {
            $share = intdiv($remaining * $pr['basis_points'], 10000);
            $shares[$index] = $share;
            $allocated += $share;
            if ($share > 0) {
                $lastPositiveIndex = $index;
            }
        }

        // Deterministically assign the truncation remainder to the last
        // positive receiver so Σshares === $intendedTotal exactly.
        if ($lastPositiveIndex !== null) {
            $shares[$lastPositiveIndex] += $intendedTotal - $allocated;
        }

        $allocatedResult = [];
        foreach ($percentReceivers as $index => $pr) {
            $amount = $shares[$index];
            if ($amount <= 0) {
                continue;
            }
            $allocatedResult[] = [
                'type' => 'merchant',
                'requisites' => [
                    'merchant_id' => $pr['merchant_id'],
                    'amount' => $amount,
                    'settlement_description' => $pr['description'] !== ''
                        ? $pr['description']
                        : (string) __('Payment split'),
                ],
            ];
        }

        return $allocatedResult;
    }

    /**
     * Normalise a money/percentage string to a fixed 2-decimal bcmath operand.
     *
     * Coerces empty/non-numeric input to "0.00" and rounds to 2dp via bcadd so
     * downstream bcmul/bcdiv operate on a clean, scale-stable value. Avoids any
     * float intermediate — the whole point of the IMPROVE-10 rewrite.
     *
     * @param string $value Raw admin-entered value (GEL or percent)
     */
    private function normalizeMoney(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return '0.00';
        }

        return bcadd($trimmed, '0', 2);
    }
}
