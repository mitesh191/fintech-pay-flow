<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Transfer;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Enum\LedgerDirection;
use App\Service\Transfer\LedgerEntryFactory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LedgerEntryFactory — the double-entry bookkeeping factory.
 *
 * Fintech methodology: every completed payment must produce a balanced set of
 * ledger lines.  DEBIT source == CREDIT destination (conservation of value).
 * Fees produce an additional DEBIT on source — the fee stays in the system.
 */
final class LedgerEntryFactoryTest extends TestCase
{
    private LedgerEntryFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new LedgerEntryFactory();
    }

    // ─── buildTransferEntries() ───────────────────────────────────────────────

    public function test_transfer_without_fee_produces_two_entries(): void
    {
        [$tx, $src, $dst] = $this->makeTransfer('100.0000');

        $entries = $this->factory->buildTransferEntries(
            $tx, $src, '1000.0000', '900.0000',
            $dst, '500.0000', '600.0000',
            '0.0000', 'USD',
        );

        $this->assertCount(2, $entries);
    }

    public function test_transfer_with_fee_produces_three_entries(): void
    {
        [$tx, $src, $dst] = $this->makeTransfer('100.0000');

        $entries = $this->factory->buildTransferEntries(
            $tx, $src, '1000.0000', '895.0000',
            $dst, '500.0000', '600.0000',
            '5.0000', 'USD',
        );

        $this->assertCount(3, $entries);
    }

    public function test_transfer_no_fee_first_entry_debits_source_principal(): void
    {
        [$tx, $src, $dst] = $this->makeTransfer('100.0000');

        $entries = $this->factory->buildTransferEntries(
            $tx, $src, '1000.0000', '900.0000',
            $dst, '500.0000', '600.0000',
            '0.0000', 'USD',
        );

        $this->assertSame(LedgerDirection::Debit,  $entries[0]->getDirection());
        $this->assertSame('100.0000',              $entries[0]->getAmount());
        $this->assertSame('transfer',              $entries[0]->getEntryType());
        $this->assertSame($src,                    $entries[0]->getAccount());
    }

    public function test_transfer_no_fee_second_entry_credits_destination(): void
    {
        [$tx, $src, $dst] = $this->makeTransfer('100.0000');

        $entries = $this->factory->buildTransferEntries(
            $tx, $src, '1000.0000', '900.0000',
            $dst, '500.0000', '600.0000',
            '0.0000', 'USD',
        );

        $this->assertSame(LedgerDirection::Credit, $entries[1]->getDirection());
        $this->assertSame('100.0000',              $entries[1]->getAmount());
        $this->assertSame('transfer',              $entries[1]->getEntryType());
        $this->assertSame($dst,                    $entries[1]->getAccount());
    }

    public function test_transfer_with_fee_middle_entry_debits_fee_from_source(): void
    {
        [$tx, $src, $dst] = $this->makeTransfer('100.0000');

        $entries = $this->factory->buildTransferEntries(
            $tx, $src, '1000.0000', '895.0000',
            $dst, '500.0000', '600.0000',
            '5.0000', 'USD',
        );

        $feeEntry = $entries[1];
        $this->assertSame(LedgerDirection::Debit, $feeEntry->getDirection());
        $this->assertSame('5.0000',               $feeEntry->getAmount());
        $this->assertSame('fee',                  $feeEntry->getEntryType());
        $this->assertSame($src,                   $feeEntry->getAccount());
    }

    public function test_transfer_balance_snapshots_are_set(): void
    {
        [$tx, $src, $dst] = $this->makeTransfer('100.0000');

        $entries = $this->factory->buildTransferEntries(
            $tx, $src, '1000.0000', '900.0000',
            $dst, '500.0000', '600.0000',
            '0.0000', 'USD',
        );

        // Source entry
        $this->assertSame('1000.0000', $entries[0]->getBalanceBefore());
        $this->assertSame('900.0000',  $entries[0]->getBalanceAfter());

        // Dest entry
        $this->assertSame('500.0000', $entries[1]->getBalanceBefore());
        $this->assertSame('600.0000', $entries[1]->getBalanceAfter());
    }

    public function test_transfer_currency_propagated_to_entries(): void
    {
        [$tx, $src, $dst] = $this->makeTransfer('100.0000');

        $entries = $this->factory->buildTransferEntries(
            $tx, $src, '1000.0000', '900.0000',
            $dst, '500.0000', '600.0000',
            '0.0000', 'EUR',
        );

        foreach ($entries as $entry) {
            $this->assertSame('EUR', $entry->getCurrency());
        }
    }

    // ─── buildReversalEntries() ───────────────────────────────────────────────

    public function test_reversal_without_fee_produces_two_entries(): void
    {
        [$tx, $src, $dst] = $this->makeTransfer('100.0000');

        $entries = $this->factory->buildReversalEntries(
            $tx, $src, '900.0000', '1000.0000',
            $dst, '600.0000', '500.0000',
            '0.0000', 'USD',
        );

        $this->assertCount(2, $entries);
    }

    public function test_reversal_with_fee_produces_three_entries(): void
    {
        [$tx, $src, $dst] = $this->makeTransfer('100.0000');

        $entries = $this->factory->buildReversalEntries(
            $tx, $src, '895.0000', '1000.0000',
            $dst, '600.0000', '500.0000',
            '5.0000', 'USD',
        );

        $this->assertCount(3, $entries);
    }

    public function test_reversal_first_entry_debits_destination(): void
    {
        [$tx, $src, $dst] = $this->makeTransfer('100.0000');

        $entries = $this->factory->buildReversalEntries(
            $tx, $src, '900.0000', '1000.0000',
            $dst, '600.0000', '500.0000',
            '0.0000', 'USD',
        );

        $this->assertSame(LedgerDirection::Debit, $entries[0]->getDirection());
        $this->assertSame($dst,                   $entries[0]->getAccount());
        $this->assertSame('reversal',             $entries[0]->getEntryType());
    }

    public function test_reversal_second_entry_credits_source(): void
    {
        [$tx, $src, $dst] = $this->makeTransfer('100.0000');

        $entries = $this->factory->buildReversalEntries(
            $tx, $src, '900.0000', '1000.0000',
            $dst, '600.0000', '500.0000',
            '0.0000', 'USD',
        );

        $this->assertSame(LedgerDirection::Credit, $entries[1]->getDirection());
        $this->assertSame($src,                    $entries[1]->getAccount());
        $this->assertSame('reversal',              $entries[1]->getEntryType());
    }

    public function test_reversal_with_fee_third_entry_credits_fee_to_source(): void
    {
        [$tx, $src, $dst] = $this->makeTransfer('100.0000');

        $entries = $this->factory->buildReversalEntries(
            $tx, $src, '895.0000', '1000.0000',
            $dst, '600.0000', '500.0000',
            '5.0000', 'USD',
        );

        $feeReturn = $entries[2];
        $this->assertSame(LedgerDirection::Credit, $feeReturn->getDirection());
        $this->assertSame($src,                    $feeReturn->getAccount());
        $this->assertSame('5.0000',                $feeReturn->getAmount());
        $this->assertSame('reversal',              $feeReturn->getEntryType());
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{Transaction, Account, Account}
     */
    private function makeTransfer(string $amount): array
    {
        $src = new Account('Alice', 'USD');
        $dst = new Account('Bob',   'USD');
        $tx  = new Transaction(
            idempotencyKey:     'key-1',
            sourceAccount:      $src,
            destinationAccount: $dst,
            amount:             $amount,
            currency:           'USD',
            description:        'Test',
        );

        return [$tx, $src, $dst];
    }
}
