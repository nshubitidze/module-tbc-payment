<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Http\Client\StatusClient;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
use Shubo\TbcPayment\Model\FlittStatus;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;
use Shubo\TbcPayment\Service\ApprovalContext;
use Shubo\TbcPayment\Service\ApprovalResult;
use Shubo\TbcPayment\Service\OrderApprovalApplier;
use Shubo\TbcPayment\Service\PaymentLock;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * Admin controller to check and sync the Flitt payment status for a TBC order.
 *
 * Queries the Flitt API. If the payment is approved but the order hasn't been
 * updated yet, processes the approval (capture + invoice).
 */
class CheckStatus extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_TbcPayment::check_status';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StatusClient $statusClient,
        private readonly CallbackValidator $callbackValidator,
        private readonly OrderApprovalApplier $approvalApplier,
        private readonly SettlementService $settlementService,
        private readonly LoggerInterface $logger,
        private readonly PaymentLock $paymentLock,
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
            $flittOrderId = (string) $payment->getAdditionalInformation('flitt_order_id');

            if ($flittOrderId === '') {
                $this->messageManager->addWarningMessage((string) __('No Flitt order ID found.'));
                return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
            }

            $storeId = (int) $order->getStoreId();
            // StatusClient::checkStatus already unwraps the Flitt `response` envelope.
            $responseData = $this->statusClient->checkStatus($flittOrderId, $storeId);
            $flittStatus = $responseData['order_status'] ?? 'unknown';

            $this->messageManager->addSuccessMessage(
                (string) __('Flitt payment status: %1 | Payment ID: %2 | Card: %3',
                    $flittStatus,
                    $responseData['payment_id'] ?? 'N/A',
                    $responseData['masked_card'] ?? 'N/A'
                )
            );

            // If Flitt says approved but order is still pending — process it
            if (
                $flittStatus === FlittStatus::APPROVED
                && in_array($order->getState(), [Order::STATE_PAYMENT_REVIEW, Order::STATE_PENDING_PAYMENT], true)
            ) {
                if (!$this->callbackValidator->validate($responseData, $storeId)) {
                    $this->messageManager->addErrorMessage((string) __('Signature validation failed. Order not updated.'));
                    return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
                }

                // IMPROVE-2: serialize against the frontend capture paths + cron
                // via the advisory lock, keyed by flitt_order_id. On contention a
                // concurrent path is finalising the order — tell the admin to
                // refresh rather than racing a second capture.
                $applyResult = $this->paymentLock->withLock(
                    $flittOrderId,
                    fn (): ApprovalResult => $this->applyApproval($orderId, $responseData)
                );

                if ($applyResult === null) {
                    $this->messageManager->addNoticeMessage(
                        (string) __('Another process is finalising this payment. Please refresh in a moment.')
                    );
                    return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
                }

                // IMPROVE-8: the applier refused the capture because the Flitt
                // amount diverged from the order grand total (cart edited
                // mid-flow). It logged at `critical` and mutated nothing; surface
                // it to the admin and leave the order for manual reconciliation.
                if ($applyResult === ApprovalResult::RefusedAmountMismatch) {
                    $this->messageManager->addErrorMessage(
                        (string) __(
                            'Payment amount does not match the order total. Capture refused. '
                            . 'Please reconcile this order manually.'
                        )
                    );
                    return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
                }

                $this->messageManager->addSuccessMessage(
                    (string) __('Order updated to processing. Payment captured.')
                );
            } elseif (
                in_array($flittStatus, [FlittStatus::DECLINED, FlittStatus::EXPIRED], true)
                && $order->getState() !== Order::STATE_CANCELED
            ) {
                $order->cancel();
                $order->addCommentToStatusHistory(
                    (string) __('Order cancelled after manual status check. Flitt status: %1', $flittStatus)
                );
                $this->orderRepository->save($order);
                $this->messageManager->addWarningMessage(
                    (string) __('Payment %1. Order has been cancelled.', $flittStatus)
                );
            }
        } catch (LocalizedException $e) {
            // LocalizedException messages are explicitly authored for user
            // surfaces (every `__()` string in this module passes review).
            // Surface them as-is so guard messages ("Invalid payment method
            // for this action.") still reach the admin verbatim.
            $this->logger->error('Status check failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage((string) $e->getMessage());
        } catch (\Exception $e) {
            // Session 3 Pass 4 (reviewer-signoff §S-4 / architect-scope §2.2.4):
            // never surface raw \Exception text to the admin UI. The
            // controller is admin-only, so the leak is contained, but the
            // same "no raw triples to user copy" principle that drives
            // UserFacingErrorMapper applies here. The bland-but-no-leak
            // option is chosen because FlittApiException (a subclass of
            // LocalizedException — caught above) is the only structured
            // failure on this path; arbitrary \Exception leaks ($e->getMessage()
            // could be a stack-trace-style RuntimeException) are now
            // suppressed. When FlittApiException carries an error_code we
            // can route it through UserFacingErrorMapper for friendlier
            // copy than the bland default.
            $this->logger->error('Status check failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(
                (string) __('Status check failed. See shubo_tbc_payment.log for details.')
            );
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }

    /**
     * Apply the shared approve mutation while holding the PaymentLock.
     *
     * RELOADS the order from the repository inside the lock so the state
     * re-check reads committed state, not the pre-lock snapshot taken in
     * execute(). A serialized callback-then-CheckStatus (or two clicks) where a
     * concurrent path already promoted the order to PROCESSING is then a no-op:
     * we observe the persisted PROCESSING state on the fresh read and skip,
     * never re-entering apply() / re-firing registerCaptureNotification.
     * Persists + settles only on a real capture.
     *
     * @param array<string, mixed> $responseData
     */
    private function applyApproval(int $orderId, array $responseData): ApprovalResult
    {
        // Reload inside the lock: a concurrent capture path may have promoted
        // the order since the pre-lock state check in execute().
        /** @var Order $order */
        $order = $this->orderRepository->get($orderId);

        if ($order->getState() === Order::STATE_PROCESSING) {
            $this->logger->info('TBC CheckStatus: order already processing inside lock, skipping', [
                'order_id' => $order->getIncrementId(),
            ]);
            return ApprovalResult::AlreadyProcessed;
        }

        $applyResult = $this->approvalApplier->apply($order, $responseData, ApprovalContext::ManualStatusCheck);

        // IMPROVE-8: refused capture — nothing was mutated, do not persist.
        if ($applyResult === ApprovalResult::RefusedAmountMismatch) {
            return $applyResult;
        }

        $this->orderRepository->save($order);

        // Trigger settlement only when this run actually captured.
        if ($applyResult === ApprovalResult::Captured) {
            try {
                $this->settlementService->settle($order);
                $this->orderRepository->save($order);
            } catch (\Exception $e) {
                $this->logger->error('Settlement after manual status check failed', [
                    'order_id' => $order->getIncrementId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $applyResult;
    }
}
