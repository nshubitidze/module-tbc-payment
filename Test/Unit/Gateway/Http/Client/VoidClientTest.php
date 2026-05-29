<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Gateway\Http\Client;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Http\Client\FlittHttpClient;
use Shubo\TbcPayment\Gateway\Http\Client\VoidClient;

/**
 * SIMPLIFY-5: VoidClient now OWNS the wire-payload build + Flitt signature
 * (mirroring StatusClient). These tests pin the signed reverse envelope shape
 * the admin VoidPayment controller no longer constructs inline, and confirm the
 * amount that goes on the wire is exactly the (authorized) minor-unit value the
 * caller hands in.
 */
class VoidClientTest extends TestCase
{
    private Config&MockObject $config;
    private FlittHttpClient&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;
    private VoidClient $client;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->httpClient = $this->createMock(FlittHttpClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('getMerchantId')->willReturn('1549901');
        $this->config->method('getPassword')->willReturn('test_secret');
        $this->config->method('isDebugEnabled')->willReturn(false);

        $this->client = new VoidClient($this->config, $this->httpClient, $this->logger);
    }

    public function testBuildsAndSignsTheReverseEnvelopeWithAuthorizedAmount(): void
    {
        // 800 tetri = the held authorization, NOT a grand-total derivation.
        $expectedSignature = Config::generateSignature(
            [
                'order_id' => 'duka_000042_1234',
                'merchant_id' => '1549901',
                'amount' => '800',
                'currency' => 'GEL',
            ],
            'test_secret',
        );

        $this->httpClient->expects(self::once())
            ->method('post')
            ->with(
                '/api/reverse/order_id',
                self::callback(static function (array $body) use ($expectedSignature): bool {
                    $req = $body['request'] ?? null;
                    return is_array($req)
                        && $req['order_id'] === 'duka_000042_1234'
                        && $req['merchant_id'] === '1549901'
                        && $req['amount'] === '800'
                        && $req['currency'] === 'GEL'
                        && $req['signature'] === $expectedSignature
                        && strlen((string) $req['signature']) === 40;
                }),
                1,
            )
            ->willReturn(['response' => ['reverse_status' => 'approved']]);

        $result = $this->client->reverse('duka_000042_1234', 800, 'GEL', 1);

        self::assertSame(['response' => ['reverse_status' => 'approved']], $result);
    }
}
