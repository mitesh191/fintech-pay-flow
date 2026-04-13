<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Infrastructure;

use App\Service\Infrastructure\DeadlockRetryWrapper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DeadlockRetryWrapper.
 */
final class DeadlockRetryWrapperTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private LoggerInterface&MockObject $logger;
    private DeadlockRetryWrapper $wrapper;

    protected function setUp(): void
    {
        $this->em     = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $this->em->method('getConnection')->willReturn($connection);

        $this->wrapper = new DeadlockRetryWrapper($this->em, $this->logger);
    }

    public function test_returns_result_on_success(): void
    {
        $result = $this->wrapper->run(fn () => 'ok');

        $this->assertSame('ok', $result);
    }

    public function test_retries_on_deadlock_and_eventually_succeeds(): void
    {
        $callCount = 0;

        $result = $this->wrapper->run(function () use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                throw $this->deadlockException();
            }
            return 'success-after-retries';
        });

        $this->assertSame('success-after-retries', $result);
        $this->assertSame(3, $callCount);
    }

    public function test_throws_after_max_retries_exhausted(): void
    {
        $callCount = 0;

        $this->expectException(DeadlockException::class);

        $this->wrapper->run(function () use (&$callCount): never {
            $callCount++;
            throw $this->deadlockException();
        });

        // MAX_RETRIES = 3, so operation will be tried 4 times (1 initial + 3 retries)
        $this->assertSame(4, $callCount);
    }

    public function test_non_deadlock_exceptions_are_not_retried(): void
    {
        $callCount = 0;

        $this->expectException(\InvalidArgumentException::class);

        $this->wrapper->run(function () use (&$callCount): never {
            $callCount++;
            throw new \InvalidArgumentException('Not a deadlock');
        });

        $this->assertSame(1, $callCount, 'Non-deadlock exceptions must not be retried.');
    }

    public function test_em_is_cleared_between_retries(): void
    {
        $this->em->expects($this->atLeastOnce())->method('clear');

        $callCount = 0;

        try {
            $this->wrapper->run(function () use (&$callCount): never {
                $callCount++;
                throw $this->deadlockException();
            });
        } catch (DeadlockException) {
            // expected
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function deadlockException(): DeadlockException
    {
        $pdoEx = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
        $pdoEx->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

        return new DeadlockException(
            \Doctrine\DBAL\Driver\PDO\Exception::new($pdoEx),
            null,
        );
    }
}
