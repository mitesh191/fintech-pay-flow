<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiKey;

interface ApiKeyRepositoryInterface
{
    public function findByHash(string $keyHash): ?ApiKey;

    public function save(ApiKey $apiKey, bool $flush = false): void;
}
