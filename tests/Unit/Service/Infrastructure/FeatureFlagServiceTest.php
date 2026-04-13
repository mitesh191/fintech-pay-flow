<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Infrastructure;

use App\Service\Infrastructure\FeatureFlagService;
use App\Tests\Stub\PredisClientStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FeatureFlagService (Redis-backed kill-switch).
 */
final class FeatureFlagServiceTest extends TestCase
{
    /** @var PredisClientStub&MockObject */
    private MockObject $redis;
    private LoggerInterface&MockObject $logger;
    private FeatureFlagService $service;

    protected function setUp(): void
    {
        // PredisClientStub extends Predis\Client and declares the needed Redis
        // commands as real abstract methods — createMock() can then stub/spy on
        // them without the now-deprecated MockBuilder::addMethods().
        $this->redis   = $this->createMock(PredisClientStub::class);
        $this->logger  = $this->createMock(LoggerInterface::class);
        $this->service = new FeatureFlagService($this->redis, $this->logger);
    }

    public function test_is_enabled_returns_true_when_redis_has_one(): void
    {
        $this->redis->method('get')->with('feature:transfers')->willReturn('1');

        $this->assertTrue($this->service->isEnabled('transfers'));
    }

    public function test_is_enabled_returns_false_when_redis_has_zero(): void
    {
        $this->redis->method('get')->with('feature:transfers')->willReturn('0');

        $this->assertFalse($this->service->isEnabled('transfers'));
    }

    public function test_is_enabled_returns_true_when_redis_key_is_absent(): void
    {
        $this->redis->method('get')->willReturn(null);

        // Absent key = enabled (kill-switch is opt-in; DEL restores normal operation)
        $this->assertTrue($this->service->isEnabled('transfers'));
    }

    public function test_is_enabled_defaults_to_true_on_redis_exception(): void
    {
        $this->redis->method('get')->willThrowException(new \RuntimeException('Redis down'));

        // Fail-open: if Redis is unreachable, transfers remain enabled
        $this->assertTrue($this->service->isEnabled('transfers'));
    }

    public function test_disable_sets_redis_key_to_zero(): void
    {
        $this->redis->expects($this->once())
            ->method('set')
            ->with('feature:transfers', '0');

        $this->service->disable('transfers');
    }

    public function test_enable_sets_redis_key_to_one_without_ttl(): void
    {
        $this->redis->expects($this->once())
            ->method('set')
            ->with('feature:transfers', '1');

        $this->service->enable('transfers');
    }

    public function test_enable_uses_setex_when_ttl_provided(): void
    {
        $this->redis->expects($this->once())
            ->method('setex')
            ->with('feature:transfers', 3600, '1');

        $this->service->enable('transfers', 3600);
    }
}
