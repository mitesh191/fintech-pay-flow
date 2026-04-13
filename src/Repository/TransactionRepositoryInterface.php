<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use Symfony\Component\Uid\Uuid;

interface TransactionRepositoryInterface
{
    public function findByIdempotencyKey(string $key): ?Transaction;

    public function findByUuid(Uuid $id): ?Transaction;

    /**
     * @return Transaction[]
     */
    public function findByAccount(Uuid $accountId, int $page = 1, int $limit = 20): array;

    public function countByAccount(Uuid $accountId): int;

    /**
     * @return Transaction[]
     */
    public function findAllPaginated(int $page = 1, int $limit = 20, ?Uuid $apiKeyId = null): array;

    public function countAll(?Uuid $apiKeyId = null): int;

    /**
     * @return Transaction[]
     */
    public function findPendingOlderThan(\DateTimeImmutable $threshold): array;

    /**
     * Returns the total COMPLETED transfer amount sent from an account
     * within the current calendar day in the given timezone.
     *
     * Used by DailyAmountLimitRule for velocity control.
     * Hits idx_tx_source_created so performance is O(log N) even at millions of rows.
     */
    public function sumSentTodayByAccount(Uuid $accountId, string $timezone = 'UTC'): string;

    public function save(Transaction $transaction, bool $flush = false): void;
}
