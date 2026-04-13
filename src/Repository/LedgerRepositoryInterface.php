<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LedgerEntry;
use App\Entity\Transaction;
use Symfony\Component\Uid\Uuid;

interface LedgerRepositoryInterface
{
    public function save(LedgerEntry $entry, bool $flush = false): void;

    /**
     * Save multiple entries in a batch (single flush → one round-trip).
     *
     * @param LedgerEntry[] $entries
     */
    public function saveAll(array $entries, bool $flush = false): void;

    /**
     * @return LedgerEntry[]
     */
    public function findByTransaction(Transaction $transaction): array;

    /**
     * Chronological ledger history for one account — used for statement generation.
     *
     * @return LedgerEntry[]
     */
    public function findByAccount(Uuid $accountId, int $page = 1, int $limit = 50): array;

    public function countByAccount(Uuid $accountId): int;

    /**
     * Returns the running balance at a specific point in time.
     * Used by reconciliation jobs to validate accounts.balance.
     */
    public function computeBalanceAt(Uuid $accountId, \DateTimeImmutable $at): string;

    /**
     * All ledger entries created within the given time window.
     * Used by ReconciliationService for global SUM(debits)==SUM(credits) checks.
     *
     * @return \App\Entity\LedgerEntry[]
     */
    public function findByDateRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
