<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Infrastructure;

use App\Service\Infrastructure\DatabaseCircuitBreaker;
use App\Tests\Stub\PredisClientStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DatabaseCircuitBreaker.
 *
 * Validates the three circuit states (CLOSED, OPEN, HALF-OPEN) and the
 * transition logic between them.
 */
final class DatabaseCircuitBreakerTest extends TestCase
{
    /** @var PredisClientStub&MockObject */
    private MockObject $redis;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        // PredisClientStub extends Predis\Client and declares the needed Redis
        // commands as real abstract methods — createMock() can then stub/spy on
        // them without the now-deprecated MockBuilder::addMethods().
        $this->redis  = $this->createMock(PredisClientStub::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function breaker(): DatabaseCircuitBreaker
    {
        return new DatabaseCircuitBreaker($this->redis, $this->logger, 'test-db');
    }

    // ─── isAvailable ──────────────────────────────────────────────────────────

    public function test_circuit_is_available_when_no_open_until_key(): void
    {
        $this->redis->method('get')->willReturn(null);

        $this->assertTrue($this->breaker()->isAvailable());
    }

    public function test_circuit_is_available_when_open_until_is_zero(): void
    {
        $this->redis->method('get')->willReturn('0');

        $this->assertTrue($this->breaker()->isAvailable());
    }

    public function test_circuit_is_not_available_when_open_until_is_in_future(): void
    {
        $futureTs = (string) (time() + 9999);
        $this->redis->method('get')->willReturn($futureTs);

        $this->assertFalse($this->breaker()->isAvailable());
    }

    public function test_circuit_is_available_after_open_window_expires(): void
    {
        // open_until is in the past — window has expired
        $pastTs = (string) (time() - 1);
        $this->redis->method('get')->willReturn($pastTs);

        $this->assertTrue($this->breaker()->isAvailable());
    }

    // ─── recordSuccess ────────────────────────────────────────────────────────

    public function test_record_success_deletes_all_circuit_keys(): void
    {
        $this->redis->expects($this->once())
            ->method('del')
            ->with($this->containsEqual('cb:test-db:failures'));

        $this->breaker()->recordSuccess();
    }

    // ─── recordFailure ────────────────────────────────────────────────────────

    public function test_record_failure_increments_failure_counter(): void
    {
        $this->redis->expects($this->once())->method('incr');
        $this->redis->method('incr')->willReturn(1);
        $this->redis->method('expire');

        $this->breaker()->recordFailure();
    }

    public function test_record_failure_trips_circuit_after_threshold(): void
    {
        // Simulate 5th failure — threshold is 5
        $this->redis->method('incr')->willReturn(5);
        $this->redis->method('expire');

        // Must set open_until key
        $this->redis->expects($this->once())
            ->method('set')
            ->with($this->stringContains('open_until'), $this->anything());

        $this->breaker()->recordFailure();
    }

    public function test_record_failure_below_threshold_does_not_trip_circuit(): void
    {
        $this->redis->method('incr')->willReturn(2);
        $this->redis->method('expire');

        // Must NOT set open_until
        $this->redis->expects($this->never())->method('set');

        $this->breaker()->recordFailure();
    }

    // ─── call ─────────────────────────────────────────────────────────────────

    public function test_call_executes_operation_and_records_success_when_available(): void
    {
        $this->redis->method('get')->willReturn(null); // CLOSED
        $this->redis->method('incr')->willReturn(0);
        $this->redis->expects($this->once())->method('del'); // recordSuccess

        $result = $this->breaker()->call(fn () => 42);

        $this->assertSame(42, $result);
    }

    public function test_call_throws_runtime_exception_when_circuit_is_open(): void
    {
        $futureTs = (string) (time() + 9999);
        $this->redis->method('get')->willReturn($futureTs);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/temporarily unavailable/');

        $this->breaker()->call(fn () => 42);
    }

    public function test_call_records_failure_and_rethrows_on_exception(): void
    {
        $this->redis->method('get')->willReturn(null); // CLOSED
        $this->redis->method('incr')->willReturn(1);
        $this->redis->method('expire');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB error');

        $this->breaker()->call(function (): never {
            throw new \RuntimeException('DB error');
        });
    }
}
