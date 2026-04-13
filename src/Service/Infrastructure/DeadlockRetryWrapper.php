<?php

declare(strict_types=1);

namespace App\Service\Infrastructure;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Wraps a callable in a deadlock-retry loop.
 *
 * Fintech rationale
 * ─────────────────
 * Under concurrent load, InnoDB can deadlock when two transactions lock rows
 * in different orders. Retrying is safe here because:
 *   a) The callable is re-executed from scratch (no partial state carried over).
 *   b) The EntityManager is cleared and the connection rolled back before retry.
 *   c) Exponential back-off with jitter avoids thundering-herd reconverging.
 *
 * MySQL error 1213 (ER_LOCK_DEADLOCK) is the canonical deadlock signal.
 * Doctrine wraps it as DeadlockException since DBAL 3.x.
 *
 * Usage:
 *   $result = $this->deadlockRetry->run(fn() => $this->doTransfer($request));
 */
final class DeadlockRetryWrapper
{
    private const MAX_RETRIES   = 3;
    private const BASE_DELAY_MS = 50;   // milliseconds — base back-off
    private const MAX_DELAY_MS  = 500;  // milliseconds — cap

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * Execute $operation, retrying on InnoDB deadlocks up to MAX_RETRIES times.
     *
     * @template T
     * @param  callable(): T $operation
     * @return T
     * @throws \Throwable  Re-throws the last deadlock exception after exhausting retries,
     *                     or any non-deadlock exception immediately.
     */
    public function run(callable $operation): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (DeadlockException $e) {
                $attempt++;

                if ($attempt > self::MAX_RETRIES) {
                    $this->logger->error('Deadlock not resolved after max retries', [
                        'attempts' => $attempt,
                        'error'    => $e->getMessage(),
                    ]);

                    throw $e;
                }

                // Roll back and clear the ORM identity map — the next attempt
                // must start with a clean slate to avoid stale entity state.
                $this->safeRollback();
                $this->em->clear();

                // Exponential back-off with full jitter: sleep [0, base*2^attempt] ms
                $ceiling = min(self::BASE_DELAY_MS * (2 ** $attempt), self::MAX_DELAY_MS);
                $delayUs = random_int(0, $ceiling) * 1_000; // µs
                usleep($delayUs);

                $this->logger->warning('Deadlock detected — retrying', [
                    'attempt'   => $attempt,
                    'delay_ms'  => intdiv($delayUs, 1_000),
                ]);
            }
        }
    }

    private function safeRollback(): void
    {
        try {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
        } catch (\Throwable) {
            // Ignore rollback errors — connection may already be broken.
        }
    }
}
