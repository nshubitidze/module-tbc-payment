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
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;
use Shubo\TbcPayment\Gateway\Http\Client\VoidClient;
use Shubo\TbcPayment\Model\Ui\ConfigProvider;

/**
 * Admin controller to void a pending or pre-authorized TBC payment.
 *
 * Calls the Flitt reverse API to release the pre-auth hold, then cancels the
 * Magento order. If the upstream reverse call fails, the local cancel still
 * proceeds (soft-fail per CLAUDE.md §10 — reversal is cleanup, local cancel
 * is the contract).
 */
class VoidPayment extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_TbcPayment::void';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
        private readonly VoidClient $voidClient,
        private readonly Config $config,
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

            $storeId = (int) $order->getStoreId();
            $flittOrderId = (string) $payment->getAdditionalInformation('flitt_order_id');

            $reverseStatus = '';
            $reverseSucceeded = false;

            if ($flittOrderId !== '') {
                try {
                    $amount = (int) round((float) $order->getGrandTotal() * 100);
                    $currency = (string) $order->getOrderCurrencyCode();
                    $merchantId = $this->config->getMerchantId($storeId);
                    $password = $this->config->getPassword($storeId);

                    $params = [
                        'order_id' => $flittOrderId,
                        'merchant_id' => $merchantId,
                        'amount' => (string) $amount,
                        'currency' => $currency,
                    ];
                    $params['signature'] = Config::generateSignature($params, $password);

                    $response = $this->voidClient->reverse($params, $storeId);
                    $responseData = $response['response'] ?? $response;
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
        } catch (\Exception $e) {
            $this->logger->error('Void payment failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage((string) __('Void failed: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
