<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;

/**
 * Resolves the Magento order behind a Flitt order_id on the capture path.
 *
 * Flitt identifies an order by the prefixed `order_id` it was given at token
 * mint (`duka_{incrementId}_{timestamp}`). The three FRONTEND capture
 * controllers — Callback (server-to-server), Confirm (embed) and ReturnAction
 * (redirect) — all have to turn that value back into a Magento order via the
 * `getList()`-by-increment_id idiom (OrderRepositoryInterface has no
 * getByIncrementId). Centralising it here removes three copies that each
 * re-injected SearchCriteriaBuilder and had to remember setPageSize(1) and the
 * `reset() ?: null` narrowing — a money-path correctness risk if any copy drifts.
 *
 * The admin controllers (Capture / CheckStatus / VoidPayment) are deliberately
 * NOT routed through here: they load by entity_id via orderRepository->get().
 */
class OrderLocator
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Load an order by its Magento increment ID.
     *
     * @return OrderInterface|null The first matching order, or null when none exists.
     */
    public function byIncrementId(string $incrementId): ?OrderInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        return reset($orders) ?: null;
    }

    /**
     * Extract the Magento increment ID embedded in a Flitt order_id.
     *
     * Flitt order_id format: `duka_{incrementId}_{timestamp}`. Falls back to the
     * raw value when the format does not match (e.g. legacy orders) — mirroring
     * the lenient extraction the Callback controller relied on.
     */
    public function extractIncrementId(string $flittOrderId): string
    {
        if (preg_match('/^duka_(.+)_\d+$/', $flittOrderId, $matches)) {
            return $matches[1];
        }

        return $flittOrderId;
    }

    /**
     * Resolve the order matching a Flitt order_id, verifying the stored value.
     *
     * Stricter than {@see byIncrementId}: the order_id MUST match the
     * `duka_{incrementId}_{timestamp}` format, AND the flitt_order_id stored on
     * the order's payment must equal the supplied value. This guards against
     * cross-order collisions on the customer-facing redirect-return path.
     *
     * @return OrderInterface|null Null when the format is wrong, the order is
     *                             missing, the payment is absent, or the stored
     *                             flitt_order_id does not match.
     */
    public function byFlittOrderId(string $flittOrderId): ?OrderInterface
    {
        if (!preg_match('/^duka_(.+)_\d+$/', $flittOrderId, $matches)) {
            $this->logger->warning('TBC OrderLocator: unrecognised Flitt order ID format', [
                'flitt_order_id' => $flittOrderId,
            ]);
            return null;
        }

        $order = $this->byIncrementId($matches[1]);
        if ($order === null) {
            return null;
        }

        /** @var Payment|null $payment */
        $payment = $order->getPayment();
        if ($payment === null) {
            return null;
        }

        $storedFlittId = (string) $payment->getAdditionalInformation('flitt_order_id');
        if ($storedFlittId !== $flittOrderId) {
            $this->logger->warning('TBC OrderLocator: flitt_order_id mismatch on payment', [
                'flitt_order_id' => $flittOrderId,
                'stored'         => $storedFlittId,
                'order_id'       => $order->getIncrementId(),
            ]);
            return null;
        }

        return $order;
    }
}
