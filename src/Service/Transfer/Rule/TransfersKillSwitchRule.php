<?php

declare(strict_types=1);

namespace App\Service\Transfer\Rule;

use App\Service\Infrastructure\FeatureFlagService;
use App\Service\Transfer\TransferContext;
use App\Service\Transfer\TransferRuleInterface;

/**
 * Kill-switch rule: instantly halts all transfers when the
 * `transfers` feature flag is disabled in Redis.
 *
 * Placement: priority 1 — runs first in the chain so no DB work is
 * wasted when the system is in maintenance or under a compliance hold.
 *
 * Operations procedure:
 *   # Disable all transfers (incident response / compliance)
 *   redis-cli SET feature:transfers 0
 *
 *   # Re-enable
 *   redis-cli SET feature:transfers 1
 *   # or remove the key entirely (absent = enabled)
 *   redis-cli DEL feature:transfers
 *
 * The rule throws \RuntimeException (HTTP 503 via ExceptionSubscriber)
 * so downstream systems receive a clear "retry later" signal rather than
 * a permanent 4xx.
 */
final class TransfersKillSwitchRule implements TransferRuleInterface
{
    public function __construct(
        private readonly FeatureFlagService $featureFlags,
    ) {}

    public function apply(TransferContext $context): void
    {
        if (!$this->featureFlags->isEnabled('transfers')) {
            throw new \RuntimeException(
                'Transfers are temporarily disabled. Please try again later.'
            );
        }
    }

    public function getPriority(): int
    {
        return 1; // Highest priority — checked before any other rule.
    }
}
