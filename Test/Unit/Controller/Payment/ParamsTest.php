<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Shubo\TbcPayment\Service\FlittLanguageResolver;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Controller\Payment\Params;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;
use Shubo\TbcPayment\Gateway\Http\Client\FlittHttpClient;

/**
 * Params controller tests.
 *
 * Transport (curl/timeouts) now lives in FlittHttpClient and is covered by
 * FlittHttpClientTest; these tests assert the controller delegates to the token
 * endpoint without requesting a retry, and surfaces failures correctly.
 */
class ParamsTest extends TestCase
{
    private JsonFactory&MockObject $jsonFactory;
    private CheckoutSession&MockObject $checkoutSession;
    private CartRepositoryInterface&MockObject $quoteRepository;
    private Config&MockObject $config;
    private UrlInterface&MockObject $urlBuilder;
    private LoggerInterface&MockObject $logger;
    private FlittHttpClient&MockObject $httpClient;
    private FlittLanguageResolver&MockObject $languageResolver;
    private JsonResult&MockObject $jsonResult;
    private UserFacingErrorMapper&MockObject $userFacingErrorMapper;

    protected function setUp(): void
    {
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->httpClient = $this->createMock(FlittHttpClient::class);
        $this->languageResolver = $this->createMock(FlittLanguageResolver::class);
        $this->jsonResult = $this->createMock(JsonResult::class);
        $this->userFacingErrorMapper = $this->createMock(UserFacingErrorMapper::class);

        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->method('setData')->willReturnSelf();
    }

    public function testDelegatesToTokenEndpointWithoutRetry(): void
    {
        $this->primeQuoteAndConfig();

        $postedEndpoint = null;
        $postedRetryable = null;
        $this->httpClient->expects(self::once())
            ->method('post')
            ->willReturnCallback(
                function (
                    string $endpoint,
                    $body,
                    int $storeId,
                    bool $retryable = false
                ) use (
                    &$postedEndpoint,
                    &$postedRetryable
                ): array {
                    $postedEndpoint = $endpoint;
                    $postedRetryable = $retryable;
                    return ['response' => ['response_status' => 'success', 'token' => 'tok-abc']];
                }
            );

        $captured = null;
        $this->jsonResult->expects(self::atLeastOnce())
            ->method('setData')
            ->willReturnCallback(function (array $data) use (&$captured): JsonResult {
                $captured = $data;
                return $this->jsonResult;
            });

        $controller = $this->buildController();
        $controller->execute();

        self::assertSame('/api/checkout/token', $postedEndpoint);
        self::assertFalse($postedRetryable, 'Token minting must NOT be retryable');
        self::assertIsArray($captured);
        self::assertTrue($captured['success']);
        self::assertSame('tok-abc', $captured['token']);
    }

    public function testTransportFailureReturnsFriendlyError(): void
    {
        $this->primeQuoteAndConfig();

        $this->httpClient->method('post')
            ->willThrowException(new FlittApiException(__('Unable to reach the TBC payment gateway.')));

        $captured = null;
        $this->jsonResult->expects(self::atLeastOnce())
            ->method('setData')
            ->willReturnCallback(function (array $data) use (&$captured): JsonResult {
                $captured = $data;
                return $this->jsonResult;
            });

        $controller = $this->buildController();
        $controller->execute();

        self::assertIsArray($captured);
        self::assertFalse($captured['success']);
    }

    private function buildController(): Params
    {
        return new Params(
            jsonFactory: $this->jsonFactory,
            checkoutSession: $this->checkoutSession,
            quoteRepository: $this->quoteRepository,
            config: $this->config,
            urlBuilder: $this->urlBuilder,
            logger: $this->logger,
            httpClient: $this->httpClient,
            languageResolver: $this->languageResolver,
            userFacingErrorMapper: $this->userFacingErrorMapper,
        );
    }

    private function primeQuoteAndConfig(): void
    {
        $quotePayment = $this->getMockBuilder(QuotePayment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setAdditionalInformation'])
            ->getMock();
        $quotePayment->method('setAdditionalInformation')->willReturnSelf();

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getStoreId', 'getPayment', 'reserveOrderId', 'getReservedOrderId'])
            ->addMethods(['getGrandTotal', 'getQuoteCurrencyCode', 'getCustomerEmail'])
            ->getMock();
        $quote->method('getId')->willReturn(7);
        $quote->method('getGrandTotal')->willReturn(10.00);
        $quote->method('getReservedOrderId')->willReturn('000000042');
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getQuoteCurrencyCode')->willReturn('GEL');
        $quote->method('getCustomerEmail')->willReturn('buyer@example.com');
        $quote->method('getPayment')->willReturn($quotePayment);

        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $this->config->method('getMerchantId')->willReturn('1549901');
        $this->config->method('getPassword')->willReturn('test');
        $this->config->method('getApiUrl')->willReturn('https://pay.flitt.com');
        $this->config->method('getPaymentLifetime')->willReturn(3600);
        $this->config->method('isPreauth')->willReturn(false);
        $this->config->method('isDebugEnabled')->willReturn(false);

        $this->urlBuilder->method('getUrl')->willReturn('https://duka.ge/cb');
        $this->languageResolver->method('resolve')->willReturn('en');
    }
}
