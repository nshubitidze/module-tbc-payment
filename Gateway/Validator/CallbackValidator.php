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
            $this->logger->warning('Flitt callback missing signature', [
                'order_id' => $callbackData['order_id'] ?? 'unknown',
            ]);
            return false;
        }

        $password = $this->config->getPassword($storeId);

        if (empty($password)) {
            $this->logger->error('Flitt password not configured, cannot validate callback');
            return false;
        }

        $expectedSignature = Config::generateSignature($callbackData, $password);

        if (!hash_equals($expectedSignature, $receivedSignature)) {
            $this->logger->warning('Flitt callback signature mismatch', [
                'order_id' => $callbackData['order_id'] ?? 'unknown',
            ]);
            return false;
        }

        return true;
    }
}
