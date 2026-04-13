<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Transfer\Rule;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\Exception\CurrencyMismatchException;
use App\Service\Transfer\Rule\CurrencyMismatchRule;
use App\Service\Transfer\TransferContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CurrencyMismatchRule (priority=30).
 *
 * Design: all three currencies (source, destination, request) must agree.
 * This prevents FX risk, which is explicitly out-of-scope per ADR-001.
 */
final class CurrencyMismatchRuleTest extends TestCase
{
    private CurrencyMismatchRule $rule;

    protected function setUp(): void
    {
        $this->rule = new CurrencyMismatchRule();
    }

    public function test_priority_is_30(): void
    {
        $this->assertSame(30, $this->rule->getPriority());
    }

    public function test_passes_when_all_three_currencies_match(): void
    {
        $ctx = $this->context(
            srcCurrency: 'USD',
            dstCurrency: 'USD',
            reqCurrency: 'USD',
        );

        $this->rule->apply($ctx);
        $this->addToAssertionCount(1);
    }

    public function test_passes_for_eur_matching(): void
    {
        $ctx = $this->context('EUR', 'EUR', 'EUR');

        $this->rule->apply($ctx);
        $this->addToAssertionCount(1);
    }

    public function test_throws_when_source_currency_differs(): void
    {
        $ctx = $this->context(srcCurrency: 'EUR', dstCurrency: 'USD', reqCurrency: 'USD');

        $this->expectException(CurrencyMismatchException::class);
        $this->rule->apply($ctx);
    }

    public function test_throws_when_destination_currency_differs(): void
    {
        $ctx = $this->context(srcCurrency: 'USD', dstCurrency: 'EUR', reqCurrency: 'USD');

        $this->expectException(CurrencyMismatchException::class);
        $this->rule->apply($ctx);
    }

    public function test_throws_when_request_currency_differs_from_accounts(): void
    {
        $ctx = $this->context(srcCurrency: 'USD', dstCurrency: 'USD', reqCurrency: 'GBP');

        $this->expectException(CurrencyMismatchException::class);
        $this->rule->apply($ctx);
    }

    public function test_throws_when_all_three_currencies_differ(): void
    {
        $ctx = $this->context(srcCurrency: 'USD', dstCurrency: 'EUR', reqCurrency: 'GBP');

        $this->expectException(CurrencyMismatchException::class);
        $this->rule->apply($ctx);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function context(string $srcCurrency, string $dstCurrency, string $reqCurrency): TransferContext
    {
        $request = new TransferRequest('src', 'dst', '100', $reqCurrency, 'k1');

        return TransferContext::create($request, 'caller', hash('sha256', 'caller:k1'))
            ->withAccounts(
                new Account('Alice', $srcCurrency),
                new Account('Bob',   $dstCurrency),
            );
    }
}
