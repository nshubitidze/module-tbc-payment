<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Gateway\Request;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;
use Shubo\TbcPayment\Gateway\Request\SplitDataBuilder;
use Shubo\TbcPayment\Model\SplitPaymentData;

/**
 * T-7 (ADJUSTED): producer-contract for {@see SplitDataBuilder::build()}.
 *
 * IMPROVE-1 carve-out: SplitDataBuilder + SplitPaymentData are KEPT but
 * currently UNWIRED (no di.xml entry feeds build() into a live gateway
 * command). This test documents the producer contract so a future rewire is
 * safe:
 *   - split disabled            → [] (no split keys ever leak into a request)
 *   - split enabled, no receivers (event yields none) → []
 *   - receivers present         → ['receivers' => [...]] with each receiver
 *                                 carrying merchant_id / amount / currency /
 *                                 description
 *   - amount is INTEGER tetri   → 10.50 GEL is represented as 1050 (int),
 *                                 never the float 10.50, per CLAUDE.md #6
 *
 * The original T-7 also mentioned "callback/response URLs present"; that was a
 * concern of the DELETED InitializeRequestBuilder (Batch 1 carve-out) and is
 * intentionally NOT asserted here — SplitDataBuilder only emits the receiver
 * payload. See Test/README.md.
 */
class SplitDataBuilderTest extends TestCase
{
    private Config&MockObject $config;
    private EventManagerInterface&MockObject $eventManager;
    private SplitDataBuilder $builder;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->eventManager = $this->createMock(EventManagerInterface::class);
        // SubjectReader is a thin un-packer with no collaborators — use the real
        // one so the [payment => ...] subject contract is exercised end-to-end.
        $this->builder = new SplitDataBuilder(
            $this->config,
            new SubjectReader(),
            $this->eventManager,
        );
    }

    /**
     * Split disabled → an empty array. No event is dispatched and no split
     * key can ever be merged into the outgoing request.
     */
    public function testSplitDisabledReturnsEmptyArray(): void
    {
        $this->config->method('isSplitPaymentsEnabled')->with(7)->willReturn(false);
        $this->eventManager->expects(self::never())->method('dispatch');

        self::assertSame([], $this->builder->build($this->makeSubject(storeId: 7)));
    }

    /**
     * Split enabled but the collector event yields no receivers → still an
     * empty array (never an empty `['receivers' => []]` payload).
     */
    public function testSplitEnabledWithNoReceiversReturnsEmptyArray(): void
    {
        $this->config->method('isSplitPaymentsEnabled')->willReturn(true);
        // Event fires but leaves the transport receivers empty.
        $this->eventManager->expects(self::once())
            ->method('dispatch')
            ->with('shubo_tbc_payment_split_data', self::isType('array'));

        self::assertSame([], $this->builder->build($this->makeSubject()));
    }

    /**
     * Receivers present → a `['receivers' => [...]]` payload, one entry per
     * receiver, each carrying the four contract keys.
     */
    public function testReceiversAreMappedIntoPayload(): void
    {
        $this->config->method('isSplitPaymentsEnabled')->willReturn(true);

        $r1 = (new SplitPaymentData())
            ->setMerchantId('1549901')
            ->setAmount(1050)
            ->setCurrency('GEL')
            ->setDescription('Vendor A');
        $r2 = (new SplitPaymentData())
            ->setMerchantId('1549902')
            ->setAmount(450)
            ->setCurrency('GEL')
            ->setDescription('Platform fee');

        $this->eventManager->method('dispatch')->willReturnCallback(
            static function (string $event, array $args) use ($r1, $r2): void {
                /** @var DataObject $transport */
                $transport = $args['transport'];
                $transport->setData('receivers', [$r1, $r2]);
            }
        );

        $result = $this->builder->build($this->makeSubject());

        self::assertArrayHasKey('receivers', $result);
        self::assertCount(2, $result['receivers']);
        self::assertSame(
            [
                'merchant_id' => '1549901',
                'amount' => 1050,
                'currency' => 'GEL',
                'description' => 'Vendor A',
            ],
            $result['receivers'][0],
        );
        self::assertSame(
            [
                'merchant_id' => '1549902',
                'amount' => 450,
                'currency' => 'GEL',
                'description' => 'Platform fee',
            ],
            $result['receivers'][1],
        );
    }

    /**
     * CLAUDE.md #6 money rule: the receiver amount is integer tetri, never a
     * float. 10.50 GEL must surface as the int 1050 with a strict int type —
     * `assertSame` would fail on a float 1050.0.
     */
    public function testReceiverAmountIsIntegerTetriNeverFloat(): void
    {
        $this->config->method('isSplitPaymentsEnabled')->willReturn(true);

        $receiver = (new SplitPaymentData())
            ->setMerchantId('1549901')
            ->setAmount(1050) // 10.50 GEL → 1050 tetri
            ->setCurrency('GEL')
            ->setDescription('');

        $this->eventManager->method('dispatch')->willReturnCallback(
            static function (string $event, array $args) use ($receiver): void {
                $args['transport']->setData('receivers', [$receiver]);
            }
        );

        $amount = $this->builder->build($this->makeSubject())['receivers'][0]['amount'];

        self::assertIsInt($amount, 'Split amount must be integer tetri, never a float');
        self::assertSame(1050, $amount);
    }

    /**
     * Build the gateway build-subject the way the payment framework supplies it:
     * a payment data object whose order exposes the store id.
     *
     * @return array{payment: PaymentDataObjectInterface}
     */
    private function makeSubject(int $storeId = 1): array
    {
        $orderAdapter = $this->createMock(OrderAdapterInterface::class);
        $orderAdapter->method('getStoreId')->willReturn($storeId);

        $paymentDO = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDO->method('getOrder')->willReturn($orderAdapter);

        return ['payment' => $paymentDO];
    }
}
