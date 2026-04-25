<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Gateway\Response;

use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Shubo\TbcPayment\Gateway\Response\PaymentInfoKeys;

/**
 * BUG-15 regression: the 5 Flitt confirmation paths (Callback, Confirm,
 * ReturnAction, CheckStatus, PendingOrderReconciler) used to each maintain
 * their own inline list of payment_info keys. That drift meant fields like
 * fee/sender_email/response_code were present on some orders but missing
 * on others depending on which path finalised the payment.
 *
 * This test locks:
 *   - the shared key set is the union of historical lists,
 *   - only non-empty values are copied (no empty-string stamping),
 *   - keys absent from the payload are not written (no null stamping).
 */
class PaymentInfoKeysTest extends TestCase
{
    public function testConstantIsSupersetOfAllHistoricalLists(): void
    {
        // Union of the 5 lists observed before the fix. If any of these
        // fields is ever dropped from PaymentInfoKeys::KEYS, a real Flitt
        // payload will silently lose data.
        $expectedSuperset = [
            'payment_id', 'order_status', 'masked_card', 'rrn',
            'approval_code', 'tran_type', 'sender_email',
            'card_type', 'card_bin', 'eci', 'fee',
            'response_code', 'response_description',
            'actual_amount', 'actual_currency',
        ];

        sort($expectedSuperset);
        $actual = PaymentInfoKeys::KEYS;
        sort($actual);

        self::assertSame($expectedSuperset, $actual);
    }

    public function testApplyCopiesAllPopulatedFields(): void
    {
        $payment = $this->createMock(Payment::class);
        $captured = [];
        $payment->method('setAdditionalInformation')
            ->willReturnCallback(static function (string $k, mixed $v) use (&$captured, $payment) {
                $captured[$k] = $v;
                return $payment;
            });

        $response = [
            'payment_id'     => 'pay-123',
            'order_status'   => 'approved',
            'masked_card'    => '444433******1111',
            'rrn'            => 'RRN001',
            'approval_code'  => 'AP001',
            'tran_type'      => 'purchase',
            'sender_email'   => 'buyer@example.ge',
            'card_type'      => 'VISA',
            'card_bin'       => '444433',
            'eci'            => '05',
            'fee'            => '0.30',
            // response_code '0' would be dropped by !empty(); Flitt actually
            // sends a non-zero numeric string for declines, so test with that.
            'response_code'  => '1014',
            'response_description' => 'OK',
            'actual_amount'  => '10000',
            'actual_currency' => 'GEL',
            // Not on the keys list:
            'order_id'       => 'duka_000000042_1234',
        ];

        PaymentInfoKeys::apply($payment, $response);

        foreach (PaymentInfoKeys::KEYS as $key) {
            self::assertArrayHasKey($key, $captured, "Missing key: {$key}");
            self::assertSame($response[$key], $captured[$key]);
        }
        // Non-listed fields must NOT be copied via apply().
        self::assertArrayNotHasKey('order_id', $captured);
    }

    public function testApplySkipsEmptyAndMissingValues(): void
    {
        $payment = $this->createMock(Payment::class);
        $captured = [];
        $payment->method('setAdditionalInformation')
            ->willReturnCallback(static function (string $k, mixed $v) use (&$captured, $payment) {
                $captured[$k] = $v;
                return $payment;
            });

        PaymentInfoKeys::apply($payment, [
            'payment_id'  => 'pay-123',
            'order_status' => '',       // empty string -> skipped
            'masked_card'  => null,     // null -> skipped
            // everything else missing -> skipped
        ]);

        self::assertSame(['payment_id' => 'pay-123'], $captured);
    }

    public function testApplyOnEmptyPayloadWritesNothing(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->expects(self::never())->method('setAdditionalInformation');
        PaymentInfoKeys::apply($payment, []);
    }
}
