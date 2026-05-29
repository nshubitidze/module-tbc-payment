<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Service\PaymentLock;

/**
 * IMPROVE-2 — named advisory-lock service used by the Callback + Confirm +
 * ReturnAction + CheckStatus + Cron paths to serialize concurrent capture
 * processing for the same Flitt order.
 *
 * Implementation uses MySQL GET_LOCK(name, timeout) / RELEASE_LOCK(name).
 * - Happy path: GET_LOCK returns 1 → acquire() returns true.
 * - A second call from a DIFFERENT connection returns 0 → acquire() false.
 * - withLock always releases, even when the callable throws.
 * - A failed acquire returns null from withLock and never runs the callable.
 * - An empty key throws (callers must supply a non-empty lock key).
 *
 * Mirrors {@see \Shubo\BogPayment\Test\Unit\Service\PaymentLockTest}; the
 * lock-name prefix is `tbc_`.
 */
class PaymentLockTest extends TestCase
{
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $adapter;
    private LoggerInterface&MockObject $logger;
    private PaymentLock $lock;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->adapter);

        $this->lock = new PaymentLock(
            resourceConnection: $this->resourceConnection,
            logger: $this->logger,
        );
    }

    /**
     * Acquiring a free lock returns true. The SQL must invoke GET_LOCK with the
     * expected name format (`tbc_<key>`) and the configured timeout (10s).
     */
    public function testAcquireReturnsTrueWhenLockIsFree(): void
    {
        $this->adapter->expects(self::once())
            ->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $binds) {
                self::assertStringContainsString('GET_LOCK', $sql);
                self::assertSame('tbc_duka_000000042_1700', $binds['name'] ?? null);
                self::assertSame(PaymentLock::DEFAULT_TIMEOUT_SECONDS, $binds['timeout'] ?? null);
                return '1';
            });

        self::assertTrue($this->lock->acquire('duka_000000042_1700'));
    }

    public function testAcquireReturnsFalseWhenLockIsTaken(): void
    {
        $this->adapter->method('fetchOne')->willReturn('0');

        self::assertFalse($this->lock->acquire('duka_OTHER_1'));
    }

    /**
     * A NULL return from GET_LOCK means an error occurred (timeout, killed
     * query). Treat as failure.
     */
    public function testAcquireReturnsFalseOnNullReturn(): void
    {
        $this->adapter->method('fetchOne')->willReturn(null);

        self::assertFalse($this->lock->acquire('duka_ERR_1'));
    }

    public function testReleaseInvokesReleaseLock(): void
    {
        $this->adapter->method('fetchOne')->willReturn('1');
        $this->lock->acquire('duka_REL_1');

        $this->adapter->expects(self::once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $binds) {
                self::assertStringContainsString('RELEASE_LOCK', $sql);
                self::assertSame('tbc_duka_REL_1', $binds['name'] ?? null);
                return $this->createMock(\Zend_Db_Statement_Interface::class);
            });

        $this->lock->release();
    }

    public function testWithLockRunsCallableAndReleasesOnSuccess(): void
    {
        $this->adapter->method('fetchOne')->willReturn('1');
        // RELEASE_LOCK must fire.
        $this->adapter->expects(self::once())->method('query');

        $ran = false;
        $result = $this->lock->withLock('duka_OK_1', function () use (&$ran) {
            $ran = true;
            return 'payload';
        });

        self::assertTrue($ran);
        self::assertSame('payload', $result);
    }

    /**
     * withLock: when the callable throws, the exception propagates but the lock
     * is ALWAYS released (try/finally).
     */
    public function testWithLockReleasesEvenWhenCallableThrows(): void
    {
        $this->adapter->method('fetchOne')->willReturn('1');
        $this->adapter->expects(self::once())->method('query');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->lock->withLock('duka_THROW_1', function () {
            throw new \RuntimeException('boom');
        });
    }

    /**
     * withLock: on contention the callable is NOT invoked and null is returned
     * (the documented "defer to another path / retry" sentinel).
     */
    public function testWithLockSkipsCallableWhenLockContended(): void
    {
        $this->adapter->method('fetchOne')->willReturn('0');
        // No RELEASE_LOCK because we never acquired.
        $this->adapter->expects(self::never())->method('query');

        $ran = false;
        $result = $this->lock->withLock('duka_CONT_1', function () use (&$ran) {
            $ran = true;
            return 'should not run';
        });

        self::assertFalse($ran);
        self::assertNull($result);
    }

    public function testAcquireRejectsEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->lock->acquire('');
    }

    public function testWithLockRejectsEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->lock->withLock('', static fn () => 'never');
    }
}
