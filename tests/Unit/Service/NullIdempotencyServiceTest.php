<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\NullIdempotencyService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Null Object implementation of IdempotencyServiceInterface.
 *
 * DDD / Null-Object Pattern: NullIdempotencyService is used in test/local
 * contexts where Redis is unavailable.  Every method must be a safe no-op,
 * otherwise tests would break in CI environments without Redis.
 */
final class NullIdempotencyServiceTest extends TestCase
{
    private NullIdempotencyService $service;

    protected function setUp(): void
    {
        $this->service = new NullIdempotencyService();
    }

    public function test_acquire_lock_always_returns_true(): void
    {
        $this->assertTrue($this->service->acquireLock('any-key', 'any-token'));
    }

    public function test_acquire_lock_returns_true_for_same_key_twice(): void
    {
        $this->service->acquireLock('key', 'token1');
        $this->assertTrue($this->service->acquireLock('key', 'token2'));
    }

    public function test_get_cached_result_always_returns_null(): void
    {
        $this->assertNull($this->service->getCachedResult('any'));
    }

    public function test_get_cached_result_returns_null_even_after_cache_result(): void
    {
        $this->service->cacheResult('key', ['transaction_id' => 'tx-123']);
        $this->assertNull($this->service->getCachedResult('key'));
    }

    public function test_release_lock_does_not_throw(): void
    {
        $this->service->releaseLock('key', 'token');
        $this->addToAssertionCount(1); // reached = no exception
    }

    public function test_invalidate_cache_does_not_throw(): void
    {
        $this->service->invalidateCache('key');
        $this->addToAssertionCount(1);
    }

    public function test_cache_result_does_not_throw(): void
    {
        $this->service->cacheResult('key', ['foo' => 'bar']);
        $this->addToAssertionCount(1);
    }

    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(\App\Service\IdempotencyServiceInterface::class, $this->service);
    }
}
