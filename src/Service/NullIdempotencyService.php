<?php

declare(strict_types=1);

namespace App\Service;

final class NullIdempotencyService implements IdempotencyServiceInterface
{
    public function acquireLock(string $key, string $token): bool
    {
        return true;
    }

    public function releaseLock(string $key, string $token): void {}

    public function cacheResult(string $key, array $result): void {}

    public function getCachedResult(string $key): ?array
    {
        return null;
    }

    public function invalidateCache(string $key): void {}
}