<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
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
 * Handles customer return from Flitt hosted payment page (redirect checkout mode).
 *
 * Flitt redirects the customer back to this URL with order_id in GET params.
 * We NEVER trust GET params — always verify via the Flitt Status API before processing.
 *
 * The Callback controller (server-to-server) and PendingOrderReconciler cron are
 * safety nets: if this controller fails or the customer closes the browser, those
 * will finalize the order asynchronously.
 */
class ReturnAction implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly \Magento\Framework\App\Request\Http $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderLocator $orderLocator,
        private readonly StatusClient $statusClient,
        private readonly CallbackValidator $callbackValidator,
        private readonly OrderApprovalApplier $approvalApplier,
        private readonly SettlementService $settlementService,
        private readonly MessageManager $messageManager,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
        private readonly PaymentLock $paymentLock,
    ) {
    }

    /**
     * No CSRF exception needed — validation is bypassed for external redirects.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @param RequestInterface $request
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Disable CSRF validation — this endpoint receives external redirects from Flitt.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @param RequestInterface $request
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Verify payment status via Flitt API and redirect to success or failure.
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();

        try {
            $flittOrderId = (string) $this->request->getParam('order_id', '');

            if ($flittOrderId === '') {
                $this->logger->error('TBC Return: no order_id in return params');
                $this->messageManager->addErrorMessage(
                    (string) __('Payment information not found. Please contact support.')
                );
                return $redirect->setPath('checkout/cart');
            }

            /** @var Order|null $order */
            $order = $this->orderLocator->byFlittOrderId($flittOrderId);

            if ($order === null) {
                $this->logger->error('TBC Return: order not found for Flitt ID', [
                    'flitt_order_id' => $flittOrderId,
                ]);
                $this->messageManager->addErrorMessage(
                    (string) __('Order not found. Please contact support.')
                );
                return $redirect->setPath('checkout/cart');
            }

            // Callback beat us here — order already finalized, just redirect to success
            if ($order->getState() === Order::STATE_PROCESSING) {
                $this->setCheckoutSessionData($order);
                return $redirect->setPath('checkout/onepage/success');
            }

            $storeId = (int) $order->getStoreId();

            // NEVER trust GET params — verify via Flitt Status API.
            // StatusClient::checkStatus already unwraps the Flitt `response` envelope.
            $responseData = $this->statusClient->checkStatus($flittOrderId, $storeId);
            $flittStatus = (string) ($responseData['order_status'] ?? '');

            $this->logger->info('TBC Return: Flitt status check', [
                'order_id'       => $order->getIncrementId(),
                'flitt_order_id' => $flittOrderId,
                'flitt_status'   => $flittStatus,
            ]);

            if ($flittStatus === FlittStatus::APPROVED) {
                return $this->handleApproved($order, $responseData, $redirect);
            }

            if ($flittStatus === FlittStatus::PROCESSING || $flittStatus === FlittStatus::CREATED) {
                // Payment still in progress — do NOT redirect to the success
                // page (misleading if the bank ultimately declines).
                // Callback + PendingOrderReconciler will finalise asynchronously
                // and email the customer on success.
                $this->messageManager->addNoticeMessage(
                    (string) __(
                        'Your payment is still being processed by the bank.'
                        . ' You will receive an email confirmation shortly.'
                    )
                );
                return $redirect->setPath('checkout');
            }

            // Declined, expired, reversed, or unknown
            return $this->handleFailure($order, $flittStatus, $redirect);
        } catch (\Exception $e) {
            $this->logger->critical('TBC Return error', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            $this->messageManager->addErrorMessage(
                (string) __('An error occurred processing your payment. Please contact support.')
            );
            return $redirect->setPath('checkout/onepage/failure');
        }
    }

    /**
     * Handle an approved Flitt payment: validate signature, update order, trigger settlement.
     *
     * Runs the order/invoice mutation under a {@see PaymentLock} (IMPROVE-2) AND
     * a row lock + DB transaction so a concurrent Callback / Confirm / Cron
     * invocation can't double-invoice. On lock contention another handler is
     * already finalising the order, so we still redirect to success (the
     * customer paid; the order is being completed elsewhere).
     *
     * @param Order $order
     * @param array<string, mixed> $responseData Flitt status API response payload
     * @param Redirect $redirect
     */
    private function handleApproved(
        Order $order,
        array $responseData,
        Redirect $redirect,
    ): ResultInterface {
        $storeId = (int) $order->getStoreId();

        if (!$this->callbackValidator->validate($responseData, $storeId)) {
            $this->logger->error('TBC Return: signature validation failed', [
                'order_id' => $order->getIncrementId(),
            ]);
            $this->messageManager->addErrorMessage(
                (string) __('Payment verification failed. Please contact support.')
            );
            return $redirect->setPath('checkout/onepage/failure');
        }

        $flittOrderId = (string) ($responseData['order_id'] ?? '');
        // Lock key: prefer the Flitt order_id; fall back to the increment_id so
        // the key never goes empty (withLock throws on an empty key).
        $lockKey = $flittOrderId !== '' ? $flittOrderId : (string) $order->getIncrementId();

        $outcome = $this->paymentLock->withLock(
            $lockKey,
            fn (): string => $this->approveUnderRowLock($order, $responseData)
        );

        if ($outcome === null) {
            // Contention — a concurrent handler holds the lock. The customer
            // paid; that handler is finalising the order. Stamp session data
            // from a fresh read so the success page renders, and redirect.
            /** @var Order $freshOrder */
            $freshOrder = $this->orderRepository->get((int) $order->getEntityId());
            $this->setCheckoutSessionData($freshOrder);
            $this->logger->info('TBC Return: lock contended, another handler finalising', [
                'order_id' => $order->getIncrementId(),
            ]);
            return $redirect->setPath('checkout/onepage/success');
        }

        if ($outcome === 'failure') {
            return $redirect->setPath('checkout/onepage/failure');
        }

        return $redirect->setPath('checkout/onepage/success');
    }

    /**
     * Acquire the row lock, re-check state, apply the approval, and run
     * settlement. Runs while holding the PaymentLock (set in handleApproved).
     *
     * @param array<string, mixed> $responseData
     * @return string 'success' or 'failure' (drives the redirect in the caller)
     */
    private function approveUnderRowLock(Order $order, array $responseData): string
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        $paymentId = (string) ($responseData['payment_id'] ?? '');
        $processedOrder = null;
        $captured = false;

        try {
            // Row-level lock so concurrent Callback/Confirm/Cron cannot also
            // create an invoice for this order (belt-and-suspenders under the
            // advisory lock).
            $orderTable = $this->resourceConnection->getTableName('sales_order');
            $select = $connection->select()
                ->from($orderTable, ['entity_id', 'state'])
                ->where('entity_id = ?', (int) $order->getEntityId())
                ->forUpdate(true);
            $row = $connection->fetchRow($select);

            if ($row === false) {
                $connection->rollBack();
                $this->logger->warning('TBC Return: order row vanished under lock', [
                    'order_id' => $order->getIncrementId(),
                ]);
                return 'failure';
            }

            // Re-load to get an up-to-date snapshot inside the locked region.
            // The repository return type is OrderInterface, but it is in fact
            // always an Order in core; we narrow the type for tooling here.
            /** @var Order $freshOrder */
            $freshOrder = $this->orderRepository->get((int) $order->getEntityId());

            if ($freshOrder->getState() === Order::STATE_PROCESSING) {
                // Another path already finalised this order — just send the
                // customer to success without touching state.
                $connection->commit();
                $this->setCheckoutSessionData($freshOrder);
                $this->logger->info('TBC Return: already processed by concurrent path', [
                    'order_id' => $freshOrder->getIncrementId(),
                ]);
                return 'success';
            }

            // Apply the shared approve mutation (in-memory only); the row lock,
            // txn and save boundary stays here in the controller.
            $applyResult = $this->approvalApplier->apply($freshOrder, $responseData, ApprovalContext::Redirect);

            // IMPROVE-8: a refused capture (amount mismatch) means the cart was
            // re-priced mid-flow. Roll back, leave the order for admin reconcile,
            // and send the customer to failure rather than confirm a wrong total.
            if ($applyResult === ApprovalResult::RefusedAmountMismatch) {
                $connection->rollBack();
                $this->logger->error('TBC Return: capture refused (amount mismatch), left for admin reconcile', [
                    'order_id' => $freshOrder->getIncrementId(),
                ]);
                $this->messageManager->addErrorMessage(
                    (string) __('Payment verification failed. Please contact support.')
                );
                return 'failure';
            }

            $this->orderRepository->save($freshOrder);
            $connection->commit();
            $processedOrder = $freshOrder;
            $captured = $applyResult === ApprovalResult::Captured;
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }

        // Settlement runs OUTSIDE the order transaction so it never holds the
        // row lock during an external HTTP call. Gate it on a GENUINE capture:
        // on a preauth-held result the funds are only HELD, so settling now
        // would distribute the full amount to sub-merchant receivers before
        // capture. This unifies ReturnAction with the other four capture paths,
        // which all settle only on ApprovalResult::Captured. The customer still
        // reaches the success page either way (their payment was approved/held).
        if ($captured) {
            try {
                $this->settlementService->settle($processedOrder);
                $this->orderRepository->save($processedOrder);
            } catch (\Exception $e) {
                $this->logger->error('TBC Return: settlement failed', [
                    'order_id' => $processedOrder->getIncrementId(),
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->setCheckoutSessionData($processedOrder);

        $this->logger->info('TBC Return: order approved', [
            'order_id'   => $processedOrder->getIncrementId(),
            'payment_id' => $paymentId,
            'result'     => $applyResult->name,
        ]);

        return 'success';
    }

    /**
     * Handle a failed/expired/declined payment: cancel order and restore quote.
     *
     * @param Order $order
     * @param string $flittStatus
     * @param Redirect $redirect
     */
    private function handleFailure(
        Order $order,
        string $flittStatus,
        Redirect $redirect,
    ): ResultInterface {
        $this->logger->warning('TBC Return: payment not successful', [
            'order_id'    => $order->getIncrementId(),
            'flitt_status' => $flittStatus,
        ]);

        $order->addCommentToStatusHistory(
            (string) __('Customer returned from TBC payment page. Status: %1', $flittStatus)
        );
        $this->orderRepository->save($order);

        // Restore the quote so the customer can retry
        $this->checkoutSession->restoreQuote();

        $this->messageManager->addErrorMessage(
            (string) __('Payment was not completed. Please try again.')
        );
        return $redirect->setPath('checkout');
    }

    /**
     * Populate checkout session so the success page renders correctly.
     *
     * @param Order $order
     */
    private function setCheckoutSessionData(Order $order): void
    {
        $this->checkoutSession->setLastSuccessQuoteId((int) $order->getQuoteId());
        $this->checkoutSession->setLastQuoteId((int) $order->getQuoteId());
        $this->checkoutSession->setLastOrderId((int) $order->getEntityId());
        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
    }
}
