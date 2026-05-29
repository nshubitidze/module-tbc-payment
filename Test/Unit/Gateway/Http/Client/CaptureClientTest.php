<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Gateway\Http\Client;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Http\Client\CaptureClient;
use Shubo\TbcPayment\Gateway\Http\Client\FlittHttpClient;

/**
 * SIMPLIFY-5: CaptureClient now OWNS the wire-payload build + Flitt signature
 * (mirroring StatusClient). These tests pin the signed envelope shape the admin
 * Capture controller no longer constructs inline. Capture posts with the
 * default retryable=false (it is non-idempotent), so the matcher pins the
 * three explicit args the client passes.
 */
class CaptureClientTest extends TestCase
{
    private Config&MockObject $config;
    private FlittHttpClient&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;
    private CaptureClient $client;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->httpClient = $this->createMock(FlittHttpClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('getMerchantId')->willReturn('1549901');
        $this->config->method('getPassword')->willReturn('test_secret');
        $this->config->method('isDebugEnabled')->willReturn(false);

        $this->client = new CaptureClient($this->config, $this->httpClient, $this->logger);
    }

    public function testBuildsAndSignsTheCaptureEnvelopeInternally(): void
    {
        $expectedSignature = Config::generateSignature(
            [
                'order_id' => 'duka_000042_1234',
                'merchant_id' => '1549901',
                'amount' => '1050',
                'currency' => 'GEL',
            ],
            'test_secret',
        );

        $this->httpClient->expects(self::once())
            ->method('post')
            ->with(
                '/api/capture/order_id',
                self::callback(static function (array $body) use ($expectedSignature): bool {
                    $req = $body['request'] ?? null;
                    return is_array($req)
                        && $req['order_id'] === 'duka_000042_1234'
                        && $req['merchant_id'] === '1549901'
                        && $req['amount'] === '1050'
                        && $req['currency'] === 'GEL'
                        && $req['signature'] === $expectedSignature
                        && strlen((string) $req['signature']) === 40;
                }),
                1,
            )
            ->willReturn(['response' => ['capture_status' => 'captured']]);

        $result = $this->client->capture('duka_000042_1234', 1050, 'GEL', 1);

        self::assertSame(['response' => ['capture_status' => 'captured']], $result);
    }
}
