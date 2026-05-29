<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Gateway\Http\Client;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Http\Client\FlittHttpClient;
use Shubo\TbcPayment\Gateway\Http\Client\StatusClient;

/**
 * Batch 3 SIMPLIFY-4 §4: the Flitt `response` envelope unwrap now lives INSIDE
 * StatusClient::checkStatus, so the four callers (Confirm, ReturnAction,
 * CheckStatus, PendingOrderReconciler) no longer repeat `$body['response'] ?? $body`.
 *
 * These tests pin the client's new return contract: the unwrapped inner payload
 * on a well-formed reply, and a graceful fall-back to the whole body otherwise —
 * the exact shapes the callers (especially the reconciler's not-found detection)
 * relied on after their own unwrap.
 */
class StatusClientTest extends TestCase
{
    private Config&MockObject $config;
    private FlittHttpClient&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;
    private StatusClient $statusClient;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->httpClient = $this->createMock(FlittHttpClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('getMerchantId')->willReturn('1234567');
        $this->config->method('getPassword')->willReturn('secret');
        $this->config->method('isDebugEnabled')->willReturn(false);

        $this->statusClient = new StatusClient(
            $this->config,
            $this->httpClient,
            $this->logger,
        );
    }

    public function testUnwrapsResponseEnvelope(): void
    {
        $this->httpClient->method('post')->willReturn([
            'response' => [
                'order_status' => 'approved',
                'payment_id'   => 'pay-1',
                'amount'       => 1000,
            ],
        ]);

        $result = $this->statusClient->checkStatus('duka_000000042_1700', 1);

        self::assertSame(
            [
                'order_status' => 'approved',
                'payment_id'   => 'pay-1',
                'amount'       => 1000,
            ],
            $result,
            'checkStatus must return the inner `response` payload, not the envelope.'
        );
    }

    public function testFallsBackToWholeBodyWhenEnvelopeMissing(): void
    {
        // A reply without the `response` key (malformed) must surface verbatim,
        // preserving the previous caller-side `$body['response'] ?? $body` fall-back.
        $this->httpClient->method('post')->willReturn([
            'order_status' => 'declined',
            'error_code'   => 1001,
        ]);

        $result = $this->statusClient->checkStatus('duka_000000042_1700', 1);

        self::assertSame(
            [
                'order_status' => 'declined',
                'error_code'   => 1001,
            ],
            $result,
        );
    }

    public function testFallsBackToWholeBodyWhenResponseNotArray(): void
    {
        // Defensive: if `response` is present but scalar, return the whole body
        // rather than a non-array — callers expect an array<string, mixed>.
        $this->httpClient->method('post')->willReturn([
            'response'        => 'unexpected-scalar',
            'response_status' => 'failure',
        ]);

        $result = $this->statusClient->checkStatus('duka_000000042_1700', 1);

        self::assertSame(
            [
                'response'        => 'unexpected-scalar',
                'response_status' => 'failure',
            ],
            $result,
        );
    }

    public function testPreservesOrderNotFoundEnvelopeForReconciler(): void
    {
        // The reconciler's isOrderNotFoundResponse() reads response_status/error_code
        // off the UNWRAPPED payload. After the move, the unwrapped 1011 envelope must
        // still carry those keys at the top level.
        $this->httpClient->method('post')->willReturn([
            'response' => [
                'response_status' => 'failure',
                'error_code'      => 1011,
                'error_message'   => 'Order not found',
            ],
        ]);

        $result = $this->statusClient->checkStatus('duka_000000055_1700000000', 1);

        self::assertSame('failure', $result['response_status']);
        self::assertSame(1011, $result['error_code']);
        self::assertArrayNotHasKey('order_status', $result);
    }
}
