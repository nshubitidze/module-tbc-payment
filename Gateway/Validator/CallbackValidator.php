<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Validator;

use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Gateway\Config\Config;

/**
 * Validates callback signatures from Flitt.
 */
class CallbackValidator
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Validate the callback signature.
     *
     * @param array<string, mixed> $callbackData The full callback payload
     * @param int|null $storeId Store ID for config lookup
     * @return bool True if signature is valid
     */
    public function validate(array $callbackData, ?int $storeId = null): bool
    {
        $receivedSignature = $callbackData['signature'] ?? '';

        if (empty($receivedSignature)) {
            // A callback without a signature is an invalid/forged request, not
            // a recoverable warning — surface it at ERROR so a forged-callback
            // probing wave clears Sentry's ERROR threshold and alerts ops.
            $this->logger->error('Flitt callback missing signature', [
                'order_id' => $callbackData['order_id'] ?? 'unknown',
            ]);
            return false;
        }

        $password = $this->config->getPassword($storeId);

        if (empty($password)) {
            $this->logger->error('Flitt password not configured, cannot validate callback');
            return false;
        }

        $expectedSignature = Config::generateSignature($this->scalarFields($callbackData), $password);

        if (!hash_equals($expectedSignature, (string) $receivedSignature)) {
            // Signature mismatch means the payload was tampered with or the
            // request is forged — ERROR (not WARNING) so a probing wave alerts.
            $this->logger->error('Flitt callback signature mismatch', [
                'order_id' => $callbackData['order_id'] ?? 'unknown',
            ]);
            return false;
        }

        return true;
    }

    /**
     * Reduce the callback payload to the scalar fields Flitt actually signs.
     *
     * Flitt computes the SHA1 over scalar request fields only. The vendored
     * Cloudipsp helper concatenates raw values, so a non-scalar value (array
     * or object) would be coerced to the literal string "Array" and corrupt
     * the recomputed signature — 403-ing a legitimate callback if Flitt ever
     * sends a nested field. Drop non-scalars here, plus `signature` and
     * `response_signature_string` (which Config::generateSignature also
     * strips), so the recompute matches what the SDK signed exactly.
     *
     * @param array<string, mixed> $callbackData
     * @return array<string, scalar>
     */
    private function scalarFields(array $callbackData): array
    {
        unset($callbackData['signature'], $callbackData['response_signature_string']);

        return array_filter($callbackData, static fn ($value): bool => is_scalar($value));
    }
}
