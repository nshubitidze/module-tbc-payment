<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper;

/**
 * Creates a Flitt payment order for redirect checkout mode.
 *
 * Called by the frontend JS AFTER the Magento order is placed. Builds signed params,
 * exchanges them for a checkout URL via the Flitt API, and returns the URL for redirect.
 *
 * IMPORTANT: In redirect mode, the Magento order already exists (unlike embed mode
 * where the order is created after Flitt approves). This is necessary because the
 * customer leaves our site and we need callback/return URLs tied to the order.
 */
class Redirect implements HttpPostActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly ResolverInterface $localeResolver,
        private readonly OrderPaymentRepositoryInterface $paymentRepository,
        private readonly UserFacingErrorMapper $userFacingErrorMapper,
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $order = $this->checkoutSession->getLastRealOrder();

            if (!$order || !$order->getEntityId()) {
                throw new LocalizedException(__('No order found.'));
            }

            if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
                throw new LocalizedException(__('Order is not in pending payment state.'));
            }

            $storeId = (int) $order->getStoreId();
            $merchantId = $this->config->getMerchantId($storeId);
            $password = $this->config->getPassword($storeId);
            $apiUrl = $this->config->getApiUrl($storeId);

            if ($merchantId === '' || $password === '') {
                throw new LocalizedException(__('TBC payment gateway is not configured.'));
            }

            if ($apiUrl === '') {
                throw new LocalizedException(__('TBC payment API URL is not configured.'));
            }

            $incrementId = (string) $order->getIncrementId();
            $amount = (int) round((float) $order->getGrandTotal() * 100);
            $currency = (string) ($order->getOrderCurrencyCode() ?: 'GEL');
            $flittOrderId = 'duka_' . $incrementId . '_' . time();

            $senderEmail = (string) ($order->getCustomerEmail() ?: '');

            $params = [
                'order_id'            => $flittOrderId,
                'merchant_id'         => $merchantId,
                'order_desc'          => (string) __('Order %1', $incrementId),
                'amount'              => $amount,
                'currency'            => $currency,
                'sender_email'        => $senderEmail,
                'lang'                => $this->resolveLanguage(),
                'response_url'        => $this->urlBuilder->getUrl(
                    'shubo_tbc/payment/returnAction',
                    ['_nosid' => true],
                ),
                'server_callback_url' => $this->urlBuilder->getUrl(
                    'shubo_tbc/payment/callback',
                    ['_nosid' => true],
                ),
                'delayed'             => 'Y',
                'lifetime'            => $this->config->getPaymentLifetime($storeId),
                'merchant_data'       => (string) __('Magento Order %1, Store %2', $incrementId, $storeId),
            ];

            if ($this->config->isPreauth($storeId)) {
                $params['preauth'] = 'Y';
            }

            $params['signature'] = Config::generateSignature($params, $password);

            $checkoutUrl = $this->requestCheckoutUrl($apiUrl, $params, $storeId);

            // Store flitt_order_id on the order payment so the callback and return
            // controllers can correlate Flitt responses back to this Magento order.
            /** @var Payment|null $payment */
            $payment = $order->getPayment();
            if ($payment === null) {
                throw new LocalizedException(__('Order has no payment record.'));
            }
            $payment->setAdditionalInformation('flitt_order_id', $flittOrderId);
            $payment->setAdditionalInformation('checkout_type', 'redirect');
            // Persist via the payment repository explicitly. `orderRepository->save($order)`
            // does NOT reliably cascade changes to `additional_information` on an
            // order loaded via `checkoutSession->getLastRealOrder()` — observed
            // in production: flitt_order_id silently dropped, ReturnAction then
            // fails to match the order returned by Flitt, customer sees an error.
            $this->paymentRepository->save($payment);

            $this->logger->debug('TBC redirect: checkout URL obtained', [
                'order_id'      => $incrementId,
                'flitt_order_id' => $flittOrderId,
            ]);

            return $result->setData([
                'success'      => true,
                'checkout_url' => $checkoutUrl,
            ]);
        } catch (LocalizedException $e) {
            $this->logger->error('TBC Redirect error: ' . $e->getMessage());

            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('TBC Redirect error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $result->setData([
                'success' => false,
                'message' => (string) __('Unable to initialize payment. Please try again.'),
            ]);
        }
    }

    /**
     * Exchange signed params for a Flitt checkout URL via the API.
     *
     * Flitt has two endpoints:
     *   /api/checkout/token  -> returns `token` only (for embedded SDK)
     *   /api/checkout/url    -> returns `checkout_url` + `payment_id` (for redirect)
     * We use the URL endpoint here.
     *
     * @param array<string, mixed> $params Signed payment parameters
     * @throws LocalizedException When the API call fails or returns an error
     */
    private function requestCheckoutUrl(string $apiUrl, array $params, int $storeId): string
    {
        $tokenUrl = $apiUrl . '/api/checkout/url';
        $requestBody = $this->json->serialize(['request' => $params]);

        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        // Bound the API call so a hung Flitt token endpoint cannot exhaust PHP workers.
        $curl->setTimeout(30);
        $curl->setOptions([
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $curl->post($tokenUrl, (string) $requestBody);

        $responseBody = $curl->getBody();
        $httpStatus = $curl->getStatus();

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('TBC redirect API request', [
                'url'    => $tokenUrl,
                'params' => array_diff_key($params, ['signature' => true]),
            ]);
            $this->logger->debug('TBC redirect API response', [
                'http_status' => $httpStatus,
                'body'        => $responseBody,
            ]);
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new LocalizedException(
                __('Flitt API returned HTTP %1.', $httpStatus),
            );
        }

        /** @var array{response?: array{response_status?: string, checkout_url?: string, error_message?: string}} $responseData */
        $responseData = $this->json->unserialize($responseBody);

        $response = $responseData['response'] ?? [];
        $status = $response['response_status'] ?? '';
        $checkoutUrl = $response['checkout_url'] ?? '';

        if ($status !== 'success' || $checkoutUrl === '') {
            // Log the raw Flitt triple BEFORE mapping so ops / support can
            // correlate the friendly user message back to the Flitt side
            // via request_id. The mapper itself is a pure function and does
            // no logging — contract is documented in error-code-map.md §3.
            $rawErrorCode = $response['error_code'] ?? 0;
            $rawErrorMessage = (string) ($response['error_message'] ?? '');
            $requestId = isset($response['request_id'])
                ? (string) $response['request_id']
                : null;

            $this->logger->error('TBC Flitt error mapped to user copy', [
                'context'         => 'redirect.checkout_url',
                'error_code'      => $rawErrorCode,
                'error_message'   => $rawErrorMessage,
                'request_id'      => $requestId,
                'response_status' => $status,
            ]);

            throw $this->userFacingErrorMapper->toLocalizedException(
                $rawErrorCode,
                $rawErrorMessage,
                $requestId,
            );
        }

        return $checkoutUrl;
    }

    private function resolveLanguage(): string
    {
        $locale = $this->localeResolver->getLocale();
        $language = substr($locale, 0, 2);

        return match ($language) {
            'ka'    => 'ka',
            'ru'    => 'ru',
            default => 'en',
        };
    }
}
