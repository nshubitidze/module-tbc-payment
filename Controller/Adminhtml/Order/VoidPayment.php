<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;
use Shubo\TbcPayment\Gateway\Http\Client\VoidClient;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;

/**
 * Admin controller to void a pre-authorized TBC payment.
 *
 * Calls the Flitt reverse API to release the held pre-auth amount, then cancels
 * the Magento order. If the upstream reverse call fails, the local cancel still
 * proceeds (soft-fail per CLAUDE.md §10 — reversal is cleanup, local cancel is
 * the contract).
 *
 * IMPROVE-7 hardening:
 *  - POST-only ({@see HttpPostActionInterface}); the form-key POST is emitted by
 *    {@see \Shubo\TbcPayment\Plugin\AddSettleButton}. A void mutates money + order
 *    state, so it must not be GET-reachable (CSRF / accidental crawl).
 *  - A server-side guard mirrors the AddSettleButton button conditions: only an
 *    un-captured preauth order in PROCESSING is voidable. Already-settled /
 *    invoiced / captured / non-preauth orders are rejected with a clear message
 *    and NO reverse is attempted (Order::cancel() no-ops on invoiced orders and
 *    a reverse on captured funds would be wrong — issue a credit memo instead).
 *  - The reverse releases the AUTHORIZED (held) amount, not a fresh
 *    grand_total*100. The amount is read from the payment's authorized amount,
 *    falling back to the order grand total when the gateway flow did not record a
 *    distinct authorization figure (the hold equals the full order total here).
 *
 * SIMPLIFY-5 — the wire-payload build + Flitt signature now live in
 * {@see VoidClient}; this controller only hands order-level inputs to the client.
 */
class VoidPayment extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Shubo_TbcPayment::void';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
        private readonly VoidClient $voidClient,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);

            /** @var Payment|null $payment */
            $payment = $order->getPayment();
            if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
                throw new LocalizedException(__('Invalid payment method for this action.'));
            }

            // Server-side voidability guard — mirrors AddSettleButton's Void
            // button conditions so the controller cannot be driven into voiding
            // captured/invoiced/non-preauth funds. Order::cancel() no-ops on
            // invoiced orders; a reverse on captured funds is simply wrong.
            if (!$this->isVoidable($order, $payment)) {
                throw new LocalizedException(__(
                    'This order cannot be voided. Only an authorized (held) payment that has not been '
                    . 'captured can be voided; capture has already happened or the order is no longer in '
                    . 'a voidable state. Issue a credit memo to refund a captured payment.'
                ));
            }

            $storeId = (int) $order->getStoreId();
            $flittOrderId = (string) $payment->getAdditionalInformation('flitt_order_id');
            $currency = (string) $order->getOrderCurrencyCode();
            // Reverse exactly the held authorization, in tetri (integer money).
            $amountMinor = $this->authorizedAmountMinor($order, $payment);

            $reverseStatus = '';
            $reverseSucceeded = false;

            if ($flittOrderId !== '') {
                try {
                    $response = $this->voidClient->reverse($flittOrderId, $amountMinor, $currency, $storeId);
                    $responseData = $response['response'] ?? $response;
                    /** @var array<string, mixed> $responseData */
                    $reverseStatus = (string) ($responseData['reverse_status'] ?? '');

                    if ($reverseStatus === 'approved' || $reverseStatus === 'success') {
                        $payment->setAdditionalInformation('reverse_status', $reverseStatus);
                        $reverseSucceeded = true;
                    } else {
                        $errorMsg = (string) ($responseData['error_message']
                            ?? $responseData['response_description']
                            ?? 'Unknown');
                        $this->logger->warning('Flitt reverse did not approve', [
                            'order_id' => $orderId,
                            'flitt_order_id' => $flittOrderId,
                            'reverse_status' => $reverseStatus,
                            'error_message' => $errorMsg,
                        ]);
                        $this->messageManager->addWarningMessage((string) __(
                            'Pre-auth hold could not be released at the bank; order was still cancelled locally.'
                        ));
                    }
                } catch (FlittApiException $e) {
                    $this->logger->error('Flitt reverse call failed', [
                        'order_id' => $orderId,
                        'flitt_order_id' => $flittOrderId,
                        'error' => $e->getMessage(),
                    ]);
                    $this->messageManager->addWarningMessage((string) __(
                        'Pre-auth hold could not be released at the bank; order was still cancelled locally.'
                    ));
                }
            }

            $payment->setAdditionalInformation('preauth_approved', false);

            $order->cancel();
            if ($reverseSucceeded) {
                $order->addCommentToStatusHistory(
                    (string) __(
                        'Payment voided by admin via Flitt reverse API. Status: %1. Order cancelled.',
                        $reverseStatus
                    )
                );
            } else {
                $order->addCommentToStatusHistory(
                    (string) __('Payment voided by admin. Order cancelled.')
                );
            }
            $this->orderRepository->save($order);

            $this->messageManager->addSuccessMessage((string) __('Payment has been voided and order cancelled.'));
        } catch (LocalizedException $e) {
            // LocalizedException is by Magento convention author-safe to show.
            $this->logger->error('TBC void payment — LocalizedException', [
                'order_id' => $orderId,
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->logger->error('Void payment failed', [
                'order_id' => $orderId,
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->messageManager->addErrorMessage(
                (string) __('Void failed. See shubo_tbc_payment.log for details.')
            );
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }

    /**
     * An order is voidable only when it holds an un-captured pre-auth in
     * PROCESSING — the exact conditions under which AddSettleButton renders the
     * Void button. Keeping the predicate here (not just in the button plugin)
     * is the real enforcement; the button is only UX.
     */
    private function isVoidable(Order $order, Payment $payment): bool
    {
        return $order->getState() === Order::STATE_PROCESSING
            && (bool) $payment->getAdditionalInformation('preauth_approved')
            && $payment->getAdditionalInformation('capture_status') !== 'captured';
    }

    /**
     * The held authorization amount in minor units (tetri).
     *
     * Prefers the payment's recorded authorized amount; falls back to the order
     * grand total when the gateway flow did not register a distinct
     * authorization figure (in this preauth flow the hold equals the full order
     * total). Integer money only (CLAUDE.md #6) — round once at the boundary.
     */
    private function authorizedAmountMinor(Order $order, Payment $payment): int
    {
        $authorized = (float) $payment->getAmountAuthorized();
        if ($authorized > 0.0) {
            return (int) round($authorized * 100);
        }

        return (int) round((float) $order->getGrandTotal() * 100);
    }
}
