<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Transfer\Rule;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\Service\Transfer\Rule\TransactionAmountLimitRule;
use App\Service\Transfer\TransferContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TransactionAmountLimitRule (priority=25).
 *
 * Fraud prevention: enforces per-transaction minimum and maximum caps.
 * Min prevents micro-transaction fee arbitrage; max routes large transfers
 * through manual review (regulatory compliance).
 */
final class TransactionAmountLimitRuleTest extends TestCase
{
    public function test_priority_is_25(): void
    {
        $rule = new TransactionAmountLimitRule();
        $this->assertSame(25, $rule->getPriority());
    }

    public function test_passes_for_normal_amount(): void
    {
        $rule = new TransactionAmountLimitRule();
        $ctx  = $this->context('100.00', 'USD');

        $rule->apply($ctx);
        $this->addToAssertionCount(1);
    }

    public function test_passes_at_minimum_amount(): void
    {
        $rule = new TransactionAmountLimitRule(minAmount: '0.1000');
        $ctx  = $this->context('0.10', 'USD');

        $rule->apply($ctx);
        $this->addToAssertionCount(1);
    }

    public function test_throws_below_minimum_amount(): void
    {
        $rule = new TransactionAmountLimitRule(minAmount: '0.1000');
        $ctx  = $this->context('0.09', 'USD');

        $this->expectException(\DomainException::class);
        $rule->apply($ctx);
    }

    public function test_passes_at_default_maximum(): void
    {
        $rule = new TransactionAmountLimitRule(defaultMaxAmount: '100000.0000');
        $ctx  = $this->context('100000.00', 'USD');

        $rule->apply($ctx);
        $this->addToAssertionCount(1);
    }

    public function test_throws_above_default_maximum(): void
    {
        $rule = new TransactionAmountLimitRule(defaultMaxAmount: '100000.0000');
        $ctx  = $this->context('100000.01', 'USD');

        $this->expectException(\DomainException::class);
        $rule->apply($ctx);
    }

    public function test_uses_currency_specific_maximum(): void
    {
        $rule = new TransactionAmountLimitRule(
            maxAmountByCurrency: ['EUR' => '5000.0000'],
            defaultMaxAmount:    '100000.0000',
        );
        $ctx = $this->context('5001.00', 'EUR');

        $this->expectException(\DomainException::class);
        $rule->apply($ctx);
    }

    public function test_currency_specific_max_below_default_does_not_affect_other_currencies(): void
    {
        $rule = new TransactionAmountLimitRule(
            maxAmountByCurrency: ['EUR' => '5000.0000'],
            defaultMaxAmount:    '100000.0000',
        );
        $ctx = $this->context('80000.00', 'USD'); // USD uses default 100000

        $rule->apply($ctx);
        $this->addToAssertionCount(1);
    }

    public function test_exception_message_contains_amount_and_currency(): void
    {
        $rule = new TransactionAmountLimitRule(minAmount: '0.1000');

        try {
            $rule->apply($this->context('0.05', 'USD'));
            $this->fail('Expected DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('0.05',  $e->getMessage());
            $this->assertStringContainsString('USD',   $e->getMessage());
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function context(string $amount, string $currency): TransferContext
    {
        $request = new TransferRequest('src', 'dst', $amount, $currency, 'k1');

        return TransferContext::create($request, 'caller', hash('sha256', 'caller:k1'))
            ->withAccounts(
                new Account('Alice', $currency),
                new Account('Bob',   $currency),
            );
    }
}
