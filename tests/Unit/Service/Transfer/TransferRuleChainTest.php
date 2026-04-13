<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Transfer;

use App\DTO\TransferRequest;
use App\Service\Transfer\TransferContext;
use App\Service\Transfer\TransferRuleChain;
use App\Service\Transfer\TransferRuleInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TransferRuleChain.
 *
 * OCP: Rules are plug-in components.  The chain sorts by priority and
 * applies them in order — it must never know rule internals.
 */
final class TransferRuleChainTest extends TestCase
{
    // ─── Priority ordering ────────────────────────────────────────────────────

    public function test_rules_execute_in_priority_order(): void
    {
        $log = [];

        $high  = $this->makeRule(10, function () use (&$log) { $log[] = 'high'; });
        $low   = $this->makeRule(100, function () use (&$log) { $log[] = 'low'; });
        $mid   = $this->makeRule(50,  function () use (&$log) { $log[] = 'mid'; });

        $chain = new TransferRuleChain([$low, $mid, $high]);
        $chain->apply($this->context());

        $this->assertSame(['high', 'mid', 'low'], $log);
    }

    public function test_rules_with_equal_priority_all_execute(): void
    {
        $log = [];

        $a = $this->makeRule(10, function () use (&$log) { $log[] = 'a'; });
        $b = $this->makeRule(10, function () use (&$log) { $log[] = 'b'; });

        $chain = new TransferRuleChain([$a, $b]);
        $chain->apply($this->context());

        $this->assertCount(2, $log);
    }

    // ─── Exception propagation ────────────────────────────────────────────────

    public function test_first_failing_rule_stops_execution(): void
    {
        $log = [];

        $pass = $this->makeRule(10, function () use (&$log) { $log[] = 'pass'; });
        $fail = $this->makeRule(20, function ()             { throw new \DomainException('fail'); });
        $skip = $this->makeRule(30, function () use (&$log) { $log[] = 'skip'; });

        $chain = new TransferRuleChain([$pass, $fail, $skip]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('fail');

        try {
            $chain->apply($this->context());
        } finally {
            $this->assertSame(['pass'], $log, 'Third rule must not execute after second throws.');
        }
    }

    public function test_exception_from_rule_is_rethrown_as_is(): void
    {
        $exception = new \RuntimeException('velocity breach');
        $rule      = $this->makeRule(10, fn() => throw $exception);

        $chain = new TransferRuleChain([$rule]);

        $this->expectExceptionObject($exception);
        $chain->apply($this->context());
    }

    // ─── Edge cases ───────────────────────────────────────────────────────────

    public function test_empty_chain_does_not_throw(): void
    {
        $chain = new TransferRuleChain([]);
        $chain->apply($this->context());

        $this->addToAssertionCount(1); // no exception = success
    }

    public function test_single_rule_executes(): void
    {
        $executed = false;
        $rule     = $this->makeRule(10, function () use (&$executed) { $executed = true; });

        (new TransferRuleChain([$rule]))->apply($this->context());

        $this->assertTrue($executed);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeRule(int $priority, callable $callback): TransferRuleInterface
    {
        return new class($priority, $callback) implements TransferRuleInterface {
            public function __construct(
                private readonly int $p,
                private $callback,
            ) {}

            public function apply(TransferContext $context): void { ($this->callback)(); }
            public function getPriority(): int { return $this->p; }
        };
    }

    private function context(): TransferContext
    {
        return TransferContext::create(
            new TransferRequest('src', 'dst', '100', 'USD', 'k'),
            'caller',
            'effective-key',
        );
    }
}
