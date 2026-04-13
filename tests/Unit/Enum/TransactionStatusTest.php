<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\TransactionStatus;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the TransactionStatus backed enum values are stable and cover
 * the full DDD aggregate lifecycle: Pending → {Completed|Failed} → Reversed.
 *
 * Changing any value breaks serialised DB rows, outbox events and audit logs —
 * freezing them here prevents accidental renames.
 */
final class TransactionStatusTest extends TestCase
{
    public function test_pending_value(): void
    {
        $this->assertSame('pending', TransactionStatus::Pending->value);
    }

    public function test_completed_value(): void
    {
        $this->assertSame('completed', TransactionStatus::Completed->value);
    }

    public function test_failed_value(): void
    {
        $this->assertSame('failed', TransactionStatus::Failed->value);
    }

    public function test_reversed_value(): void
    {
        $this->assertSame('reversed', TransactionStatus::Reversed->value);
    }

    public function test_from_string_pending(): void
    {
        $this->assertSame(TransactionStatus::Pending, TransactionStatus::from('pending'));
    }

    public function test_from_string_completed(): void
    {
        $this->assertSame(TransactionStatus::Completed, TransactionStatus::from('completed'));
    }

    public function test_from_string_failed(): void
    {
        $this->assertSame(TransactionStatus::Failed, TransactionStatus::from('failed'));
    }

    public function test_from_string_reversed(): void
    {
        $this->assertSame(TransactionStatus::Reversed, TransactionStatus::from('reversed'));
    }

    public function test_from_invalid_value_throws(): void
    {
        $this->expectException(\ValueError::class);
        TransactionStatus::from('unknown');
    }

    public function test_try_from_invalid_value_returns_null(): void
    {
        $this->assertNull(TransactionStatus::tryFrom('cancelled'));
    }

    public function test_all_cases_count(): void
    {
        $this->assertCount(4, TransactionStatus::cases());
    }

    public function test_cases_include_exact_set(): void
    {
        $values = array_map(static fn(TransactionStatus $s) => $s->value, TransactionStatus::cases());
        sort($values);

        $this->assertSame(['completed', 'failed', 'pending', 'reversed'], $values);
    }
}
