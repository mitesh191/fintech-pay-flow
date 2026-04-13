<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Account;
use App\Exception\InsufficientFundsException;
use App\ValueObject\Money;
use PHPUnit\Framework\TestCase;

/**
 * Financial precision and boundary tests for the Account aggregate.
 *
 * Fintech methodology: every sub-cent operation must not introduce float
 * rounding error. BCMath at scale=4 is the only safe arithmetic here.
 *
 * DDD: tests the invariants enforced by the Account domain aggregate root.
 */
final class AccountBoundaryTest extends TestCase
{
    // ─── Debit boundaries ─────────────────────────────────────────────────────

    public function test_debit_one_sub_cent_over_balance_throws(): void
    {
        $account = new Account('Test', 'USD', '100.0000');

        $this->expectException(InsufficientFundsException::class);
        $account->debit(Money::of('100.0001', 'USD'));
    }

    public function test_successive_debits_drain_to_exactly_zero(): void
    {
        $account = new Account('Test', 'USD', '0.0003');
        $account->debit(Money::of('0.0001', 'USD'));
        $account->debit(Money::of('0.0001', 'USD'));
        $account->debit(Money::of('0.0001', 'USD'));

        $this->assertSame('0.0000', $account->getBalance());
    }

    public function test_three_way_split_of_one_dollar_does_not_leave_residual(): void
    {
        // 0.3333 + 0.3333 + 0.3334 = 1.0000 exactly in BCMath
        $account = new Account('Test', 'USD', '1.0000');
        $account->debit(Money::of('0.3333', 'USD'));
        $account->debit(Money::of('0.3333', 'USD'));
        $account->debit(Money::of('0.3334', 'USD'));

        $this->assertSame('0.0000', $account->getBalance());
    }

    public function test_fifty_debits_of_one_dollar_are_exactly_fifty(): void
    {
        $account = new Account('Test', 'USD', '500.0000');
        for ($i = 0; $i < 50; $i++) {
            $account->debit(Money::of('1.0000', 'USD'));
        }

        $this->assertSame('450.0000', $account->getBalance());
    }

    // ─── Credit precision ─────────────────────────────────────────────────────

    public function test_one_thousand_sub_cent_credits_accumulate_precisely(): void
    {
        $account = new Account('Test', 'USD', '0.0000');
        for ($i = 0; $i < 1000; $i++) {
            $account->credit(Money::of('0.0001', 'USD'));
        }

        $this->assertSame('0.1000', $account->getBalance());
    }

    public function test_fractional_debit_preserves_precision(): void
    {
        $account = new Account('Test', 'USD', '1.0000');
        $account->debit(Money::of('0.3333', 'USD'));

        $this->assertSame('0.6667', $account->getBalance());
    }

    public function test_credit_then_debit_same_amount_restores_balance(): void
    {
        $account = new Account('Test', 'USD', '500.0000');
        $account->credit(Money::of('250.0000', 'USD'));
        $account->debit(Money::of('250.0000', 'USD'));

        $this->assertSame('500.0000', $account->getBalance());
    }

    // ─── Currency normalisation ───────────────────────────────────────────────

    public function test_mixed_case_currency_is_normalised_to_uppercase(): void
    {
        $account = new Account('Test', 'GbP', '0.0000');

        $this->assertSame('GBP', $account->getCurrency());
    }

    public function test_initial_balance_integer_string_normalises_to_four_decimals(): void
    {
        $account = new Account('Test', 'USD', '100');

        $this->assertSame('100.0000', $account->getBalance());
    }

    // ─── Fund conservation ────────────────────────────────────────────────────

    /**
     * Core fintech invariant: no money created or destroyed.
     * After N debits from A and N credits to B, the total stays constant.
     */
    public function test_fund_conservation_over_multiple_debits_and_credits(): void
    {
        $alice = new Account('Alice', 'USD', '10000.0000');
        $bob   = new Account('Bob',   'USD',  '5000.0000');

        $totalBefore = bcadd($alice->getBalance(), $bob->getBalance(), 4);

        $transfers = ['100.0000', '250.5000', '0.0001', '999.9999'];
        foreach ($transfers as $amount) {
            $alice->debit(Money::of($amount, 'USD'));
            $bob->credit(Money::of($amount, 'USD'));
        }

        $totalAfter = bcadd($alice->getBalance(), $bob->getBalance(), 4);

        $this->assertSame($totalBefore, $totalAfter, 'Total funds must be conserved across transfers.');
    }
}
