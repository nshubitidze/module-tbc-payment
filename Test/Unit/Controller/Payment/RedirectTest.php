<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Shubo\TbcPayment\Service\FlittLanguageResolver;
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
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;
use Shubo\TbcPayment\Gateway\Http\Client\FlittHttpClient;

/**
 * Redirect controller tests.
 *
 * Transport (curl/timeouts) now lives in FlittHttpClient and is covered by
 * FlittHttpClientTest; these tests assert the controller's delegation, endpoint
 * selection, persistence and double-click idempotency behaviour.
 */
class RedirectTest extends TestCase
{
    private JsonFactory&MockObject $jsonFactory;
    private CheckoutSession&MockObject $checkoutSession;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private Config&MockObject $config;
    private UrlInterface&MockObject $urlBuilder;
    private LoggerInterface&MockObject $logger;
    private FlittHttpClient&MockObject $httpClient;
    private FlittLanguageResolver&MockObject $languageResolver;
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
        $this->httpClient = $this->createMock(FlittHttpClient::class);
        $this->languageResolver = $this->createMock(FlittLanguageResolver::class);
        $this->jsonResult = $this->createMock(JsonResult::class);
        $this->paymentRepository = $this->createMock(OrderPaymentRepositoryInterface::class);
        $this->userFacingErrorMapper = $this->createMock(UserFacingErrorMapper::class);

        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->method('setData')->willReturnSelf();
    }

    /**
     * Regression for the bug where Redirect was posting to /api/checkout/token (the
     * embed-SDK endpoint that returns only `{token}`) instead of /api/checkout/url
     * (the redirect endpoint that returns `{checkout_url, payment_id}`). The
     * controller must delegate to the URL endpoint, never the token endpoint, and
     * must NOT request a retry (URL minting is non-idempotent).
     */
    public function testPostsToCheckoutUrlEndpointNotTokenEndpoint(): void
    {
        $this->primeOrderAndConfig();

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
                    return ['response' => [
                        'response_status' => 'success',
                        'checkout_url'    => 'https://pay.flitt.com/x',
                        'payment_id'      => '12345',
                    ]];
                }
            );

        $controller = $this->makeController();
        $controller->execute();

        self::assertSame('/api/checkout/url', $postedEndpoint);
        self::assertFalse($postedRetryable, 'URL minting must NOT be retryable');
    }

    /**
     * Regression for the bug where `flitt_order_id` (and `checkout_type`) were
     * set on the payment via `setAdditionalInformation()` but then only
     * `orderRepository->save($order)` was called. Fix: persist the payment
     * explicitly via OrderPaymentRepositoryInterface.
     */
    public function testPersistsFlittOrderIdViaPaymentRepository(): void
    {
        $this->primeOrderAndConfig();
        $this->httpClient->method('post')->willReturn([
            'response' => [
                'response_status' => 'success',
                'checkout_url'    => 'https://pay.flitt.com/x',
                'payment_id'      => '12345',
            ],
        ]);

        $this->paymentRepository->expects(self::once())
            ->method('save')
            ->with(self::callback(static function ($payment): bool {
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
     * calling Flitt again.
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
        $this->httpClient->expects(self::never())->method('post');
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
        $staleCreatedAt = (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s');

        $this->primeOrderAndConfig(
            preSeededAdditionalInfo: [
                'flitt_order_id' => 'duka_000000042_STALE',
                'checkout_url'   => 'https://pay.flitt.com/stale',
                'checkout_type'  => 'redirect',
            ],
            createdAt: $staleCreatedAt,
        );

        // Stale path MUST call Flitt once with a fresh flitt_order_id.
        $this->httpClient->expects(self::once())
            ->method('post')
            ->willReturn([
                'response' => [
                    'response_status' => 'success',
                    'checkout_url'    => 'https://pay.flitt.com/fresh',
                    'payment_id'      => '999',
                ],
            ]);

        $this->paymentRepository->expects(self::once())
            ->method('save')
            ->with(self::callback(static function ($payment): bool {
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
     * Edge-cases-matrix §4 — when the Flitt endpoint is unreachable the
     * FlittHttpClient throws FlittApiException; the controller must attach a
     * visible history comment on the Magento order so admin can correlate the
     * stuck order to the outage, then save the order via orderRepository.
     */
    public function testAddsHistoryCommentOnFlittTimeout(): void
    {
        [$order] = $this->primeOrderAndConfig();

        $this->httpClient->method('post')
            ->willThrowException(new FlittApiException(__('Unable to reach the TBC payment gateway.')));

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
     * Primes the shared mocks for a "fresh" order and returns the order + payment.
     *
     * @param array<string, mixed> $preSeededAdditionalInfo seed additional_information
     * @param string|null $createdAt ISO datetime for $order->getCreatedAt(); null = now
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
        $this->languageResolver->method('resolve')->willReturn('en');

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
            httpClient: $this->httpClient,
            languageResolver: $this->languageResolver,
            paymentRepository: $this->paymentRepository,
            userFacingErrorMapper: $this->userFacingErrorMapper,
            orderRepository: $this->orderRepository,
        );
    }
}
