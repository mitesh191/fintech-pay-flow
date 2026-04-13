<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use Symfony\Component\Uid\Uuid;

interface AccountRepositoryInterface
{
    public function save(Account $account, bool $flush = false): void;

    public function findByIdWithLock(Uuid $id, bool $lock = true): ?Account;

    /**
     * @return Account[]
     */
    public function findPairWithLock(string $sourceId, string $destinationId): array;

    /**
     * @return Account[]
     */
    public function findPaginated(int $page = 1, int $limit = 20, ?Uuid $apiKeyId = null): array;

    public function countAll(?Uuid $apiKeyId = null): int;
}
