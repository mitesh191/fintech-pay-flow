<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Account;
use App\Entity\ApiKey;
use App\Enum\LedgerDirection;
use App\Exception\CurrencyMismatchException;
use App\Exception\InsufficientFundsException;
use App\ValueObject\Money;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Account aggregate root.
 *
 * Domain-Driven Design: Account is an Aggregate Root. These tests verify:
 *  - Construction invariants
 *  - State transitions (credit, debit, deactivate, rename)
 *  - BCMath 4dp precision guarantee (financial correctness)
 *  - Domain guard: InsufficientFundsException on overdraft
 *
 * SOLID: each test method tests one behaviour (SRP).
 *        No persistence dependency (ISP / dependency inversion).
 */
final class AccountTest extends TestCase
{
    // ─── Construction invariants ──────────────────────────────────────────────

    public function test_constructor_sets_owner_name(): void
    {
        $account = new Account('Alice Smith', 'USD', '1000.0000');

        $this->assertSame('Alice Smith', $account->getOwnerName());
    }

    public function test_constructor_uppercases_currency(): void
    {
        $account = new Account('Test', 'usd', '100.0000');

        $this->assertSame('USD', $account->getCurrency());
    }

    public function test_constructor_normalises_balance_to_four_decimal_places(): void
    {
        $account = new Account('Test', 'USD', '100');

        $this->assertSame('100.0000', $account->getBalance());
    }

    public function test_constructor_sets_balance_to_zero_by_default(): void
    {
        $account = new Account('Test', 'USD');

        $this->assertSame('0.0000', $account->getBalance());
    }

    public function test_constructor_sets_active_true(): void
    {
        $account = new Account('Test', 'USD');

        $this->assertTrue($account->isActive());
    }

    public function test_constructor_sets_version_to_zero(): void
    {
        $account = new Account('Test', 'USD');

        $this->assertSame(0, $account->getVersion());
    }

    public function test_constructor_assigns_uuid_v7_id(): void
    {
        $account = new Account('Test', 'USD');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $account->getId(),
        );
    }

    public function test_two_accounts_have_distinct_uuids(): void
    {
        $a = new Account('A', 'USD');
        $b = new Account('B', 'USD');

        $this->assertNotSame((string) $a->getId(), (string) $b->getId());
    }

    public function test_constructor_stores_api_key_reference(): void
    {
        $key     = new ApiKey('Client', 'raw-token');
        $account = new Account('Test', 'USD', '0', $key);

        $this->assertSame($key, $account->getApiKey());
    }

    public function test_constructor_stores_null_api_key_when_not_provided(): void
    {
        $account = new Account('Test', 'USD');

        $this->assertNull($account->getApiKey());
    }

    public function test_account_number_is_prefixed_with_ft(): void
    {
        $account = new Account('Test', 'USD');

        $this->assertStringStartsWith('FT', $account->getAccountNumber());
    }

    public function test_account_number_is_14_characters(): void
    {
        $account = new Account('Test', 'USD');

        $this->assertSame(14, strlen($account->getAccountNumber()));
    }

    // ─── credit() ─────────────────────────────────────────────────────────────

    public function test_credit_increases_balance(): void
    {
        $account = new Account('Test', 'USD', '100.0000');
        $account->credit(Money::of('50.0000', 'USD'));

        $this->assertSame('150.0000', $account->getBalance());
    }

    public function test_credit_normalises_integer_amount_to_four_decimals(): void
    {
        $account = new Account('Test', 'USD', '0.0000');
        $account->credit(Money::of('1', 'USD'));

        $this->assertSame('1.0000', $account->getBalance());
    }

    public function test_credit_accumulates_sub_cent_amounts_precisely(): void
    {
        $account = new Account('Test', 'USD', '0.0000');
        for ($i = 0; $i < 10; $i++) {
            $account->credit(Money::of('0.1000', 'USD'));
        }

        $this->assertSame('1.0000', $account->getBalance());
    }

    public function test_credit_refreshes_updated_at(): void
    {
        $account  = new Account('Test', 'USD', '0.0000');
        $snapshot = $account->getUpdatedAt();
        usleep(1_500);
        $account->credit(Money::of('1.0000', 'USD'));

        $this->assertGreaterThan($snapshot, $account->getUpdatedAt());
    }

    public function test_credit_rejects_wrong_currency(): void
    {
        $account = new Account('Test', 'USD', '100.0000');

        $this->expectException(CurrencyMismatchException::class);
        $account->credit(Money::of('10.0000', 'EUR'));
    }

    // ─── debit() ──────────────────────────────────────────────────────────────

    public function test_debit_decreases_balance(): void
    {
        $account = new Account('Test', 'USD', '100.0000');
        $account->debit(Money::of('30.0000', 'USD'));

        $this->assertSame('70.0000', $account->getBalance());
    }

    public function test_debit_exact_balance_leaves_zero(): void
    {
        $account = new Account('Test', 'USD', '99.9999');
        $account->debit(Money::of('99.9999', 'USD'));

        $this->assertSame('0.0000', $account->getBalance());
    }

    public function test_debit_throws_insufficient_funds_exception_when_overdraft(): void
    {
        $account = new Account('Test', 'USD', '10.0000');

        $this->expectException(InsufficientFundsException::class);
        $account->debit(Money::of('10.0001', 'USD'));
    }

    public function test_debit_throws_when_balance_is_zero(): void
    {
        $account = new Account('Test', 'USD', '0.0000');

        $this->expectException(InsufficientFundsException::class);
        $account->debit(Money::of('0.0001', 'USD'));
    }

    public function test_debit_refreshes_updated_at(): void
    {
        $account  = new Account('Test', 'USD', '100.0000');
        $snapshot = $account->getUpdatedAt();
        usleep(1_500);
        $account->debit(Money::of('1.0000', 'USD'));

        $this->assertGreaterThan($snapshot, $account->getUpdatedAt());
    }

    public function test_debit_rejects_wrong_currency(): void
    {
        $account = new Account('Test', 'USD', '100.0000');

        $this->expectException(CurrencyMismatchException::class);
        $account->debit(Money::of('10.0000', 'EUR'));
    }

    // ─── deactivate() ─────────────────────────────────────────────────────────

    public function test_deactivate_sets_active_false(): void
    {
        $account = new Account('Test', 'USD');
        $account->deactivate();

        $this->assertFalse($account->isActive());
    }

    public function test_deactivate_refreshes_updated_at(): void
    {
        $account  = new Account('Test', 'USD');
        $snapshot = $account->getUpdatedAt();
        usleep(1_500);
        $account->deactivate();

        $this->assertGreaterThan($snapshot, $account->getUpdatedAt());
    }

    // ─── renameOwner() ────────────────────────────────────────────────────────

    public function test_rename_owner_updates_name(): void
    {
        $account = new Account('Old', 'USD');
        $account->renameOwner('New Name');

        $this->assertSame('New Name', $account->getOwnerName());
    }

    public function test_rename_owner_refreshes_updated_at(): void
    {
        $account  = new Account('Old', 'USD');
        $snapshot = $account->getUpdatedAt();
        usleep(1_500);
        $account->renameOwner('New');

        $this->assertGreaterThan($snapshot, $account->getUpdatedAt());
    }

    public function test_rename_owner_throws_on_empty_string(): void
    {
        $account = new Account('Test', 'USD');

        $this->expectException(\InvalidArgumentException::class);
        $account->renameOwner('');
    }

    public function test_rename_owner_throws_on_256_character_name(): void
    {
        $account = new Account('Test', 'USD');

        $this->expectException(\InvalidArgumentException::class);
        $account->renameOwner(str_repeat('A', 256));
    }

    // ─── Timestamp immutability ───────────────────────────────────────────────

    public function test_created_at_is_datetime_immutable(): void
    {
        $account = new Account('Test', 'USD');

        $this->assertInstanceOf(\DateTimeImmutable::class, $account->getCreatedAt());
    }

    public function test_created_at_does_not_change_after_credit(): void
    {
        $account   = new Account('Test', 'USD', '100.0000');
        $createdAt = $account->getCreatedAt();
        $account->credit(Money::of('1.0000', 'USD'));

        $this->assertSame($createdAt, $account->getCreatedAt());
    }

    public function test_created_at_does_not_change_after_debit(): void
    {
        $account   = new Account('Test', 'USD', '100.0000');
        $createdAt = $account->getCreatedAt();
        $account->debit(Money::of('1.0000', 'USD'));

        $this->assertSame($createdAt, $account->getCreatedAt());
    }

    public function test_get_balance_money_returns_money_vo(): void
    {
        $account = new Account('Test', 'USD', '250.5000');

        $money = $account->getBalanceMoney();

        $this->assertSame('250.5000', $money->getAmount());
        $this->assertSame('USD', $money->getCurrency());
    }
}
