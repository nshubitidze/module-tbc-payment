<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;

/**
 * Cancels a just-placed TBC redirect-mode order when the client-side
 * `initiateRedirect` call fails (BUG-14).
 *
 * In redirect mode the JS calls `getPlaceOrderDeferredObject()` BEFORE
 * hitting the Flitt token endpoint. If token fetching fails (500, CSP,
 * timeout), the Magento order has already been placed and the quote has
 * been consumed — leaving an orphan order in `pending_payment` that the
 * customer cannot complete.
 *
 * The client JS must call this endpoint with the just-placed order's
 * `increment_id` so we can cancel it and let the customer retry.
 *
 * Guards (enforced):
 *   - Order exists and can be loaded by increment_id.
 *   - Order is still in `pending_payment` state (has not been captured
 *     by the callback/reconciler in the meantime).
 *   - Order has no invoices (defence-in-depth against race with cron).
 *   - Order's payment method is exactly `shubo_tbc` — we never let this
 *     endpoint be used to cancel non-TBC orders.
 *
 * CSRF: exempt because this is called from our own checkout JS with a
 * form_key (validated by the default FormKeyValidator plugin on the POST
 * route). Exempting here prevents spurious CSRF errors when the plugin
 * is unavailable in tests, matching the pattern used by Callback.php
 * and ReturnAction.php.
 */
class AbortRedirect implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly HttpRequest $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute(): ResultInterface
    {
        /** @var JsonResult $result */
        $result = $this->jsonFactory->create();

        $incrementId = trim((string) $this->request->getParam('increment_id', ''));

        if ($incrementId === '') {
            $this->logger->warning('TBC abortRedirect: missing increment_id');
            return $result->setData([
                'success' => false,
                'message' => (string) __('Missing order reference.'),
            ]);
        }

        try {
            $order = $this->loadOrder($incrementId);

            if ($order === null) {
                $this->logger->warning('TBC abortRedirect: order not found', [
                    'increment_id' => $incrementId,
                ]);
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Order not found.'),
                ]);
            }

            if (!$this->isCancelable($order)) {
                $this->logger->warning('TBC abortRedirect: order not in a cancelable state', [
                    'increment_id' => $incrementId,
                    'state' => $order->getState(),
                    'invoices' => $order->getInvoiceCollection()->getSize(),
                    'method' => $this->getPaymentMethod($order),
                ]);
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Order is not in a cancelable state.'),
                ]);
            }

            $order->cancel();
            $order->addCommentToStatusHistory(
                (string) __('Payment redirect initialization failed. Order cancelled so customer can retry.')
            );
            $this->orderRepository->save($order);

            // Best-effort: restore the quote so the customer's cart is repopulated
            // and they can try again without having to rebuild the cart.
            try {
                $this->checkoutSession->restoreQuote();
            } catch (\Exception $e) {
                $this->logger->warning('TBC abortRedirect: restoreQuote failed', [
                    'increment_id' => $incrementId,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->logger->info('TBC abortRedirect: orphan order cancelled', [
                'increment_id' => $incrementId,
            ]);

            return $result->setData([
                'success' => true,
                'increment_id' => $incrementId,
                'message' => (string) __('Order has been cancelled. You may retry payment.'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('TBC abortRedirect: unexpected error', [
                'increment_id' => $incrementId,
                'exception' => $e,
            ]);

            return $result->setData([
                'success' => false,
                'message' => (string) __('Unable to cancel order.'),
            ]);
        }
    }

    /**
     * Return true iff the order is safe to cancel via this endpoint:
     * still in pending_payment, uninvoiced, and paid via shubo_tbc.
     */
    private function isCancelable(Order $order): bool
    {
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
            return false;
        }

        if ($order->getInvoiceCollection()->getSize() > 0) {
            return false;
        }

        if ($this->getPaymentMethod($order) !== ConfigProvider::CODE) {
            return false;
        }

        return true;
    }

    private function getPaymentMethod(Order $order): string
    {
        /** @var Payment|null $payment */
        $payment = $order->getPayment();

        if ($payment === null) {
            return '';
        }

        return (string) $payment->getMethod();
    }

    private function loadOrder(string $incrementId): ?Order
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        /** @var Order|null $order */
        $order = reset($orders) ?: null;

        return $order;
    }
}
