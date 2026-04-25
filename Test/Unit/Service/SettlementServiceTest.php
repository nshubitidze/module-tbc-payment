<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Service;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Http\Client\SettlementClient;
use Shubo\TbcPayment\Service\SettlementService;

/**
 * BUG-7 regression: every call to SettlementService::settle() must send
 * Flitt a distinct settlement order_id. When the prior attempt timed out
 * on our side but actually reached Flitt, a retry with the same order_id
 * returns error 1013/2004 ("duplicate order_id") and the vendor payout
 * stays stuck forever.
 *
 * The attempt counter is persisted on payment additional_information so
 * it survives process restarts, cron runs, and concurrent admin retries.
 * Suffix format:
 *   attempt 1: settlement_{flittOrderId}
 *   attempt N: settlement_{flittOrderId}_r{N}
 */
class SettlementServiceTest extends TestCase
{
    private Config&MockObject $config;
    private SettlementClient&MockObject $settlementClient;
    private EventManagerInterface&MockObject $eventManager;
    private Json&MockObject $json;
    private UrlInterface&MockObject $urlBuilder;
    private LoggerInterface&MockObject $logger;

    /** @var array<string, mixed> */
    private array $paymentInfo = [];
    /** @var array<int, array<string, mixed>> */
    private array $capturedOrderData = [];

    protected function setUp(): void
    {
        $this->config            = $this->createMock(Config::class);
        $this->settlementClient  = $this->createMock(SettlementClient::class);
        $this->eventManager      = $this->createMock(EventManagerInterface::class);
        $this->json              = $this->createMock(Json::class);
        $this->urlBuilder        = $this->createMock(UrlInterface::class);
        $this->logger            = $this->createMock(LoggerInterface::class);

        $this->json->method('serialize')->willReturn('[]');
        $this->urlBuilder->method('getUrl')->willReturn('https://example.ge/tbc/callback');

        $this->config->method('isSplitPaymentsEnabled')->willReturn(true);
        $this->config->method('isSplitAutoSettleEnabled')->willReturn(true);
        $this->config->method('getMerchantId')->willReturn('1549901');
        $this->config->method('getSplitReceivers')->willReturn(
            // Single fixed-amount receiver so buildReceiverData yields >0
            '{"_1":{"merchant_id":"1549901","amount_type":"fixed",'
            . '"amount":"1.00","description":"Test"}}'
        );

        $this->json->method('unserialize')->willReturn([
            '_1' => [
                'merchant_id' => '1549901',
                'amount_type' => 'fixed',
                'amount'      => '1.00',
                'description' => 'Test',
            ],
        ]);
    }

    public function testFirstAttemptUsesPlainSettlementOrderId(): void
    {
        $this->primeClientResponse('success');
        $order = $this->makeOrder('duka_42_111');

        $this->settlementClient->expects(self::once())
            ->method('settle')
            ->willReturnCallback(function (array $data): array {
                $this->capturedOrderData[] = $data;
                return ['response' => ['response_status' => 'success']];
            });

        $this->buildService()->settle($order);

        self::assertCount(1, $this->capturedOrderData);
        self::assertSame('settlement_duka_42_111', $this->capturedOrderData[0]['order_id']);
        self::assertSame(1, $this->paymentInfo['settlement_attempt']);
    }

    public function testSecondAttemptAppendsRetrySuffix(): void
    {
        $this->primeClientResponse('success');
        // Pre-seed: first attempt already persisted, but settlement_status
        // was never stamped (e.g., HTTP timeout). Calling settle() again
        // must NOT reuse the same order_id.
        $this->paymentInfo['settlement_attempt'] = 1;

        $order = $this->makeOrder('duka_42_111');

        $this->settlementClient->expects(self::once())
            ->method('settle')
            ->willReturnCallback(function (array $data): array {
                $this->capturedOrderData[] = $data;
                return ['response' => ['response_status' => 'success']];
            });

        $this->buildService()->settle($order);

        self::assertCount(1, $this->capturedOrderData);
        self::assertSame(
            'settlement_duka_42_111_r2',
            $this->capturedOrderData[0]['order_id'],
        );
        self::assertSame(2, $this->paymentInfo['settlement_attempt']);
    }

    public function testThirdAttemptIncrementsSuffix(): void
    {
        $this->primeClientResponse('success');
        $this->paymentInfo['settlement_attempt'] = 2;

        $order = $this->makeOrder('duka_42_111');
        $this->settlementClient->method('settle')->willReturnCallback(
            function (array $data): array {
                $this->capturedOrderData[] = $data;
                return ['response' => ['response_status' => 'success']];
            }
        );

        $this->buildService()->settle($order);

        self::assertSame('settlement_duka_42_111_r3', $this->capturedOrderData[0]['order_id']);
        self::assertSame(3, $this->paymentInfo['settlement_attempt']);
    }

    public function testOperationIdAlwaysReferencesOriginalFlittOrderId(): void
    {
        // Flitt needs operation_id to match the ORIGINAL payment id so it
        // knows which captured payment the settlement applies to. The
        // attempt suffix only decorates order_id, never operation_id.
        $this->primeClientResponse('success');
        $this->paymentInfo['settlement_attempt'] = 4;

        $order = $this->makeOrder('duka_42_111');
        $this->settlementClient->method('settle')->willReturnCallback(
            function (array $data): array {
                $this->capturedOrderData[] = $data;
                return ['response' => ['response_status' => 'success']];
            }
        );

        $this->buildService()->settle($order);

        self::assertSame('duka_42_111', $this->capturedOrderData[0]['operation_id']);
        self::assertSame('settlement_duka_42_111_r5', $this->capturedOrderData[0]['order_id']);
    }

    private function buildService(): SettlementService
    {
        return new SettlementService(
            $this->config,
            $this->settlementClient,
            $this->eventManager,
            $this->json,
            $this->urlBuilder,
            $this->logger,
        );
    }

    private function primeClientResponse(string $status): void
    {
        $this->settlementClient->method('settle')->willReturn([
            'response' => ['response_status' => $status],
        ]);
    }

    private function makeOrder(string $flittOrderId): Order&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            function (?string $key = null): mixed {
                if ($key === null) {
                    return $this->paymentInfo;
                }
                return $this->paymentInfo[$key] ?? null;
            }
        );
        $payment->method('setAdditionalInformation')->willReturnCallback(
            function (string $key, mixed $value) use ($payment): Payment {
                $this->paymentInfo[$key] = $value;
                return $payment;
            }
        );
        // Pre-seed flitt_order_id so getAdditionalInformation('flitt_order_id')
        // returns the original payment id.
        $this->paymentInfo['flitt_order_id'] = $flittOrderId;

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(10.0);
        $order->method('getOrderCurrencyCode')->willReturn('GEL');
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        return $order;
    }
}
