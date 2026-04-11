<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Http\Client\StatusClient;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;
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
class ReturnAction implements HttpGetActionInterface, CsrfAwareActionInterface
{
    /**
     * @param \Magento\Framework\App\Request\Http $request
     * @param RedirectFactory $redirectFactory
     * @param CheckoutSession $checkoutSession
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param StatusClient $statusClient
     * @param CallbackValidator $callbackValidator
     * @param SettlementService $settlementService
     * @param MessageManager $messageManager
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly \Magento\Framework\App\Request\Http $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly StatusClient $statusClient,
        private readonly CallbackValidator $callbackValidator,
        private readonly SettlementService $settlementService,
        private readonly MessageManager $messageManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
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

            $order = $this->findOrderByFlittId($flittOrderId);

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

            // NEVER trust GET params — verify via Flitt Status API
            $response = $this->statusClient->checkStatus($flittOrderId, $storeId);
            $responseData = $response['response'] ?? $response;
            $flittStatus = (string) ($responseData['order_status'] ?? '');

            $this->logger->info('TBC Return: Flitt status check', [
                'order_id'       => $order->getIncrementId(),
                'flitt_order_id' => $flittOrderId,
                'flitt_status'   => $flittStatus,
            ]);

            if ($flittStatus === 'approved') {
                return $this->handleApproved($order, $responseData, $redirect);
            }

            if ($flittStatus === 'processing' || $flittStatus === 'created') {
                // Payment still in progress — show success page, callback/cron will finalize
                $this->setCheckoutSessionData($order);
                $this->messageManager->addNoticeMessage(
                    (string) __('Your payment is being processed. You will receive confirmation shortly.')
                );
                return $redirect->setPath('checkout/onepage/success');
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

        /** @var Payment $payment */
        $payment = $order->getPayment();

        $this->storePaymentDetails($payment, $responseData);

        $paymentId = (string) ($responseData['payment_id'] ?? '');
        if ($paymentId !== '') {
            $payment->setTransactionId($paymentId);
            $payment->setParentTransactionId($order->getIncrementId() . '-auth');
        }

        if ($this->config->isPreauth($storeId)) {
            $payment->setAdditionalInformation('preauth_approved', true);
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(false);
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __(
                    'Funds held by TBC Bank (redirect). Payment ID: %1. Use "Capture Payment" to charge.',
                    $paymentId
                )
            );
        } else {
            $amountMinor = (int) ($responseData['amount'] ?? (int) round($order->getGrandTotal() * 100));
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(true);
            $payment->registerCaptureNotification($amountMinor / 100);
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __('Payment approved by TBC Bank (redirect). Payment ID: %1', $paymentId)
            );
        }

        $this->orderRepository->save($order);

        try {
            $this->settlementService->settle($order);
            $this->orderRepository->save($order);
        } catch (\Exception $e) {
            $this->logger->error('TBC Return: settlement failed', [
                'order_id' => $order->getIncrementId(),
                'error'    => $e->getMessage(),
            ]);
        }

        $this->setCheckoutSessionData($order);

        $this->logger->info('TBC Return: order approved', [
            'order_id'   => $order->getIncrementId(),
            'payment_id' => $paymentId,
        ]);

        return $redirect->setPath('checkout/onepage/success');
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
     * Find the Magento order matching a Flitt order ID.
     *
     * Flitt order ID format: duka_{incrementId}_{timestamp}
     * We extract the increment ID, load the order, then verify the stored
     * flitt_order_id on the payment matches — guards against timing collisions.
     *
     * @param string $flittOrderId
     */
    private function findOrderByFlittId(string $flittOrderId): ?Order
    {
        if (!preg_match('/^duka_(.+)_\d+$/', $flittOrderId, $matches)) {
            $this->logger->warning('TBC Return: unrecognised Flitt order ID format', [
                'flitt_order_id' => $flittOrderId,
            ]);
            return null;
        }

        $incrementId = $matches[1];

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        /** @var Order|null $order */
        $order = reset($orders) ?: null;

        if ($order === null) {
            return null;
        }

        // Confirm the stored Flitt order ID matches — prevents cross-order collisions
        /** @var Payment|null $payment */
        $payment = $order->getPayment();
        if ($payment === null) {
            return null;
        }

        $storedFlittId = (string) $payment->getAdditionalInformation('flitt_order_id');
        if ($storedFlittId !== $flittOrderId) {
            $this->logger->warning('TBC Return: flitt_order_id mismatch on payment', [
                'flitt_order_id' => $flittOrderId,
                'stored'         => $storedFlittId,
                'order_id'       => $order->getIncrementId(),
            ]);
            return null;
        }

        return $order;
    }

    /**
     * Persist Flitt payment details onto the order payment additional info.
     *
     * @param Payment $payment
     * @param array<string, mixed> $responseData Flitt status API response payload
     */
    private function storePaymentDetails(Payment $payment, array $responseData): void
    {
        $infoKeys = [
            'payment_id', 'order_status', 'masked_card', 'rrn',
            'approval_code', 'tran_type', 'card_type', 'card_bin',
            'eci', 'fee', 'actual_amount', 'actual_currency',
        ];

        foreach ($infoKeys as $key) {
            if (!empty($responseData[$key])) {
                $payment->setAdditionalInformation($key, $responseData[$key]);
            }
        }

        $payment->setAdditionalInformation('awaiting_flitt_confirmation', false);
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
