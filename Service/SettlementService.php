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

        // Check if already settled
        if ($payment->getAdditionalInformation('settlement_status')) {
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

        $orderData = [
            'order_type' => 'settlement',
            'order_id' => 'settlement_' . $flittOrderId,
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

            $payment->setAdditionalInformation('settlement_status', $status);
            $payment->setAdditionalInformation(
                'settlement_receivers',
                $this->json->serialize($receiverData)
            );

            if ($status === 'success' || $status === 'approved') {
                $order->addCommentToStatusHistory(
                    (string) __('Payment settlement sent to %1 receiver(s).', count($receiverData))
                );
                $this->logger->info('Settlement successful', [
                    'order_id' => $order->getIncrementId(),
                    'receivers' => count($receiverData),
                ]);
                return true;
            }

            $errorMsg = is_array($responseOrder)
                ? (string) ($responseOrder['error_message']
                    ?? ($responseOrder['response_description'] ?? 'Unknown error'))
                : 'Unknown error';
            $order->addCommentToStatusHistory(
                (string) __('Payment settlement failed: %1', $errorMsg)
            );
            $this->logger->error('Settlement failed', [
                'order_id' => $order->getIncrementId(),
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
                $amount = (int) round((float) $amountValue * 100);
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
                $percentReceivers[] = [
                    'merchant_id' => $merchantId,
                    'percent' => (float) $amountValue,
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

        // Second pass: process percentage receivers (from remaining after fixed)
        $remainingAmount = $totalAmount - $fixedTotal;
        foreach ($percentReceivers as $pr) {
            $amount = (int) round($remainingAmount * ($pr['percent'] / 100));
            if ($amount <= 0) {
                continue;
            }
            $desc = $pr['description'] !== ''
                ? $pr['description']
                : (string) __('Payment split');
            $result[] = [
                'type' => 'merchant',
                'requisites' => [
                    'merchant_id' => (int) $pr['merchant_id'],
                    'amount' => $amount,
                    'settlement_description' => $desc,
                ],
            ];
        }

        return $result;
    }
}
