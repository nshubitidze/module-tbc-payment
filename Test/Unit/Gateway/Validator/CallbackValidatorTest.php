<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Gateway\Validator;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Validator\CallbackValidator;

/**
 * Signature validation for Flitt server callbacks.
 *
 * Flitt signs the SHA1 over scalar request fields only. The validator must:
 *  - recompute over the scalar fields (IMPROVE-13) so a legitimate callback
 *    carrying a non-scalar field is NOT 403'd by the vendored Cloudipsp
 *    helper coercing an array to the literal string "Array";
 *  - keep the timing-safe hash_equals() comparison;
 *  - log forged/invalid callbacks at ERROR (IMPROVE-6) so a probing wave
 *    clears Sentry's ERROR threshold.
 */
class CallbackValidatorTest extends TestCase
{
    private const PASSWORD = 'test_secret_key';

    private Config&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private CallbackValidator $validator;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->validator = new CallbackValidator($this->config, $this->logger);
    }

    /**
     * Compute the signature Flitt would produce — over scalar fields only,
     * with `signature` / `response_signature_string` stripped — using the
     * exact same SDK path the validator delegates to.
     *
     * @param array<string, scalar> $scalarFields
     */
    private function sign(array $scalarFields): string
    {
        return Config::generateSignature($scalarFields, self::PASSWORD);
    }

    /**
     * Baseline: a purely scalar payload signed correctly validates true.
     */
    public function testValidScalarPayloadPasses(): void
    {
        $this->config->method('getPassword')->willReturn(self::PASSWORD);

        $signed = [
            'order_id' => 'ORDER-100',
            'amount' => '5000',
            'currency' => 'GEL',
            'order_status' => 'approved',
        ];
        $payload = $signed + ['signature' => $this->sign($signed)];

        $this->logger->expects($this->never())->method('error');

        self::assertTrue($this->validator->validate($payload, 1));
    }

    /**
     * IMPROVE-13 core case: Flitt sends an additional NON-scalar field
     * (e.g. a nested `additional_info` array). The signature is still
     * computed over the scalar fields only, so the validator — after
     * filtering to scalars — must still recompute the SAME signature and
     * accept the callback instead of 403-ing it.
     */
    public function testPayloadWithNestedArrayFieldStillValidates(): void
    {
        $this->config->method('getPassword')->willReturn(self::PASSWORD);

        // Only these scalar fields are what Flitt signs.
        $signedScalars = [
            'order_id' => 'ORDER-200',
            'amount' => '12345',
            'currency' => 'GEL',
            'order_status' => 'approved',
        ];
        $signature = $this->sign($signedScalars);

        // The real callback also carries a nested array field + the signature.
        $payload = $signedScalars + [
            'additional_info' => [
                'reservation_data' => ['foo' => 'bar'],
                'capture_status' => 'captured',
            ],
            'rrn' => 12345678, // non-string scalar — still signed/kept
            'signature' => $signature,
        ];

        // rrn is a scalar (int) so it participates in the signature too; sign
        // the full scalar set to keep the expectation honest.
        $expectedScalars = $signedScalars + ['rrn' => 12345678];
        $payload['signature'] = $this->sign($expectedScalars);

        $this->logger->expects($this->never())->method('error');

        self::assertTrue(
            $this->validator->validate($payload, 1),
            'A callback carrying a nested array field must still validate; '
            . 'the array must be filtered out before the signature recompute.'
        );
    }

    /**
     * Control: without the scalar filter the nested array would be coerced to
     * the literal "Array" and break the signature. Prove the filter is what
     * makes the above pass by signing the WRONG way (array coerced) and
     * asserting it is rejected.
     */
    public function testSignatureComputedWithCoercedArrayIsRejected(): void
    {
        $this->config->method('getPassword')->willReturn(self::PASSWORD);

        $scalars = [
            'order_id' => 'ORDER-300',
            'amount' => '900',
            'currency' => 'GEL',
        ];

        // Simulate a (broken) signer that left the array in: PHP coerces the
        // array value to the string "Array". This must NOT match the
        // validator's scalar-filtered recompute.
        $withCoercedArray = $scalars + ['meta' => 'Array'];
        $brokenSignature = $this->sign($withCoercedArray);

        $payload = $scalars + [
            'meta' => ['k' => 'v'],
            'signature' => $brokenSignature,
        ];

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Flitt callback signature mismatch', $this->anything());

        self::assertFalse($this->validator->validate($payload, 1));
    }

    /**
     * IMPROVE-6: a missing signature is a forged/invalid callback — logged at
     * ERROR (not WARNING) and rejected.
     */
    public function testMissingSignatureRejectedAndLoggedAtError(): void
    {
        $this->config->expects($this->never())->method('getPassword');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Flitt callback missing signature', ['order_id' => 'ORDER-400']);
        $this->logger->expects($this->never())->method('warning');

        self::assertFalse($this->validator->validate(['order_id' => 'ORDER-400'], 1));
    }

    /**
     * A signature mismatch (tampered payload / forged request) is rejected and
     * logged at ERROR so a probing wave alerts.
     */
    public function testSignatureMismatchRejectedAndLoggedAtError(): void
    {
        $this->config->method('getPassword')->willReturn(self::PASSWORD);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Flitt callback signature mismatch', ['order_id' => 'ORDER-500']);
        $this->logger->expects($this->never())->method('warning');

        $payload = [
            'order_id' => 'ORDER-500',
            'amount' => '100',
            'signature' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
        ];

        self::assertFalse($this->validator->validate($payload, 1));
    }

    /**
     * Defence in depth: an unconfigured password cannot validate anything.
     */
    public function testEmptyPasswordRejected(): void
    {
        $this->config->method('getPassword')->willReturn('');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Flitt password not configured, cannot validate callback');

        $payload = ['order_id' => 'ORDER-600', 'signature' => 'whatever'];

        self::assertFalse($this->validator->validate($payload, 1));
    }
}
