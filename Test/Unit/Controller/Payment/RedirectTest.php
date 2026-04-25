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
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Controller\Payment\Redirect;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper;

/**
 * Regression tests for BUG-2: Redirect controller must enforce a CURL timeout
 * so a hung Flitt token endpoint cannot exhaust PHP workers (worse than BUG-1
 * because the order already exists at this point).
 */
class RedirectTest extends TestCase
{
    private JsonFactory&MockObject $jsonFactory;
    private CheckoutSession&MockObject $checkoutSession;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private Config&MockObject $config;
    private UrlInterface&MockObject $urlBuilder;
    private LoggerInterface&MockObject $logger;
    private CurlFactory&MockObject $curlFactory;
    private Json&MockObject $json;
    private ResolverInterface&MockObject $localeResolver;
    private Curl&MockObject $curl;
    private JsonResult&MockObject $jsonResult;
    private OrderPaymentRepositoryInterface&MockObject $paymentRepository;
    private UserFacingErrorMapper&MockObject $userFacingErrorMapper;

    protected function setUp(): void
    {
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->json = $this->createMock(Json::class);
        $this->localeResolver = $this->createMock(ResolverInterface::class);
        $this->curl = $this->createMock(Curl::class);
        $this->jsonResult = $this->createMock(JsonResult::class);
        $this->paymentRepository = $this->createMock(OrderPaymentRepositoryInterface::class);
        $this->userFacingErrorMapper = $this->createMock(UserFacingErrorMapper::class);

        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->curlFactory->method('create')->willReturn($this->curl);
    }

    public function testTimeoutIsAppliedToCurlBeforePost(): void
    {
        $this->primeOrderAndConfig();
        $this->json->method('serialize')->willReturn('{"request":{}}');
        $this->json->method('unserialize')->willReturn([
            'response' => ['response_status' => 'success', 'checkout_url' => 'https://pay.flitt.com/c/x'],
        ]);

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(
            '{"response":{"response_status":"success","checkout_url":"https://pay.flitt.com/c/x"}}'
        );

        $callOrder = [];
        $optionsApplied = [];
        $this->curl->expects(self::atLeastOnce())
            ->method('setTimeout')
            ->willReturnCallback(static function (int $value) use (&$callOrder): void {
                $callOrder[] = ['setTimeout', $value];
            });
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

        $controller = new Redirect(
            jsonFactory: $this->jsonFactory,
            checkoutSession: $this->checkoutSession,
            config: $this->config,
            urlBuilder: $this->urlBuilder,
            logger: $this->logger,
            curlFactory: $this->curlFactory,
            json: $this->json,
            localeResolver: $this->localeResolver,
            paymentRepository: $this->paymentRepository,
            userFacingErrorMapper: $this->userFacingErrorMapper,
        );
        $controller->execute();

        self::assertArrayHasKey(CURLOPT_TIMEOUT, $optionsApplied);
        self::assertArrayHasKey(CURLOPT_CONNECTTIMEOUT, $optionsApplied);
        self::assertSame(30, $optionsApplied[CURLOPT_TIMEOUT]);
        self::assertSame(10, $optionsApplied[CURLOPT_CONNECTTIMEOUT]);

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
        self::assertLessThan($postIndex, $optionsIndex);
    }

    /**
     * Regression for the bug where Redirect was posting to /api/checkout/token (the
     * embed-SDK endpoint that returns only `{token}`) instead of /api/checkout/url
     * (the redirect endpoint that returns `{checkout_url, payment_id}`). The old
     * mocks fed back a fake `checkout_url` key so unit tests passed while the real
     * API responded with empty checkout_url, surfacing as "Unknown error from Flitt
     * API" in the UI.
     */
    public function testPostsToCheckoutUrlEndpointNotTokenEndpoint(): void
    {
        $this->primeOrderAndConfig();
        $this->json->method('serialize')->willReturn('{"request":{}}');
        $this->json->method('unserialize')->willReturn([
            'response' => [
                'response_status' => 'success',
                'checkout_url'    => 'https://pay.flitt.com/merchants/abc/default/index.html?token=t',
                'payment_id'      => '12345',
            ],
        ]);
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(
            '{"response":{"response_status":"success","checkout_url":"https://pay.flitt.com/x","payment_id":"12345"}}'
        );

        $postedUrl = null;
        $this->curl->expects(self::once())
            ->method('post')
            ->willReturnCallback(static function (string $url) use (&$postedUrl): void {
                $postedUrl = $url;
            });

        $controller = new Redirect(
            jsonFactory: $this->jsonFactory,
            checkoutSession: $this->checkoutSession,
            config: $this->config,
            urlBuilder: $this->urlBuilder,
            logger: $this->logger,
            curlFactory: $this->curlFactory,
            json: $this->json,
            localeResolver: $this->localeResolver,
            paymentRepository: $this->paymentRepository,
            userFacingErrorMapper: $this->userFacingErrorMapper,
        );
        $controller->execute();

        self::assertSame('https://pay.flitt.com/api/checkout/url', $postedUrl);
        self::assertStringNotContainsString('/api/checkout/token', (string) $postedUrl);
    }

    /**
     * Regression for the bug where `flitt_order_id` (and `checkout_type`) were
     * set on the payment via `setAdditionalInformation()` but then only
     * `orderRepository->save($order)` was called. On prod this silently dropped
     * the keys — ReturnAction then could not match the order Flitt redirected
     * back for, surfacing as "Payment information not found" + redirect to cart.
     * Fix: persist the payment explicitly via OrderPaymentRepositoryInterface.
     */
    public function testPersistsFlittOrderIdViaPaymentRepository(): void
    {
        $this->primeOrderAndConfig();
        $this->json->method('serialize')->willReturn('{"request":{}}');
        $this->json->method('unserialize')->willReturn([
            'response' => [
                'response_status' => 'success',
                'checkout_url'    => 'https://pay.flitt.com/x',
                'payment_id'      => '12345',
            ],
        ]);
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(
            '{"response":{"response_status":"success","checkout_url":"https://pay.flitt.com/x","payment_id":"12345"}}'
        );

        $this->paymentRepository->expects(self::once())
            ->method('save')
            ->with(self::callback(static function ($payment): bool {
                // Must be a payment object whose additional_information carries
                // the flitt_order_id prefix that Redirect.php builds.
                $info = $payment->getAdditionalInformation('flitt_order_id');
                return is_string($info) && str_starts_with($info, 'duka_000000042_');
            }));

        $controller = new Redirect(
            jsonFactory: $this->jsonFactory,
            checkoutSession: $this->checkoutSession,
            config: $this->config,
            urlBuilder: $this->urlBuilder,
            logger: $this->logger,
            curlFactory: $this->curlFactory,
            json: $this->json,
            localeResolver: $this->localeResolver,
            paymentRepository: $this->paymentRepository,
            userFacingErrorMapper: $this->userFacingErrorMapper,
        );
        $controller->execute();
    }

    private function primeOrderAndConfig(): void
    {
        $additionalInformation = [];
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setAdditionalInformation', 'getAdditionalInformation'])
            ->getMock();
        $payment->method('setAdditionalInformation')
            ->willReturnCallback(function (string $key, $value) use (&$additionalInformation, $payment) {
                $additionalInformation[$key] = $value;
                return $payment;
            });
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(function (?string $key = null) use (&$additionalInformation) {
                if ($key === null) {
                    return $additionalInformation;
                }
                return $additionalInformation[$key] ?? null;
            });

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getEntityId', 'getState', 'getStoreId', 'getIncrementId',
                'getPayment', 'getGrandTotal', 'getOrderCurrencyCode', 'getCustomerEmail',
            ])
            ->getMock();
        $order->method('getEntityId')->willReturn(11);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getGrandTotal')->willReturn(10.00);
        $order->method('getOrderCurrencyCode')->willReturn('GEL');
        $order->method('getCustomerEmail')->willReturn('buyer@example.com');
        $order->method('getPayment')->willReturn($payment);

        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);

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
