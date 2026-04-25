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

        $controller = $this->makeController();
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

        $controller = $this->makeController();
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

        $controller = $this->makeController();
        $controller->execute();
    }

    /**
     * Edge-cases-matrix §5 — double-click Place Order idempotency. If the
     * first invocation already persisted flitt_order_id + checkout_url and
     * the order is fresh, a second POST MUST return the cached URL without
     * calling Flitt again (no curl->post, no curl->setOptions, no fresh
     * flitt_order_id overwrite).
     */
    public function testReturnsCachedUrlOnSecondClickIdempotency(): void
    {
        $cachedFlittOrderId = 'duka_000000042_1700000000';
        $cachedCheckoutUrl = 'https://pay.flitt.com/merchants/abc/default/index.html?token=cached';

        $this->primeOrderAndConfig(
            preSeededAdditionalInfo: [
                'flitt_order_id' => $cachedFlittOrderId,
                'checkout_url'   => $cachedCheckoutUrl,
                'checkout_type'  => 'redirect',
            ],
        );

        // Second click MUST NOT touch Flitt or the payment repository.
        $this->curl->expects(self::never())->method('post');
        $this->curl->expects(self::never())->method('setOptions');
        $this->paymentRepository->expects(self::never())->method('save');

        $captured = null;
        $this->jsonResult->expects(self::atLeastOnce())
            ->method('setData')
            ->willReturnCallback(function (array $data) use (&$captured): JsonResult {
                $captured = $data;
                return $this->jsonResult;
            });

        $controller = $this->makeController();
        $controller->execute();

        self::assertIsArray($captured);
        self::assertTrue($captured['success']);
        self::assertSame($cachedCheckoutUrl, $captured['checkout_url']);
    }

    /**
     * Edge-cases-matrix §5 — when the order's created_at is past the
     * configured payment_lifetime, the Flitt session is assumed expired
     * and we regenerate rather than returning a stale cached URL.
     */
    public function testRegeneratesUrlIfCachePastLifetime(): void
    {
        // created_at 2 hours ago, lifetime is 1 hour (config default) →
        // cache is stale, controller must call Flitt again.
        $staleCreatedAt = (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s');

        $this->primeOrderAndConfig(
            preSeededAdditionalInfo: [
                'flitt_order_id' => 'duka_000000042_STALE',
                'checkout_url'   => 'https://pay.flitt.com/stale',
                'checkout_type'  => 'redirect',
            ],
            createdAt: $staleCreatedAt,
        );

        $this->json->method('serialize')->willReturn('{"request":{}}');
        $this->json->method('unserialize')->willReturn([
            'response' => [
                'response_status' => 'success',
                'checkout_url'    => 'https://pay.flitt.com/fresh',
                'payment_id'      => '999',
            ],
        ]);
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(
            '{"response":{"response_status":"success","checkout_url":"https://pay.flitt.com/fresh","payment_id":"999"}}'
        );

        // Stale path MUST call Flitt once with a fresh flitt_order_id.
        $this->curl->expects(self::once())->method('post');
        $this->paymentRepository->expects(self::once())
            ->method('save')
            ->with(self::callback(static function ($payment): bool {
                // The new flitt_order_id must differ from the stale one.
                $info = (string) $payment->getAdditionalInformation('flitt_order_id');
                $checkoutUrl = (string) $payment->getAdditionalInformation('checkout_url');
                return str_starts_with($info, 'duka_000000042_')
                    && $info !== 'duka_000000042_STALE'
                    && $checkoutUrl === 'https://pay.flitt.com/fresh';
            }));

        $captured = null;
        $this->jsonResult->expects(self::atLeastOnce())
            ->method('setData')
            ->willReturnCallback(function (array $data) use (&$captured): JsonResult {
                $captured = $data;
                return $this->jsonResult;
            });

        $controller = $this->makeController();
        $controller->execute();

        self::assertIsArray($captured);
        self::assertTrue($captured['success']);
        self::assertSame('https://pay.flitt.com/fresh', $captured['checkout_url']);
    }

    /**
     * Edge-cases-matrix §4 — when the Flitt token endpoint is unreachable
     * (curl throws) the controller must attach a visible history comment
     * on the Magento order so admin can correlate the stuck order to the
     * outage, then save the order via orderRepository.
     */
    public function testAddsHistoryCommentOnFlittTimeout(): void
    {
        [$order] = $this->primeOrderAndConfig();
        $this->json->method('serialize')->willReturn('{"request":{}}');

        // Simulate a curl transport failure (e.g. timeout) — the Magento
        // Curl wrapper throws a generic \Exception in that situation.
        $this->curl->method('post')
            ->willThrowException(new \RuntimeException('cURL error 28: Operation timed out'));

        $order->expects(self::once())
            ->method('addCommentToStatusHistory')
            ->with(self::callback(static function ($comment): bool {
                $str = (string) $comment;
                return str_contains($str, 'Flitt token endpoint unreachable')
                    && str_contains($str, 'reconciler will retry');
            }));

        $this->orderRepository->expects(self::once())
            ->method('save')
            ->with($order);

        $captured = null;
        $this->jsonResult->expects(self::atLeastOnce())
            ->method('setData')
            ->willReturnCallback(function (array $data) use (&$captured): JsonResult {
                $captured = $data;
                return $this->jsonResult;
            });

        $controller = $this->makeController();
        $controller->execute();

        self::assertIsArray($captured);
        self::assertFalse($captured['success']);
    }

    /**
     * Primes the shared mocks (checkoutSession, config, urlBuilder) for a
     * "fresh" order with an empty payment additional_information map and
     * returns the payment + order so tests can inspect/seed them.
     *
     * @param array<string, mixed> $preSeededAdditionalInfo seed additional_information
     *        before the controller runs (e.g. for idempotency short-circuit tests).
     * @param string|null $createdAt ISO datetime for $order->getCreatedAt(); null
     *        means "now" (controller will treat the cache as fresh).
     * @return array{0: Order&MockObject, 1: Payment&MockObject}
     */
    private function primeOrderAndConfig(
        array $preSeededAdditionalInfo = [],
        ?string $createdAt = null,
    ): array {
        $additionalInformation = $preSeededAdditionalInfo;
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
                'getCreatedAt', 'addCommentToStatusHistory',
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
        $order->method('getCreatedAt')->willReturn(
            $createdAt ?? (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s')
        );

        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);

        $this->config->method('getMerchantId')->willReturn('1549901');
        $this->config->method('getPassword')->willReturn('test');
        $this->config->method('getApiUrl')->willReturn('https://pay.flitt.com');
        $this->config->method('getPaymentLifetime')->willReturn(3600);
        $this->config->method('isPreauth')->willReturn(false);
        $this->config->method('isDebugEnabled')->willReturn(false);

        $this->urlBuilder->method('getUrl')->willReturn('https://duka.ge/cb');
        $this->localeResolver->method('getLocale')->willReturn('en_US');

        return [$order, $payment];
    }

    private function makeController(): Redirect
    {
        return new Redirect(
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
            orderRepository: $this->orderRepository,
        );
    }
}
