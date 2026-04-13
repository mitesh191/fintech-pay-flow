<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LedgerDirection;
use App\Repository\LedgerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Immutable double-entry ledger line.
 *
 * Every transfer creates exactly two rows:
 *   DEBIT  source_account_id   –amount  (balance_before → balance_after)
 *   CREDIT destination_account_id +amount  (balance_before → balance_after)
 *
 * Fee entries produce an additional DEBIT on the source account (amount = fee).
 *
 * This table is append-only at the application layer — no UPDATE or DELETE
 * should ever target it.  Corrections are made via reversal entries (a new
 * CREDIT on the account that was incorrectly DEBITed, tied to a reversal
 * transaction).
 *
 * Compliance / audit usage
 * ─────────────────────────
 * balance_before and balance_after allow regulators to reconstruct the
 * full account history from ledger_entries alone — independent of the
 * current accounts.balance column.  Reconciliation alerts fire when
 * SUM(debits) - SUM(credits) ≠ current balance.
 *
 * Performance
 * ────────────
 * At 2 M tx/s the table grows ~4 M rows/s (2 lines per transfer).
 * idx_le_account_created supports per-account history queries in O(log N).
 * Partitioning by created_at (RANGE COLUMNS, monthly) keeps each partition
 * under MySQL's 64 M row sweet-spot at sustained load.
 */
#[ORM\Entity(repositoryClass: LedgerRepository::class)]
#[ORM\Table(name: 'ledger_entries')]
#[ORM\Index(name: 'idx_le_account_created', columns: ['account_id', 'created_at'])]
#[ORM\Index(name: 'idx_le_transaction',     columns: ['transaction_id'])]
class LedgerEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Transaction::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Transaction $transaction;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Account $account;

    #[ORM\Column(enumType: LedgerDirection::class)]
    private LedgerDirection $direction;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 4)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** Account balance immediately before this entry was applied. */
    #[ORM\Column(type: 'decimal', precision: 20, scale: 4)]
    private string $balanceBefore;

    /** Account balance immediately after this entry was applied. */
    #[ORM\Column(type: 'decimal', precision: 20, scale: 4)]
    private string $balanceAfter;

    /** Semantic label: 'transfer', 'fee', 'reversal', 'adjustment'. */
    #[ORM\Column(length: 50)]
    private string $entryType;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Transaction    $transaction,
        Account        $account,
        LedgerDirection $direction,
        string         $amount,
        string         $currency,
        string         $balanceBefore,
        string         $balanceAfter,
        string         $entryType = 'transfer',
    ) {
        $this->id            = Uuid::v7();
        $this->transaction   = $transaction;
        $this->account       = $account;
        $this->direction     = $direction;
        $this->amount        = $amount;
        $this->currency      = $currency;
        $this->balanceBefore = $balanceBefore;
        $this->balanceAfter  = $balanceAfter;
        $this->entryType     = $entryType;
        $this->createdAt     = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTransaction(): Transaction
    {
        return $this->transaction;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getDirection(): LedgerDirection
    {
        return $this->direction;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBalanceBefore(): string
    {
        return $this->balanceBefore;
    }

    public function getBalanceAfter(): string
    {
        return $this->balanceAfter;
    }

    public function getEntryType(): string
    {
        return $this->entryType;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
