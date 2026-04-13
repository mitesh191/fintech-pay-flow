<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Raised when a transfer would push an account's daily sent total over its limit.
 *
 * Includes both the configured cap and the already-sent amount so the caller
 * can display a meaningful message without a second API call.
 */
final class DailyLimitExceededException extends \DomainException
{
    public function __construct(
        private readonly string $currency,
        private readonly string $dailyLimit,
        private readonly string $alreadySent,
    ) {
        parent::__construct(sprintf(
            'Daily transfer limit of %s %s reached. Sent today: %s %s.',
            $dailyLimit,
            $currency,
            $alreadySent,
            $currency,
        ));
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getDailyLimit(): string
    {
        return $this->dailyLimit;
    }

    public function getAlreadySent(): string
    {
        return $this->alreadySent;
    }
}
