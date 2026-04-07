<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;

/**
 * AJAX endpoint that obtains a Flitt checkout token for the active quote.
 *
 * Called by the frontend JS component BEFORE order placement so the embedded card
 * form can be initialized. The controller builds signed params, exchanges them for
 * a token via the Flitt API, and returns only the token to the frontend.
 */
class Params implements HttpPostActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly ResolverInterface $localeResolver,
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $quote = $this->checkoutSession->getQuote();

            if (!$quote || !$quote->getId()) {
                throw new LocalizedException(__('No active quote found.'));
            }

            if (!$quote->getGrandTotal() || $quote->getGrandTotal() <= 0) {
                throw new LocalizedException(__('Quote has no items or zero total.'));
            }

            // Reserve order ID on the quote if not already reserved
            if (!$quote->getReservedOrderId()) {
                $quote->reserveOrderId();
                $this->quoteRepository->save($quote);
            }

            $storeId = (int) $quote->getStoreId();
            $merchantId = $this->config->getMerchantId($storeId);
            $password = $this->config->getPassword($storeId);
            $apiUrl = $this->config->getApiUrl($storeId);

            if (empty($merchantId) || empty($password)) {
                throw new LocalizedException(__('TBC payment gateway is not configured.'));
            }

            if (empty($apiUrl)) {
                throw new LocalizedException(__('TBC payment API URL is not configured.'));
            }

            $reservedOrderId = (string) $quote->getReservedOrderId();
            $amount = (int) round((float) $quote->getGrandTotal() * 100);
            $currency = (string) ($quote->getQuoteCurrencyCode() ?: 'GEL');

            // Prefix order_id to avoid collisions on shared test merchants.
            // The callback strips this prefix to find the Magento order.
            $flittOrderId = 'duka_' . $reservedOrderId . '_' . time();

            $senderEmail = (string) ($quote->getCustomerEmail() ?: '');

            $params = [
                'order_id' => $flittOrderId,
                'merchant_id' => $merchantId,
                'order_desc' => (string) __('Order %1', $reservedOrderId),
                'amount' => $amount,
                'currency' => $currency,
                'sender_email' => $senderEmail,
                'lang' => $this->resolveLanguage(),
                'response_url' => $this->urlBuilder->getUrl(
                    'checkout/onepage/success',
                    ['_nosid' => true],
                ),
                'server_callback_url' => $this->urlBuilder->getUrl(
                    'shubo_tbc/payment/callback',
                    ['_nosid' => true],
                ),
                'delayed' => 'Y',
                'lifetime' => $this->config->getPaymentLifetime($storeId),
                'merchant_data' => (string) __('Magento Order %1, Store %2', $reservedOrderId, $storeId),
            ];

            if ($this->config->isPreauth($storeId)) {
                $params['preauth'] = 'Y';
            }

            $params['signature'] = Config::generateSignature($params, $password);

            $token = $this->requestCheckoutToken($apiUrl, $params, $storeId);

            // Store flitt_order_id on quote payment so it carries over to the order.
            // The cron reconciler and refund builder need this to reference the Flitt order.
            $quotePayment = $quote->getPayment();
            $quotePayment->setAdditionalInformation('flitt_order_id', $flittOrderId);
            $this->quoteRepository->save($quote);

            $this->logger->debug('TBC checkout token obtained', [
                'order_id' => $reservedOrderId,
                'amount' => $amount,
                'currency' => $currency,
            ]);

            return $result->setData([
                'success' => true,
                'token' => $token,
            ]);
        } catch (LocalizedException $e) {
            $this->logger->error('TBC Params error: ' . $e->getMessage());

            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('TBC Params error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $result->setData([
                'success' => false,
                'message' => (string) __('Unable to initialize payment. Please try again.'),
            ]);
        }
    }

    /**
     * Exchange signed params for a Flitt checkout token via the API.
     *
     * @param array<string, mixed> $params Signed payment parameters
     * @throws LocalizedException When the API call fails or returns an error
     */
    private function requestCheckoutToken(string $apiUrl, array $params, int $storeId): string
    {
        $tokenUrl = $apiUrl . '/api/checkout/token';
        $requestBody = $this->json->serialize(['request' => $params]);

        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->post($tokenUrl, (string) $requestBody);

        $responseBody = $curl->getBody();
        $httpStatus = $curl->getStatus();

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('TBC token API request', [
                'url' => $tokenUrl,
                'params' => array_diff_key($params, ['signature' => true]),
            ]);
            $this->logger->debug('TBC token API response', [
                'http_status' => $httpStatus,
                'body' => $responseBody,
            ]);
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new LocalizedException(
                __('Flitt API returned HTTP %1.', $httpStatus),
            );
        }

        /** @var array{response?: array{response_status?: string, token?: string, error_message?: string}} $responseData */
        $responseData = $this->json->unserialize($responseBody);

        $response = $responseData['response'] ?? [];
        $status = $response['response_status'] ?? '';
        $token = $response['token'] ?? '';

        if ($status !== 'success' || $token === '') {
            $errorMessage = $response['error_message'] ?? __('Unknown error from Flitt API.');
            $this->logger->error('TBC token API error', [
                'response_status' => $status,
                'error_message' => $errorMessage,
            ]);

            throw new LocalizedException(
                __('Payment gateway error: %1', $errorMessage),
            );
        }

        return $token;
    }

    /**
     * Map current store locale to a Flitt-supported language code.
     */
    private function resolveLanguage(): string
    {
        $locale = $this->localeResolver->getLocale();
        $language = substr($locale, 0, 2);

        return match ($language) {
            'ka' => 'ka',
            'ru' => 'ru',
            default => 'en',
        };
    }
}
