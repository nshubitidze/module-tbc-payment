<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;

/**
 * AJAX endpoint for generating Flitt Embed payment parameters from the active quote.
 *
 * Called by the frontend JS component BEFORE order placement so the embedded card
 * form can be initialized. The password/secret is used only for signature generation
 * and is never exposed to the frontend.
 */
class Params implements HttpPostActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly Config $config,
        private readonly ResolverInterface $localeResolver,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger,
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

            if (empty($merchantId) || empty($password)) {
                throw new LocalizedException(__('TBC payment gateway is not configured.'));
            }

            $reservedOrderId = (string) $quote->getReservedOrderId();
            $amount = (string) (int) round((float) $quote->getGrandTotal() * 100);
            $currency = (string) ($quote->getQuoteCurrencyCode() ?: 'GEL');
            $locale = $this->resolveLanguage();

            $params = [
                'order_id' => $reservedOrderId,
                'merchant_id' => (string) $merchantId,
                'order_desc' => (string) __('Order %1', $reservedOrderId),
                'amount' => $amount,
                'currency' => $currency,
                'lang' => $locale,
                'preauth' => 'Y',
                'server_callback_url' => $this->urlBuilder->getUrl(
                    'shubo_tbc/payment/callback',
                    ['_nosid' => true],
                ),
            ];

            $params['signature'] = Config::generateSignature($params, $password);

            $this->logger->debug('TBC Params generated', [
                'order_id' => $reservedOrderId,
                'amount' => $amount,
                'currency' => $currency,
            ]);

            return $result->setData([
                'success' => true,
                'params' => $params,
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
     * Map Magento locale to Flitt-supported language code.
     *
     * Flitt supports: ka (Georgian), en (English), ru (Russian).
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
