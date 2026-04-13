<?php

declare(strict_types=1);

namespace App\Service\Infrastructure;

use Predis\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Redis-backed feature flag service (kill-switch pattern).
 *
 * Fintech rationale
 * ─────────────────
 * In financial systems a kill-switch is a non-negotiable operational control:
 *   • A compliance officer can halt all outbound transfers instantly
 *     without a code deployment or firewall change.
 *   • Post-incident response can gate recovery: re-enable per-feature
 *     rather than rolling back the full deployment.
 *   • Canary releases: enable a new flow for 1 % of traffic by combining
 *     flags with a consistent hash on account ID.
 *
 * Redis is the right store here because:
 *   a) Sub-millisecond reads — does not add measurable latency to the hot path.
 *   b) Distributed — all pods see the same flag state immediately.
 *   c) TTL support — time-boxed experiments expire automatically.
 *   d) Atomic SET/GET — no distributed lock needed for boolean flags.
 *
 * Flag key convention: `feature:{name}` → '1' = enabled, absent/'0' = disabled.
 *
 * Usage:
 *   if (!$this->featureFlags->isEnabled('transfers')) {
 *       throw new \RuntimeException('Transfers are currently disabled.');
 *   }
 *
 * Enable/disable (Redis CLI or a management endpoint):
 *   SET feature:transfers 1
 *   DEL feature:transfers
 */
final class FeatureFlagService
{
    private const KEY_PREFIX = 'feature:';

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns true unless the flag is explicitly set to '0'.
     *
     * Semantics: absent key or '1' = enabled; only '0' = disabled.
     * This means DEL feature:transfers immediately re-enables transfers —
     * the kill-switch is opt-IN (must be actively triggered), not opt-out.
     */
    public function isEnabled(string $feature): bool
    {
        try {
            $value = $this->redis->get(self::KEY_PREFIX . $feature);
            // null (key absent) and '1' both mean enabled; only explicit '0' disables
            return $value !== '0';
        } catch (\Throwable $e) {
            // Redis outage must not block transfers — default to ENABLED so
            // a Redis failure does not accidentally kill-switch the whole system.
            $this->logger->warning('FeatureFlagService: Redis read failed, defaulting to enabled', [
                'feature' => $feature,
                'error'   => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * Enable a feature flag (optional TTL in seconds).
     * TTL = 0 means the flag persists until explicitly deleted.
     */
    public function enable(string $feature, int $ttlSeconds = 0): void
    {
        $key = self::KEY_PREFIX . $feature;

        if ($ttlSeconds > 0) {
            $this->redis->setex($key, $ttlSeconds, '1');
        } else {
            $this->redis->set($key, '1');
        }

        $this->logger->info('Feature flag enabled', ['feature' => $feature, 'ttl' => $ttlSeconds]);
    }

    /**
     * Disable a feature flag (kill-switch).
     */
    public function disable(string $feature): void
    {
        $this->redis->set(self::KEY_PREFIX . $feature, '0');
        $this->logger->warning('Feature flag DISABLED (kill-switch activated)', ['feature' => $feature]);
    }
}
