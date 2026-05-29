<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Gateway\Http\Client;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Exception\FlittApiException;
use Shubo\TbcPayment\Gateway\Http\Client\FlittHttpClient;

/**
 * Transport-behaviour tests for the shared FlittHttpClient (SIMPLIFY-2 + IMPROVE-12).
 */
class FlittHttpClientTest extends TestCase
{
    private const STORE_ID = 1;
    private const API_URL = 'https://pay.flitt.com';

    private Config&MockObject $config;
    private CurlFactory&MockObject $curlFactory;
    private Json&MockObject $json;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->json = $this->createMock(Json::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('getApiUrl')->willReturn(self::API_URL);
        $this->config->method('isDebugEnabled')->willReturn(false);
        $this->config->method('getHttpConnectTimeout')->willReturn(5);
        $this->config->method('getHttpReadTimeout')->willReturn(30);
        $this->config->method('getHttpStatusRetries')->willReturn(1);
    }

    public function testSuccessfulResponseIsDecodedToArray(): void
    {
        $curl = $this->makeCurl(200, '{"order_status":"approved"}');
        $this->curlFactory->method('create')->willReturn($curl);
        $this->json->method('serialize')->willReturn('{"request":{"order_id":"x"}}');
        $this->json->method('unserialize')->willReturn(['order_status' => 'approved']);

        $result = $this->client()->post('/api/status/order_id', ['request' => ['order_id' => 'x']], self::STORE_ID);

        self::assertSame(['order_status' => 'approved'], $result);
    }

    public function testNonTwoxxStatusThrowsFlittApiException(): void
    {
        $curl = $this->makeCurl(502, 'Bad Gateway');
        $this->curlFactory->method('create')->willReturn($curl);
        $this->json->method('serialize')->willReturn('{}');

        $this->expectException(FlittApiException::class);

        $this->client()->post('/api/capture/order_id', ['request' => []], self::STORE_ID);
    }

    public function testNonArrayBodyThrowsFlittApiException(): void
    {
        $curl = $this->makeCurl(200, '"just-a-string"');
        $this->curlFactory->method('create')->willReturn($curl);
        $this->json->method('serialize')->willReturn('{}');
        $this->json->method('unserialize')->willReturn('just-a-string');

        $this->expectException(FlittApiException::class);

        $this->client()->post('/api/capture/order_id', ['request' => []], self::STORE_ID);
    }

    public function testConnectAndReadTimeoutsArePassedThroughBeforePost(): void
    {
        $callOrder = [];
        $optionsApplied = [];

        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{"ok":true}');
        $curl->method('setOptions')
            ->willReturnCallback(static function (array $opts) use (&$callOrder, &$optionsApplied): void {
                $callOrder[] = 'setOptions';
                $optionsApplied = $opts;
            });
        $curl->expects(self::once())
            ->method('post')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'post';
            });

        $this->curlFactory->method('create')->willReturn($curl);
        $this->json->method('serialize')->willReturn('{}');
        $this->json->method('unserialize')->willReturn(['ok' => true]);

        $this->client()->post('/api/capture/order_id', ['request' => []], self::STORE_ID);

        self::assertArrayHasKey(CURLOPT_CONNECTTIMEOUT, $optionsApplied);
        self::assertArrayHasKey(CURLOPT_TIMEOUT, $optionsApplied);
        self::assertSame(5, $optionsApplied[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(30, $optionsApplied[CURLOPT_TIMEOUT]);

        $optionsIndex = array_search('setOptions', $callOrder, true);
        $postIndex = array_search('post', $callOrder, true);
        self::assertNotFalse($optionsIndex);
        self::assertNotFalse($postIndex);
        self::assertLessThan($postIndex, $optionsIndex, 'timeouts must be applied before post');
    }

    public function testRawBodyModeBypassesJsonEncode(): void
    {
        $rawBody = '{"request":{"version":"2.0","data":"BASE64","signature":"sig"}}';

        $postedBody = null;
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{"response_status":"success"}');
        $curl->method('post')
            ->willReturnCallback(static function (string $url, string $body) use (&$postedBody): void {
                $postedBody = $body;
            });

        $this->curlFactory->method('create')->willReturn($curl);
        // serialize must NOT be invoked for a raw string body.
        $this->json->expects(self::never())->method('serialize');
        $this->json->method('unserialize')->willReturn(['response_status' => 'success']);

        $result = $this->client()->post('/api/settlement', $rawBody, self::STORE_ID);

        self::assertSame($rawBody, $postedBody, 'raw body must be sent verbatim');
        self::assertSame(['response_status' => 'success'], $result);
    }

    public function testRetryHappensOnlyWhenRetryableTrue(): void
    {
        // First attempt throws a transport error, second succeeds.
        $attempts = 0;
        $curl = $this->createMock(Curl::class);
        $curl->method('post')->willReturnCallback(static function () use (&$attempts): void {
            $attempts++;
            if ($attempts === 1) {
                throw new \RuntimeException('cURL error 28: Operation timed out');
            }
        });
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{"order_status":"approved"}');

        $this->curlFactory->method('create')->willReturn($curl);
        $this->json->method('serialize')->willReturn('{}');
        $this->json->method('unserialize')->willReturn(['order_status' => 'approved']);

        $result = $this->client()->post('/api/status/order_id', ['request' => []], self::STORE_ID, retryable: true);

        self::assertSame(['order_status' => 'approved'], $result);
        self::assertSame(2, $attempts, 'retryable status call should retry once');
    }

    public function testNoRetryForNonIdempotentCallEvenWithConfiguredRetries(): void
    {
        $attempts = 0;
        $curl = $this->createMock(Curl::class);
        $curl->method('post')->willReturnCallback(static function () use (&$attempts): void {
            $attempts++;
            throw new \RuntimeException('cURL error 28: Operation timed out');
        });
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{}');

        $this->curlFactory->method('create')->willReturn($curl);
        $this->json->method('serialize')->willReturn('{}');

        $thrown = false;
        try {
            // Default retryable=false (capture/void/settle/refund/token path).
            $this->client()->post('/api/capture/order_id', ['request' => []], self::STORE_ID);
        } catch (FlittApiException) {
            $thrown = true;
        }

        self::assertTrue($thrown, 'non-2xx-free transport failure must surface as FlittApiException');
        self::assertSame(1, $attempts, 'non-idempotent calls must NOT retry');
    }

    public function testNonTwoxxIsNotRetriedEvenWhenRetryable(): void
    {
        // A deterministic HTTP-status failure (FlittApiException) must not be retried.
        $attempts = 0;
        $curl = $this->createMock(Curl::class);
        $curl->method('post')->willReturnCallback(static function () use (&$attempts): void {
            $attempts++;
        });
        $curl->method('getStatus')->willReturn(500);
        $curl->method('getBody')->willReturn('error');

        $this->curlFactory->method('create')->willReturn($curl);
        $this->json->method('serialize')->willReturn('{}');

        $thrown = false;
        try {
            $this->client()->post('/api/status/order_id', ['request' => []], self::STORE_ID, retryable: true);
        } catch (FlittApiException) {
            $thrown = true;
        }

        self::assertTrue($thrown);
        self::assertSame(1, $attempts, 'a non-2xx status is deterministic and must NOT be retried');
    }

    private function makeCurl(int $status, string $body): Curl&MockObject
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn($body);

        return $curl;
    }

    private function client(): FlittHttpClient
    {
        return new FlittHttpClient(
            config: $this->config,
            curlFactory: $this->curlFactory,
            json: $this->json,
            logger: $this->logger,
        );
    }
}
