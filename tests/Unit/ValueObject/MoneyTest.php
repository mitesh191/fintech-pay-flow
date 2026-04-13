<?php

declare(strict_types=1);

namespace App\Tests\Unit\ValueObject;

use App\ValueObject\Money;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Money value object.
 *
 * DDD: Money is the canonical value object in a payment domain.
 * Fintech methodology: amount + currency are inseparable — mixing them
 * causes silent currency bugs at runtime. These tests protect that invariant.
 *
 * All arithmetic uses BCMath (scale=4) — IEEE-754 float is prohibited.
 */
final class MoneyTest extends TestCase
{
    // ─── Construction ─────────────────────────────────────────────────────────

    public function test_of_normalises_amount_to_four_decimals(): void
    {
        $m = Money::of('100', 'USD');

        $this->assertSame('100.0000', $m->getAmount());
    }

    public function test_of_uppercases_currency(): void
    {
        $m = Money::of('10', 'usd');

        $this->assertSame('USD', $m->getCurrency());
    }

    public function test_of_trims_currency_whitespace(): void
    {
        $m = Money::of('10', ' EUR ');

        $this->assertSame('EUR', $m->getCurrency());
    }

    public function test_of_throws_on_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of('-0.0001', 'USD');
    }

    public function test_of_throws_on_empty_currency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of('10', '');
    }

    public function test_of_throws_on_non_three_letter_currency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of('10', 'US');
    }

    public function test_zero_factory_returns_zero_amount(): void
    {
        $m = Money::zero('USD');

        $this->assertSame('0.0000', $m->getAmount());
        $this->assertSame('USD', $m->getCurrency());
    }

    // ─── Predicates ───────────────────────────────────────────────────────────

    public function test_is_zero_returns_true_for_zero(): void
    {
        $this->assertTrue(Money::zero('USD')->isZero());
    }

    public function test_is_zero_returns_false_for_non_zero(): void
    {
        $this->assertFalse(Money::of('0.0001', 'USD')->isZero());
    }

    public function test_is_same_currency_returns_true_for_matching(): void
    {
        $a = Money::of('10', 'USD');
        $b = Money::of('20', 'USD');

        $this->assertTrue($a->isSameCurrency($b));
    }

    public function test_is_same_currency_returns_false_for_different(): void
    {
        $a = Money::of('10', 'USD');
        $b = Money::of('10', 'EUR');

        $this->assertFalse($a->isSameCurrency($b));
    }

    // ─── add() ────────────────────────────────────────────────────────────────

    public function test_add_returns_sum_with_bcmath_precision(): void
    {
        $result = Money::of('0.1000', 'USD')->add(Money::of('0.2000', 'USD'));

        $this->assertSame('0.3000', $result->getAmount());
    }

    public function test_add_preserves_currency(): void
    {
        $result = Money::of('10', 'EUR')->add(Money::of('5', 'EUR'));

        $this->assertSame('EUR', $result->getCurrency());
    }

    public function test_add_returns_new_immutable_instance(): void
    {
        $a = Money::of('10', 'USD');
        $b = Money::of('5', 'USD');
        $c = $a->add($b);

        $this->assertNotSame($a, $c);
        $this->assertSame('10.0000', $a->getAmount(), 'original must not mutate');
    }

    public function test_add_throws_on_currency_mismatch(): void
    {
        $this->expectException(\DomainException::class);
        Money::of('10', 'USD')->add(Money::of('5', 'EUR'));
    }

    // ─── subtract() ───────────────────────────────────────────────────────────

    public function test_subtract_returns_difference(): void
    {
        $result = Money::of('100.0000', 'USD')->subtract(Money::of('30.5000', 'USD'));

        $this->assertSame('69.5000', $result->getAmount());
    }

    public function test_subtract_to_exactly_zero_succeeds(): void
    {
        $result = Money::of('50.0000', 'USD')->subtract(Money::of('50.0000', 'USD'));

        $this->assertSame('0.0000', $result->getAmount());
    }

    public function test_subtract_throws_on_negative_result(): void
    {
        $this->expectException(\DomainException::class);
        Money::of('10', 'USD')->subtract(Money::of('10.0001', 'USD'));
    }

    public function test_subtract_throws_on_currency_mismatch(): void
    {
        $this->expectException(\DomainException::class);
        Money::of('10', 'USD')->subtract(Money::of('5', 'EUR'));
    }

    // ─── Precision (fintech critical path) ───────────────────────────────────

    public function test_1000_sub_cent_additions_accumulate_without_drift(): void
    {
        $result = Money::zero('USD');
        for ($i = 0; $i < 1000; $i++) {
            $result = $result->add(Money::of('0.0001', 'USD'));
        }

        $this->assertSame('0.1000', $result->getAmount(), 'BCMath must not accumulate float drift.');
    }
}
