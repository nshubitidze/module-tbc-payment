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

    /**
     * IMPROVE-4: a FAILED settlement reply must NOT stamp the blocking
     * settlement_status key — it goes to settlement_last_status instead — so
     * the already-settled guard does not short-circuit and the admin Settle
     * button stays visible. The attempt counter still advances (BUG-7).
     */
    public function testFailedSettlementStaysRetryable(): void
    {
        $order = $this->makeOrder('duka_42_111');

        $this->settlementClient->method('settle')->willReturnCallback(
            function (array $data): array {
                $this->capturedOrderData[] = $data;
                return ['response' => ['response_status' => 'failure', 'error_message' => 'declined by acquirer']];
            }
        );

        $result = $this->buildService()->settle($order);

        self::assertFalse($result, 'A failed settlement returns false');
        // The blocking key is NEVER set on failure — the guard would otherwise
        // short-circuit forever and hide the admin Settle button.
        self::assertArrayNotHasKey('settlement_status', $this->paymentInfo);
        self::assertSame('failure', $this->paymentInfo['settlement_last_status']);
        // Attempt counter advanced so the next retry uses a distinct order_id.
        self::assertSame(1, $this->paymentInfo['settlement_attempt']);
    }

    /**
     * IMPROVE-4: after a failed attempt the service can be called again and it
     * does NOT short-circuit on the already-settled guard; it advances the
     * BUG-7 retry suffix. This is the "no longer blocks retry" guarantee.
     */
    public function testRetryAfterFailureIsNotShortCircuited(): void
    {
        // First call fails.
        $order = $this->makeOrder('duka_42_111');
        $this->settlementClient->method('settle')->willReturnCallback(
            function (array $data): array {
                $this->capturedOrderData[] = $data;
                $attempt = count($this->capturedOrderData);
                // Fail the first attempt, succeed the second.
                $status = $attempt === 1 ? 'failure' : 'success';
                return ['response' => ['response_status' => $status]];
            }
        );

        $service = $this->buildService();
        self::assertFalse($service->settle($order), 'First attempt fails');
        self::assertTrue($service->settle($order), 'Second attempt is not blocked and succeeds');

        self::assertCount(2, $this->capturedOrderData);
        self::assertSame('settlement_duka_42_111', $this->capturedOrderData[0]['order_id']);
        self::assertSame('settlement_duka_42_111_r2', $this->capturedOrderData[1]['order_id']);
        // Only the genuine success stamps the blocking key.
        self::assertSame('success', $this->paymentInfo['settlement_status']);
    }

    /**
     * IMPROVE-4: once genuinely settled, the guard DOES short-circuit — no
     * second Flitt call, no re-settlement.
     */
    public function testGenuineSuccessIsTerminalForGuard(): void
    {
        $this->paymentInfo['settlement_status'] = 'success';
        $order = $this->makeOrder('duka_42_111');

        $this->settlementClient->expects(self::never())->method('settle');

        self::assertFalse($this->buildService()->settle($order));
    }

    /**
     * IMPROVE-4: a persisted FAILURE status under the non-blocking key must NOT
     * be treated as already-settled — the order remains retryable.
     */
    public function testPersistedFailureStatusDoesNotShortCircuit(): void
    {
        // Simulate a prior failed attempt: last_status failure, attempt=1,
        // blocking key absent.
        $this->paymentInfo['settlement_last_status'] = 'failure';
        $this->paymentInfo['settlement_attempt'] = 1;

        $order = $this->makeOrder('duka_42_111');
        $this->settlementClient->expects(self::once())
            ->method('settle')
            ->willReturnCallback(function (array $data): array {
                $this->capturedOrderData[] = $data;
                return ['response' => ['response_status' => 'success']];
            });

        self::assertTrue($this->buildService()->settle($order));
        self::assertSame('settlement_duka_42_111_r2', $this->capturedOrderData[0]['order_id']);
    }

    /**
     * IMPROVE-4: isAlreadySettled() agrees with the guard — true only for a
     * genuine success value, false for empty or failure.
     */
    public function testIsAlreadySettledOnlyTrueForSuccess(): void
    {
        $service = $this->buildService();

        self::assertFalse($service->isAlreadySettled($this->paymentWithStatus(null)));
        self::assertFalse($service->isAlreadySettled($this->paymentWithStatus('')));
        self::assertFalse($service->isAlreadySettled($this->paymentWithStatus('failure')));
        self::assertFalse($service->isAlreadySettled($this->paymentWithStatus('declined')));
        self::assertTrue($service->isAlreadySettled($this->paymentWithStatus('success')));
        self::assertTrue($service->isAlreadySettled($this->paymentWithStatus('approved')));
    }

    private function paymentWithStatus(?string $status): Payment&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (?string $key = null): mixed => $key === 'settlement_status' ? $status : null
        );

        return $payment;
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

    /**
     * IMPROVE-10: percentage split must use integer/bcmath tetri with a
     * deterministic rounding remainder so Σreceivers reconciles EXACTLY to the
     * intended slice of the order — no ±1 float drift — and the percentage sum
     * must be validated to never exceed 100%.
     *
     * @param list<array{merchant_id: string, amount_type: string, amount: string}> $receivers
     * @param int $grandTotalTetri Order grand total in tetri
     * @param list<int>|null $expectedAmounts Expected per-receiver tetri (in order), or null if settlement is rejected
     * @param int $expectedSum Expected Σ of receiver amounts (the intended slice)
     *
     * @dataProvider percentageSplitProvider
     */
    public function testPercentageSplitIsIntegerExactAndValidated(
        array $receivers,
        int $grandTotalTetri,
        ?array $expectedAmounts,
        int $expectedSum,
    ): void {
        $captured = [];
        $client = $this->createMock(SettlementClient::class);
        $client->method('settle')->willReturnCallback(
            function (array $data) use (&$captured): array {
                $captured = $data['receiver'];
                return ['response' => ['response_status' => 'success']];
            }
        );

        $service = $this->buildServiceWithReceivers($receivers, $client);
        $order = $this->makeOrderWithTotal($grandTotalTetri);

        $result = $service->settle($order);

        if ($expectedAmounts === null) {
            // Rejected (e.g. percent sum > 100 or fixed > total): no Flitt call.
            self::assertFalse($result, 'Invalid split must be rejected (no settlement sent)');
            self::assertSame([], $captured, 'No receiver data should be sent');
            return;
        }

        self::assertTrue($result, 'Valid split must settle');

        $amounts = array_map(
            static fn (array $r): int => (int) $r['requisites']['amount'],
            $captured
        );
        self::assertSame($expectedAmounts, $amounts, 'Per-receiver tetri must match deterministic allocation');

        // The core invariant: integer-exact reconciliation, no float drift.
        self::assertSame($expectedSum, array_sum($amounts), 'Sum of receivers must reconcile exactly');
        self::assertLessThanOrEqual($grandTotalTetri, array_sum($amounts), 'Sum never exceeds the order total');
    }

    /**
     * @return array<string, array{
     *     0: list<array{merchant_id: string, amount_type: string, amount: string}>,
     *     1: int,
     *     2: list<int>|null,
     *     3: int
     * }>
     */
    public static function percentageSplitProvider(): array
    {
        return [
            // 1001 tetri (10.01 GEL) split 33.33 / 33.33 / 33.34.
            // floor(1001*3333/10000)=333, floor(1001*3333/10000)=333,
            // floor(1001*3334/10000)=333; intended=floor(1001*10000/10000)=1001;
            // remainder 1001-999=2 → last positive receiver: 333,333,335.
            '1001 tetri 33.33/33.33/33.34' => [
                [
                    ['merchant_id' => '101', 'amount_type' => 'percent', 'amount' => '33.33'],
                    ['merchant_id' => '102', 'amount_type' => 'percent', 'amount' => '33.33'],
                    ['merchant_id' => '103', 'amount_type' => 'percent', 'amount' => '33.34'],
                ],
                1001,
                [333, 333, 335],
                1001,
            ],
            // 99.99% of 1000 tetri = floor(1000*9999/10000)=999; intended=999;
            // remainder 0 → single receiver gets 999, 1 tetri stays with main.
            '99.99 percent of 1000' => [
                [
                    ['merchant_id' => '201', 'amount_type' => 'percent', 'amount' => '99.99'],
                ],
                1000,
                [999],
                999,
            ],
            // 0% → receiver resolves to zero amount and is dropped; with no other
            // receivers buildReceiverData is empty → settlement skipped.
            '0 percent yields no receivers' => [
                [
                    ['merchant_id' => '301', 'amount_type' => 'percent', 'amount' => '0'],
                ],
                5000,
                null,
                0,
            ],
            // Fixed == total: one fixed receiver takes everything, no remainder.
            'fixed equals total' => [
                [
                    ['merchant_id' => '401', 'amount_type' => 'fixed', 'amount' => '50.00'],
                ],
                5000,
                [5000],
                5000,
            ],
            // Percent sum > 100 (60 + 60) → rejected.
            'percent sum over 100 rejected' => [
                [
                    ['merchant_id' => '501', 'amount_type' => 'percent', 'amount' => '60'],
                    ['merchant_id' => '502', 'amount_type' => 'percent', 'amount' => '60'],
                ],
                5000,
                null,
                0,
            ],
            // 50/50 of 101 tetri: floor(101*5000/10000)=50, 50; intended=50+50... wait
            // intended=floor(101*10000/10000)=101; remainder 101-100=1 → 50, 51.
            '50/50 of 101 tetri deterministic remainder' => [
                [
                    ['merchant_id' => '601', 'amount_type' => 'percent', 'amount' => '50'],
                    ['merchant_id' => '602', 'amount_type' => 'percent', 'amount' => '50'],
                ],
                101,
                [50, 51],
                101,
            ],
            // Mixed: fixed 2.00 GEL + 100% of the rest. total 1001 tetri:
            // fixed=200; remaining=801; 100% → floor(801*10000/10000)=801.
            'mixed fixed plus full percent' => [
                [
                    ['merchant_id' => '701', 'amount_type' => 'fixed', 'amount' => '2.00'],
                    ['merchant_id' => '702', 'amount_type' => 'percent', 'amount' => '100'],
                ],
                1001,
                [200, 801],
                1001,
            ],
        ];
    }

    /**
     * T-6 defensive guard: split is enabled but NO receivers are configured
     * (empty split_receivers config). The service must skip cleanly — no Flitt
     * call, returns false — never send an empty settlement.
     */
    public function testEmptyReceiversConfiguredSkipsSettlement(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isSplitPaymentsEnabled')->willReturn(true);
        $config->method('isSplitAutoSettleEnabled')->willReturn(true);
        $config->method('getMerchantId')->willReturn('1549901');
        // No receivers configured at all.
        $config->method('getSplitReceivers')->willReturn('');

        $client = $this->createMock(SettlementClient::class);
        $client->expects(self::never())->method('settle');

        $json = $this->createMock(Json::class);
        $json->method('serialize')->willReturn('[]');
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturn('https://example.ge/tbc/callback');

        $service = new SettlementService(
            $config,
            $client,
            $this->createMock(EventManagerInterface::class),
            $json,
            $url,
            $this->createMock(LoggerInterface::class),
        );

        self::assertFalse($service->settle($this->makeOrderWithTotal(5000)));
    }

    /**
     * T-6 defensive guard: a fixed receiver amount GREATER than the order total
     * is rejected by buildReceiverData (would over-allocate). No Flitt call,
     * returns false.
     */
    public function testFixedAmountExceedingTotalIsRejected(): void
    {
        $captured = [];
        $client = $this->createMock(SettlementClient::class);
        $client->method('settle')->willReturnCallback(
            function (array $data) use (&$captured): array {
                $captured = $data['receiver'] ?? [];
                return ['response' => ['response_status' => 'success']];
            }
        );

        // Fixed 60.00 GEL (6000 tetri) against a 50.00 GEL (5000 tetri) order.
        $service = $this->buildServiceWithReceivers(
            [['merchant_id' => '801', 'amount_type' => 'fixed', 'amount' => '60.00']],
            $client,
        );

        self::assertFalse($service->settle($this->makeOrderWithTotal(5000)));
        self::assertSame([], $captured, 'fixed > total must not send any receiver data');
    }

    /**
     * T-6 defensive guard: all percentage receivers resolve to a zero share
     * (0% each) → allocatePercentReceivers drops them and buildReceiverData
     * yields an empty set → settlement skipped, returns false, no Flitt call.
     *
     * NOTE: a FIXED 0.00 receiver is intentionally NOT a zero-skip — Flitt
     * accepts a zero fixed leg — so the all-zero guard is exercised with the
     * percentage path, which is where the empty-set collapse actually happens.
     */
    public function testAllZeroPercentReceiversSkipSettlement(): void
    {
        $client = $this->createMock(SettlementClient::class);
        $client->expects(self::never())->method('settle');

        $service = $this->buildServiceWithReceivers(
            [
                ['merchant_id' => '901', 'amount_type' => 'percent', 'amount' => '0'],
                ['merchant_id' => '902', 'amount_type' => 'percent', 'amount' => '0'],
            ],
            $client,
        );

        self::assertFalse($service->settle($this->makeOrderWithTotal(5000)));
    }

    /**
     * Build a service whose admin receivers are the given config rows.
     *
     * @param list<array{merchant_id: string, amount_type: string, amount: string}> $receivers
     */
    private function buildServiceWithReceivers(
        array $receivers,
        SettlementClient&MockObject $client,
    ): SettlementService {
        $config = $this->createMock(Config::class);
        $config->method('isSplitPaymentsEnabled')->willReturn(true);
        $config->method('isSplitAutoSettleEnabled')->willReturn(true);
        $config->method('getMerchantId')->willReturn('1549901');
        $config->method('getSplitReceivers')->willReturn('configured');

        $json = $this->createMock(Json::class);
        $json->method('serialize')->willReturn('[]');
        // Re-key like dynamic rows (assoc by row id) so getAdminReceivers
        // re-indexes via array_values, matching production parsing.
        $keyed = [];
        foreach ($receivers as $i => $r) {
            $keyed['_' . $i] = $r;
        }
        $json->method('unserialize')->willReturn($keyed);

        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturn('https://example.ge/tbc/callback');

        return new SettlementService(
            $config,
            $client,
            $this->createMock(EventManagerInterface::class),
            $json,
            $url,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function makeOrderWithTotal(int $grandTotalTetri): Order&MockObject
    {
        $info = ['flitt_order_id' => 'duka_split_1'];

        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (?string $key = null): mixed => $key === null ? $info : ($info[$key] ?? null)
        );
        $payment->method('setAdditionalInformation')->willReturnCallback(
            static function (string $key, mixed $value) use (&$info, $payment): Payment {
                $info[$key] = $value;
                return $payment;
            }
        );

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn($grandTotalTetri / 100);
        $order->method('getOrderCurrencyCode')->willReturn('GEL');
        $order->method('getIncrementId')->willReturn('000000099');
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        return $order;
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
