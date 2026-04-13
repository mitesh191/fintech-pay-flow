<?php

declare(strict_types=1);

namespace App\Service;

interface IdempotencyServiceInterface
{
    public function acquireLock(string $idempotencyKey, string $token): bool;

    public function releaseLock(string $idempotencyKey, string $token): void;

    /**
     * @param array<string, mixed> $result
     */
    public function cacheResult(string $idempotencyKey, array $result): void;

    /**
     * @return array<string, mixed>|null
     */
    public function getCachedResult(string $idempotencyKey): ?array;

    public function invalidateCache(string $idempotencyKey): void;
}
