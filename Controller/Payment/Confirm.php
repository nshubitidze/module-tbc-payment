<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Http\Client\StatusClient;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Model\FlittStatus;
use Shubo\TbcPayment\Model\OrderLocator;
use Shubo\TbcPayment\Service\ApprovalContext;
use Shubo\TbcPayment\Service\ApprovalResult;
use Shubo\TbcPayment\Service\OrderApprovalApplier;
use Shubo\TbcPayment\Service\PaymentLock;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Called by the frontend JS after Flitt embed fires the success event.
 *
 * Checks the Flitt API for the real payment status and processes the order
 * immediately, so the customer doesn't have to wait for the server callback
 * or the cron reconciler.
 *
 * Race-safety: Callback (server-to-server) and PendingOrderReconciler can run
 * concurrently with this controller. We wrap the order load + state check +
 * approval inside a {@see PaymentLock} (IMPROVE-2) AND a DB transaction with a
 * SELECT ... FOR UPDATE on the order row. The advisory lock is what actually
 * serializes us against Callback/Cron (whose plain SELECT neither blocks nor is
 * blocked by FOR UPDATE); the FOR UPDATE stays as belt-and-suspenders. On lock
 * contention we simply skip — the concurrent holder finalises the order.
 */
class Confirm implements HttpPostActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StatusClient $statusClient,
        private readonly CallbackValidator $callbackValidator,
        private readonly OrderApprovalApplier $approvalApplier,
        private readonly SettlementService $settlementService,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
        private readonly OrderLocator $orderLocator,
        private readonly PaymentLock $paymentLock,
    ) {
    }

    public function execute(): ResultInterface
    {
        /** @var JsonResult $result */
        $result = $this->jsonFactory->create();

        try {
            $sessionOrder = $this->checkoutSession->getLastRealOrder();

            if (!$sessionOrder || !$sessionOrder->getEntityId()) {
                return $result->setData(['success' => false, 'message' => (string) __('No order found.')]);
            }

            /** @var Payment $sessionPayment */
            $sessionPayment = $sessionOrder->getPayment();
            $flittOrderId = (string) $sessionPayment->getAdditionalInformation('flitt_order_id');

            if ($flittOrderId === '') {
                return $result->setData(['success' => false, 'message' => (string) __('No Flitt order ID.')]);
            }

            // Fast pre-check before doing any external API call: skip if already done.
            if ($sessionOrder->getState() === Order::STATE_PROCESSING) {
                return $result->setData(['success' => true, 'already_processed' => true]);
            }

            $storeId = (int) $sessionOrder->getStoreId();
            // StatusClient::checkStatus already unwraps the Flitt `response` envelope.
            $responseData = $this->statusClient->checkStatus($flittOrderId, $storeId);
            $flittStatus = $responseData['order_status'] ?? '';

            $this->logger->info('TBC confirm: Flitt status check', [
                'order_id' => $sessionOrder->getIncrementId(),
                'flitt_status' => $flittStatus,
            ]);

            if ($flittStatus !== FlittStatus::APPROVED) {
                return $result->setData([
                    'success' => false,
                    'flitt_status' => $flittStatus,
                    'message' => (string) __('Payment not yet approved.'),
                ]);
            }

            if (!$this->callbackValidator->validate($responseData, $storeId)) {
                $this->logger->error('TBC confirm: signature validation failed', [
                    'order_id' => $sessionOrder->getIncrementId(),
                ]);
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Signature validation failed.'),
                ]);
            }

            // IMPROVE-2: serialize against Callback/ReturnAction/Cron via the
            // advisory lock, keyed by flitt_order_id. On contention withLock
            // returns null; the concurrent holder is finalising the order, so we
            // just report success and skip — exactly the existing
            // already-processed semantics.
            //
            // processWithLock returns the order ONLY when this run genuinely
            // CAPTURED (ApprovalResult::Captured). On a preauth-held result the
            // funds are only HELD, not captured — it returns null so settlement
            // does NOT run (settling on a held pre-auth would distribute the full
            // amount to sub-merchant receivers before capture). This unifies
            // Confirm with the other four capture paths, which all gate
            // settlement on Captured.
            $order = $this->paymentLock->withLock(
                $flittOrderId,
                fn (): ?Order => $this->processWithLock(
                    (int) $sessionOrder->getEntityId(),
                    $sessionOrder->getIncrementId(),
                    $responseData,
                )
            );

            // Trigger settlement OUTSIDE the order transaction; settlement does its own
            // external HTTP call and we don't want to hold the row lock during it.
            if ($order !== null) {
                try {
                    $this->settlementService->settle($order);
                    $this->orderRepository->save($order);
                } catch (\Exception $e) {
                    $this->logger->error('TBC confirm: settlement failed', [
                        'order_id' => $order->getIncrementId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $result->setData(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('TBC confirm error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $result->setData([
                'success' => false,
                'message' => (string) __('Unable to confirm payment.'),
            ]);
        }
    }

    /**
     * Acquire a row-level lock on the order, re-check state, and process the approval.
     *
     * Returns the processed order (so the caller can run settlement after
     * commit) ONLY when this run genuinely CAPTURED. Returns null when another
     * path beat us to it (already PROCESSING), when the capture was refused
     * (amount mismatch), OR when only a pre-auth was HELD — settlement must not
     * run on a held pre-auth, so the null return suppresses it.
     *
     * @param array<string, mixed> $responseData
     */
    private function processWithLock(
        int $orderEntityId,
        string $incrementId,
        array $responseData,
    ): ?Order {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            // SELECT ... FOR UPDATE on the order row blocks concurrent
            // Callback / Confirm / Cron processing for the same order.
            $orderTable = $this->resourceConnection->getTableName('sales_order');
            $select = $connection->select()
                ->from($orderTable, ['entity_id', 'state'])
                ->where('entity_id = ?', $orderEntityId)
                ->forUpdate(true);
            $row = $connection->fetchRow($select);

            if ($row === false) {
                $connection->rollBack();
                $this->logger->warning('TBC confirm: order row vanished under lock', [
                    'order_id' => $incrementId,
                ]);
                return null;
            }

            // Re-load via the repository so we get the full domain object.
            /** @var Order|null $order */
            $order = $this->orderLocator->byIncrementId($incrementId);

            if ($order === null) {
                $connection->rollBack();
                $this->logger->warning('TBC confirm: order disappeared between lock and reload', [
                    'order_id' => $incrementId,
                ]);
                return null;
            }

            // Idempotent check INSIDE the locked region: if another path
            // already promoted the order, do nothing.
            if ($order->getState() === Order::STATE_PROCESSING) {
                $connection->commit();
                $this->logger->info('TBC confirm: already processed by concurrent path', [
                    'order_id' => $incrementId,
                ]);
                return null;
            }

            // Apply the shared approve mutation (in-memory only); the lock/txn/save
            // boundary stays here in the controller.
            $applyResult = $this->approvalApplier->apply($order, $responseData, ApprovalContext::Confirm);

            // IMPROVE-8: a refused capture (amount mismatch) means the cart was
            // re-priced mid-flow. The applier mutated nothing and logged at
            // `critical`; roll back and leave the order untouched for an admin to
            // reconcile. Return null so no settlement runs.
            if ($applyResult === ApprovalResult::RefusedAmountMismatch) {
                $connection->rollBack();
                $this->logger->error('TBC confirm: capture refused (amount mismatch), left for admin reconcile', [
                    'order_id' => $order->getIncrementId(),
                ]);
                return null;
            }

            $this->orderRepository->save($order);

            $connection->commit();

            $this->logger->info('TBC confirm: order approved', [
                'order_id' => $order->getIncrementId(),
                'payment_id' => (string) ($responseData['payment_id'] ?? ''),
                'result' => $applyResult->name,
            ]);

            // Only a genuine capture is eligible for settlement. A preauth-held
            // order is committed/saved above (the hold IS persisted) but returns
            // null so the caller skips settlement until a real capture occurs.
            return $applyResult === ApprovalResult::Captured ? $order : null;
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
