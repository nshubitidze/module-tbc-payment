<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Service;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * IMPROVE-2 — cross-path concurrency guard for the TBC capture paths.
 *
 * Callback, Confirm, ReturnAction::handleApproved, the admin CheckStatus button
 * and Cron/PendingOrderReconciler can all race to call
 * registerCaptureNotification() on the same order. Without serialization this
 * causes:
 *   - duplicate invoices
 *   - duplicate commission rows (Payout ledger double-credit)
 *   - duplicate settlement rows
 *
 * REUSE: this is a straight copy of {@see \Shubo\BogPayment\Service\PaymentLock}
 * with the namespace, the `tbc_` lock-name prefix and the key documentation
 * changed. The two payment modules share the identical concurrency problem and
 * the identical MySQL-advisory-lock solution; per CLAUDE.md "Simplicity-first"
 * step (2) Reuse, copying the proven BOG pattern needs no new-abstraction
 * justification doc.
 *
 * Design choice: **MySQL named advisory locks** (`GET_LOCK` / `RELEASE_LOCK`).
 *
 * Why not a dedicated lock table with INSERT IGNORE?
 *   - Zero schema change (no db_schema.xml migration + tests)
 *   - Auto-cleanup on connection drop (no stale rows to sweep)
 *   - Re-entrant per-connection (a single handler that happens to acquire
 *     twice within one request doesn't deadlock itself)
 *
 * Why not row-level FOR UPDATE on sales_order alone?
 *   - Confirm and ReturnAction already take SELECT ... FOR UPDATE, but Callback
 *     and Cron historically used a plain non-locking getList SELECT. A plain
 *     SELECT neither blocks nor is blocked by a FOR UPDATE on the same row, so
 *     the four paths were NOT mutually serialized. This advisory lock is what
 *     actually serializes them; the FOR UPDATE in Confirm/ReturnAction stays as
 *     belt-and-suspenders.
 *
 * Key: the Flitt order_id (`flitt_order_id`), falling back to the order
 * increment_id when flitt_order_id is empty — mirroring how Callback resolves
 * the order from the prefixed `duka_{incrementId}_{timestamp}` value.
 *
 * MySQL caveat: the lock is scoped to a single SESSION (connection). Magento's
 * default resource uses a single connection per PHP request, so our handlers
 * share the lock within a request but compete across requests — exactly what we
 * want.
 *
 * Timeout: 10 seconds. Registered capture + invoice creation typically runs in
 * 100–500 ms. A 10 s timeout gives the legitimate holder ample time while
 * preventing callback retries from piling up.
 */
class PaymentLock
{
    public const DEFAULT_TIMEOUT_SECONDS = 10;
    public const NAME_PREFIX = 'tbc_';

    /** @var list<string> Keys currently held by this instance, for cleanup. */
    private array $heldKeys = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * Attempt to acquire the advisory lock for $lockKey.
     *
     * Returns true if the lock was granted within $timeoutSeconds, false if
     * another session holds it or on error.
     */
    public function acquire(string $lockKey): bool
    {
        if ($lockKey === '') {
            throw new \InvalidArgumentException('lock key must not be empty');
        }

        $name = $this->lockName($lockKey);
        $connection = $this->resourceConnection->getConnection();

        $result = $connection->fetchOne(
            'SELECT GET_LOCK(:name, :timeout)',
            ['name' => $name, 'timeout' => $this->timeoutSeconds]
        );

        if ($result === '1' || $result === 1) {
            $this->heldKeys[] = $lockKey;
            return true;
        }

        $this->logger->warning('TBC payment lock: acquire failed', [
            'name' => $name,
            'result' => $result,
        ]);

        return false;
    }

    /**
     * Release the most recently acquired lock (if any).
     *
     * If a specific $lockKey is supplied, release that one; otherwise release
     * the top of the held-keys stack. Safe to call on an empty stack.
     */
    public function release(?string $lockKey = null): void
    {
        if ($lockKey === null) {
            $lockKey = array_pop($this->heldKeys);
            if ($lockKey === null) {
                return;
            }
        } else {
            $index = array_search($lockKey, $this->heldKeys, true);
            if ($index !== false) {
                array_splice($this->heldKeys, $index, 1);
            }
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->query(
            'SELECT RELEASE_LOCK(:name)',
            ['name' => $this->lockName($lockKey)]
        );
    }

    /**
     * Run $callable while holding the lock. On contention, the callable is
     * NOT invoked and null is returned. Any exception thrown by $callable
     * propagates after the lock is released.
     *
     * @template T
     * @param callable():T $callable
     * @return T|null
     */
    public function withLock(string $lockKey, callable $callable): mixed
    {
        if ($lockKey === '') {
            throw new \InvalidArgumentException('lock key must not be empty');
        }

        if (!$this->acquire($lockKey)) {
            return null;
        }

        try {
            return $callable();
        } finally {
            $this->release($lockKey);
        }
    }

    private function lockName(string $lockKey): string
    {
        return self::NAME_PREFIX . $lockKey;
    }
}
