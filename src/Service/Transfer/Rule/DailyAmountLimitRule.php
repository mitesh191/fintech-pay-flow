<?php

declare(strict_types=1);

namespace App\Service\Transfer\Rule;

use App\Exception\DailyLimitExceededException;
use App\Repository\TransactionRepositoryInterface;
use App\Service\Transfer\TransferContext;
use App\Service\Transfer\TransferRuleInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Velocity control: prevents a source account from sending more than
 * $dailyLimitAmount in principal value within a rolling UTC calendar day.
 *
 * Why this matters at scale
 * ──────────────────────────
 * Without a velocity cap a compromised API key can drain all accounts
 * linked to it in a single burst. Daily limits:
 *   - Reduce blast radius of credential theft.
 *   - Satisfy FFIEC / PCI-DSS velocity-monitoring requirements.
 *   - Provide a natural circuit-breaker for runaway automation bugs.
 *
 * The query hits idx_tx_source_created (source_account_id, created_at),
 * so it is O(log N) even at millions of rows.
 */
final class DailyAmountLimitRule implements TransferRuleInterface
{
    /**
     * @param array<string, string> $dailyLimitByCurrency Currency-keyed limits, e.g. ['USD' => '50000.0000', 'INR' => '500000.0000']
     * @param string $defaultDailyLimit Fallback limit if currency not in map
     * @param string $timezone Timezone for the velocity window (e.g. 'America/New_York', 'Asia/Kolkata'). Defaults to UTC.
     */
    public function __construct(
        private readonly TransactionRepositoryInterface $txRepo,
        private readonly string $defaultDailyLimit = '50000.0000',
        private readonly array  $dailyLimitByCurrency = [],
        private readonly string $timezone = 'UTC',
    ) {}

    public function apply(TransferContext $context): void
    {
        if ($context->getSourceAccount() === null) {
            return;
        }

        $currency = $context->getRequest()->currency;
        $dailyLimitAmount = $this->dailyLimitByCurrency[$currency] ?? $this->defaultDailyLimit;

        $sourceUuid = $context->getSourceAccount()->getId();
        $sentToday  = $this->txRepo->sumSentTodayByAccount($sourceUuid, $this->timezone);

        // Include total debit (principal + fee) in velocity check to close the fee-bypass loophole.
        $totalDebit = $context->getTotalDebit()->getAmount();
        $projected  = bcadd($sentToday, $totalDebit, 4);

        if (bccomp($projected, $dailyLimitAmount, 4) > 0) {
            throw new DailyLimitExceededException(
                $currency,
                $dailyLimitAmount,
                $sentToday,
            );
        }
    }

    public function getPriority(): int
    {
        return 40;
    }
}
