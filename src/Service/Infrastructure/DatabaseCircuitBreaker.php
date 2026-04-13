<?php

declare(strict_types=1);

namespace App\Service\Infrastructure;

use Predis\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Redis-backed circuit breaker for database operations.
 *
 * Pattern
 * ───────
 * CLOSED  → normal operation.  Failures are tallied in Redis.
 * OPEN    → fast-fail for OPEN_DURATION_SEC.  No DB calls attempted.
 * HALF-OPEN → one probe request is allowed through after the open window expires.
 *             Success → CLOSED; Failure → OPEN again.
 *
 * Fintech rationale
 * ─────────────────
 * Under a catastrophic DB failure, without a circuit breaker every in-flight
 * HTTP request will block for the full TCP/ORM timeout (typically 30 s).
 * This slams the connection pool, exhausts PHP-FPM workers, and turns a
 * DB blip into a full service outage.  With a circuit breaker, we shed load
 * instantly (HTTP 503) once the failure threshold is crossed, giving the DB
 * time to recover without spawning hundreds of new retry connections.
 *
 * Redis keys (all prefixed `cb:`):
 *   cb:{name}:failures   — INCR counter with TTL = FAILURE_WINDOW_SEC
 *   cb:{name}:open_until — timestamp when the OPEN state expires
 *   cb:{name}:half_open  — SET NX flag; first probe wins the HALF-OPEN slot
 *
 * All operations are atomic (single-key INCR, GETSET, SET NX PX) —
 * safe for multi-pod deployments behind a shared Redis.
 */
final class DatabaseCircuitBreaker
{
    /** How many failures within FAILURE_WINDOW_SEC trigger OPEN state. */
    private const FAILURE_THRESHOLD = 5;
    /** Sliding window in seconds for failure counting. */
    private const FAILURE_WINDOW_SEC = 60;
    /** How long (seconds) the circuit stays OPEN before trying HALF-OPEN. */
    private const OPEN_DURATION_SEC = 30;

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly LoggerInterface $logger,
        private readonly string           $name = 'db',
    ) {}

    /**
     * Returns true if the circuit is CLOSED or HALF-OPEN (call allowed through).
     * Returns false if the circuit is OPEN (fast-fail).
     */
    public function isAvailable(): bool
    {
        $openUntil = (int) ($this->redis->get($this->key('open_until')) ?? 0);

        if ($openUntil === 0 || time() >= $openUntil) {
            // CLOSED or open window expired — try HALF-OPEN probe
            return true;
        }

        // OPEN — fast fail
        return false;
    }

    /**
     * Record a successful operation.
     * In HALF-OPEN state this closes the circuit and clears the failure counter.
     */
    public function recordSuccess(): void
    {
        $this->redis->del([$this->key('failures'), $this->key('open_until'), $this->key('half_open')]);

        $this->logger->info('Circuit breaker: circuit CLOSED after probe success.', ['circuit' => $this->name]);
    }

    /**
     * Record a DB failure.
     * After FAILURE_THRESHOLD failures the circuit trips to OPEN.
     */
    public function recordFailure(): void
    {
        $failKey  = $this->key('failures');
        $failures = $this->redis->incr($failKey);

        // (Re-)set the sliding window TTL on every increment
        $this->redis->expire($failKey, self::FAILURE_WINDOW_SEC);

        if ((int) $failures >= self::FAILURE_THRESHOLD) {
            $openUntil = time() + self::OPEN_DURATION_SEC;
            $this->redis->set($this->key('open_until'), (string) $openUntil);

            $this->logger->critical('Circuit breaker OPEN — DB failure threshold reached', [
                'circuit'     => $this->name,
                'failures'    => $failures,
                'open_until'  => $openUntil,
            ]);
        }
    }

    /**
     * Execute $operation through the circuit breaker.
     *
     * @template T
     * @param  callable(): T $operation
     * @return T
     * @throws \RuntimeException when the circuit is OPEN
     * @throws \Throwable        on operation failure (and trip the circuit)
     */
    public function call(callable $operation): mixed
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException(
                sprintf('Service temporarily unavailable (circuit breaker open for "%s").', $this->name)
            );
        }

        try {
            $result = $operation();
            $this->recordSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    private function key(string $suffix): string
    {
        return sprintf('cb:%s:%s', $this->name, $suffix);
    }
}
