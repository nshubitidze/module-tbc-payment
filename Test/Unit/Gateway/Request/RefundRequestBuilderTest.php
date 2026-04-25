<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Gateway\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;
use Shubo\TbcPayment\Gateway\Request\RefundRequestBuilder;

/**
 * Regression tests for BUG-3.
 *
 * Flitt v1.0 signature is sha1(password + '|' + pipe-joined sorted non-empty values).
 * If any value is a nested array (e.g. settlement receivers), PHP coerces it to the
 * literal string "Array" — Flitt then rejects the call. The fix is to keep the
 * reverse payload scalar-only.
 */
class RefundRequestBuilderTest extends TestCase
{
    private Config&MockObject $config;
    private SubjectReader&MockObject $subjectReader;
    private RefundRequestBuilder $builder;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->subjectReader = $this->createMock(SubjectReader::class);
        $this->builder = new RefundRequestBuilder(
            config: $this->config,
            subjectReader: $this->subjectReader,
        );
    }

    public function testBuildsScalarOnlyV1Payload(): void
    {
        $subject = $this->prepareSubject(grandTotal: 50.00, settledReceivers: null);

        $params = $this->builder->build($subject);

        self::assertSame('duka_000000042_1700000000', $params['order_id']);
        self::assertSame('1549901', $params['merchant_id']);
        self::assertSame('5000', $params['amount']);
        self::assertSame('GEL', $params['currency']);
        self::assertSame(1, $params['__store_id']);
        self::assertArrayNotHasKey('receiver', $params, 'Reverse payload must never inline receiver array');

        foreach (array_diff_key($params, ['__store_id' => true]) as $key => $value) {
            self::assertIsString($value, "Field {$key} must be scalar-string for v1.0 signature");
        }
    }

    public function testReceiverArrayIsExcludedEvenWhenSettledReceiversStored(): void
    {
        // Even with stored settlement_receivers JSON, the reverse payload
        // must NOT include them — they belong in a separate v2.0 settlement call.
        $receiversJson = json_encode([
            ['type' => 'merchant', 'requisites' => ['merchant_id' => 999, 'amount' => 1000]],
        ], JSON_THROW_ON_ERROR);

        $subject = $this->prepareSubject(grandTotal: 50.00, settledReceivers: $receiversJson);

        $params = $this->builder->build($subject);

        self::assertArrayNotHasKey('receiver', $params);
    }

    public function testSignatureIsValidV1Sha1(): void
    {
        $subject = $this->prepareSubject(grandTotal: 50.00, settledReceivers: null);

        $params = $this->builder->build($subject);

        $signed = $params;
        $providedSig = $signed['signature'];
        unset($signed['signature'], $signed['__store_id']);

        // Re-derive expected signature exactly as Flitt v1.0 spec dictates.
        $filtered = array_filter(
            $signed,
            static fn ($v): bool => $v !== '' && $v !== null,
        );
        ksort($filtered);
        $signString = 'test-password';
        foreach ($filtered as $value) {
            $signString .= '|' . $value;
        }
        $expected = strtolower(sha1($signString));

        self::assertSame($expected, $providedSig, 'Signature must match Flitt v1.0 spec');
    }

    /**
     * Session 3 Priority 1.1.6 Change C — missing flitt_order_id must NOT
     * fall back to the bare increment_id; that guaranteed a Flitt-side
     * rejection (1013/1002) for orders placed before the redirect-flow
     * persistence fix (commit 4b8d444). Throw an actionable
     * LocalizedException instead so the admin can switch to Offline refund.
     */
    public function testThrowsWhenFlittOrderIdMissing(): void
    {
        $subject = $this->prepareSubject(
            grandTotal: 25.00,
            settledReceivers: null,
            storedFlittOrderId: null,
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/missing the Flitt reference/');

        $this->builder->build($subject);
    }

    public function testThrowsWhenFlittOrderIdIsEmptyString(): void
    {
        $subject = $this->prepareSubject(
            grandTotal: 25.00,
            settledReceivers: null,
            storedFlittOrderId: '',
        );

        $this->expectException(LocalizedException::class);

        $this->builder->build($subject);
    }

    public function testAmountConvertedToMinorUnits(): void
    {
        $subject = $this->prepareSubject(grandTotal: 12.34, settledReceivers: null);

        $params = $this->builder->build($subject);

        self::assertSame('1234', $params['amount']);
    }

    /**
     * Build a stub subject that returns a fully-mocked PaymentDataObject and amount.
     */
    private function prepareSubject(
        float $grandTotal,
        ?string $settledReceivers,
        ?string $storedFlittOrderId = 'duka_000000042_1700000000',
    ): array {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAdditionalInformation'])
            ->getMock();
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(static function (string $key) use ($storedFlittOrderId, $settledReceivers) {
                return match ($key) {
                    'flitt_order_id' => $storedFlittOrderId,
                    'settlement_receivers' => $settledReceivers,
                    default => null,
                };
            });

        // The PaymentDataObject's order is an OrderAdapterInterface, not Sales\Model\Order.
        $order = $this->createMock(OrderAdapterInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCurrencyCode')->willReturn('GEL');
        $order->method('getOrderIncrementId')->willReturn('000000042');

        $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDataObject->method('getPayment')->willReturn($payment);
        $paymentDataObject->method('getOrder')->willReturn($order);

        $this->config->method('getMerchantId')->willReturn('1549901');
        $this->config->method('getPassword')->willReturn('test-password');

        $subject = ['payment' => $paymentDataObject, 'amount' => $grandTotal];

        $this->subjectReader->method('readPayment')->willReturn($paymentDataObject);
        $this->subjectReader->method('readAmount')->willReturn($grandTotal);

        return $subject;
    }
}
