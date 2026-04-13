<?php

declare(strict_types=1);

namespace App\Service;

use Predis\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides Redis-backed idempotency guards for the transfer pipeline.
 *
 * Two mechanisms work in concert:
 *
 *  1. **In-flight lock** (SET NX PX): acquired before DB work starts, released
 *     after the transaction commits.  Prevents two concurrent requests with the
 *     same idempotency key from both entering the critical section.
 *
 *  2. **Result cache**: after a successful (or failed) transfer the outcome is
 *     stored in Redis for IDEMPOTENCY_TTL seconds.  Subsequent requests with the
 *     same key can be answered instantly without touching the database.
 *
 * Both keys are namespaced under `transfer:` to avoid collisions with other
 * parts of the system.
 */
final class IdempotencyService implements IdempotencyServiceInterface
{
    /** How long (seconds) we keep a completed result cached in Redis. */
    private const IDEMPOTENCY_TTL = 86_400; // 24 hours

    /** How long (milliseconds) the in-flight lock is held before auto-expiring. */
    private const LOCK_TTL_MS = 30_000; // 30 s — covers worst-case DB latency

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly LoggerInterface $logger,
    ) {}

    // ─── In-flight lock ───────────────────────────────────────────────────────

    /**
     * Try to acquire the processing lock for an idempotency key.
     *
     * @param string $token A unique random token used to safely release only our own lock.
     * @return bool true if the lock was acquired
     */
    public function acquireLock(string $idempotencyKey, string $token): bool
    {
        $result = $this->redis->set(
            $this->lockKey($idempotencyKey),
            $token,
            'NX',
            'PX',
            self::LOCK_TTL_MS,
        );

        return $result !== null;
    }

    /**
     * Release the lock only if we own it (compare-and-delete via Lua).
     */
    public function releaseLock(string $idempotencyKey, string $token): void
    {
        /** @lang Lua */
        $script = <<<'LUA'
            if redis.call("get", KEYS[1]) == ARGV[1] then
                return redis.call("del", KEYS[1])
            else
                return 0
            end
        LUA;

        try {
            $this->redis->eval($script, 1, $this->lockKey($idempotencyKey), $token);
        } catch (\Throwable $e) {
            // Non-fatal: the lock has a TTL so it will expire automatically.
            $this->logger->warning('Failed to release idempotency lock', [
                'key'   => $idempotencyKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ─── Result cache ─────────────────────────────────────────────────────────

    /**
     * Store a serialised transfer result so future duplicate requests are fast.
     *
     * @param array<string, mixed> $result
     */
    public function cacheResult(string $idempotencyKey, array $result): void
    {
        try {
            $this->redis->setex(
                $this->resultKey($idempotencyKey),
                self::IDEMPOTENCY_TTL,
                json_encode($result, JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $e) {
            // Non-fatal: worst case the DB will answer the duplicate check.
            $this->logger->warning('Failed to cache idempotency result', [
                'key'   => $idempotencyKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCachedResult(string $idempotencyKey): ?array
    {
        try {
            $raw = $this->redis->get($this->resultKey($idempotencyKey));
            if ($raw === null) {
                return null;
            }

            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to read idempotency cache', [
                'key'   => $idempotencyKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function invalidateCache(string $idempotencyKey): void
    {
        try {
            $this->redis->del([$this->resultKey($idempotencyKey)]);
        } catch (\Throwable) {
            // Best-effort cleanup — not critical
        }
    }

    // ─── Key builders ─────────────────────────────────────────────────────────

    private function lockKey(string $idempotencyKey): string
    {
        return sprintf('transfer:lock:%s', $idempotencyKey);
    }

    private function resultKey(string $idempotencyKey): string
    {
        return sprintf('transfer:result:%s', $idempotencyKey);
    }
}
