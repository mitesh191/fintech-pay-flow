<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\LedgerDirection;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the LedgerDirection backed enum values are stable.
 *
 * Both sides of double-entry bookkeeping must be frozen: changing
 * 'debit'/'credit' breaks every existing ledger_entry row.
 */
final class LedgerDirectionTest extends TestCase
{
    public function test_debit_value(): void
    {
        $this->assertSame('debit', LedgerDirection::Debit->value);
    }

    public function test_credit_value(): void
    {
        $this->assertSame('credit', LedgerDirection::Credit->value);
    }

    public function test_from_string_debit(): void
    {
        $this->assertSame(LedgerDirection::Debit, LedgerDirection::from('debit'));
    }

    public function test_from_string_credit(): void
    {
        $this->assertSame(LedgerDirection::Credit, LedgerDirection::from('credit'));
    }

    public function test_from_invalid_value_throws(): void
    {
        $this->expectException(\ValueError::class);
        LedgerDirection::from('transfer');
    }

    public function test_try_from_invalid_value_returns_null(): void
    {
        $this->assertNull(LedgerDirection::tryFrom('debit_credit'));
    }

    public function test_all_cases_count(): void
    {
        $this->assertCount(2, LedgerDirection::cases());
    }
}
