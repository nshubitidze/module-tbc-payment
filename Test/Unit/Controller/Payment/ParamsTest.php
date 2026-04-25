<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Controller\Payment\Params;
use Shubo\TbcPayment\Gateway\Config\Config;

/**
 * Regression tests for BUG-1: Params controller must enforce a CURL timeout
 * so a hung Flitt token endpoint cannot exhaust PHP workers.
 */
class ParamsTest extends TestCase
{
    private JsonFactory&MockObject $jsonFactory;
    private CheckoutSession&MockObject $checkoutSession;
    private CartRepositoryInterface&MockObject $quoteRepository;
    private Config&MockObject $config;
    private UrlInterface&MockObject $urlBuilder;
    private LoggerInterface&MockObject $logger;
    private CurlFactory&MockObject $curlFactory;
    private Json&MockObject $json;
    private ResolverInterface&MockObject $localeResolver;
    private Curl&MockObject $curl;
    private JsonResult&MockObject $jsonResult;

    protected function setUp(): void
    {
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->json = $this->createMock(Json::class);
        $this->localeResolver = $this->createMock(ResolverInterface::class);
        $this->curl = $this->createMock(Curl::class);
        $this->jsonResult = $this->createMock(JsonResult::class);

        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->curlFactory->method('create')->willReturn($this->curl);
    }

    public function testTimeoutIsAppliedToCurlBeforePost(): void
    {
        $this->primeQuoteAndConfig();
        $this->json->method('serialize')->willReturn('{"request":{}}');
        $this->json->method('unserialize')->willReturn([
            'response' => ['response_status' => 'success', 'token' => 'tok-abc'],
        ]);

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"response":{"response_status":"success","token":"tok-abc"}}');

        // Verify timeout is set BEFORE the request fires.
        $callOrder = [];
        $this->curl->expects(self::atLeastOnce())
            ->method('setTimeout')
            ->willReturnCallback(static function (int $value) use (&$callOrder): void {
                $callOrder[] = ['setTimeout', $value];
            });

        $optionsApplied = [];
        $this->curl->expects(self::atLeastOnce())
            ->method('setOptions')
            ->willReturnCallback(static function (array $opts) use (&$callOrder, &$optionsApplied): void {
                $callOrder[] = ['setOptions', $opts];
                $optionsApplied = $opts;
            });

        $this->curl->expects(self::once())
            ->method('post')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = ['post'];
            });

        $controller = $this->buildController();
        $controller->execute();

        self::assertArrayHasKey(CURLOPT_TIMEOUT, $optionsApplied, 'CURLOPT_TIMEOUT must be set');
        self::assertArrayHasKey(CURLOPT_CONNECTTIMEOUT, $optionsApplied, 'CURLOPT_CONNECTTIMEOUT must be set');
        self::assertSame(30, $optionsApplied[CURLOPT_TIMEOUT]);
        self::assertSame(10, $optionsApplied[CURLOPT_CONNECTTIMEOUT]);

        // Ordering: timeouts MUST be configured before post() is invoked.
        $postIndex = array_search(['post'], $callOrder, true);
        self::assertNotFalse($postIndex);
        $optionsIndex = null;
        foreach ($callOrder as $i => $call) {
            if ($call[0] === 'setOptions') {
                $optionsIndex = $i;
                break;
            }
        }
        self::assertNotNull($optionsIndex);
        self::assertLessThan($postIndex, $optionsIndex, 'setOptions must run before post');
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
            curlFactory: $this->curlFactory,
            json: $this->json,
            localeResolver: $this->localeResolver,
        );
    }

    private function primeQuoteAndConfig(): void
    {
        $quotePayment = $this->getMockBuilder(QuotePayment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setAdditionalInformation'])
            ->getMock();
        $quotePayment->method('setAdditionalInformation')->willReturnSelf();

        // Quote uses DataObject magic getters; declare via addMethods.
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
        $this->localeResolver->method('getLocale')->willReturn('en_US');
    }
}
