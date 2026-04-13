<?php

declare(strict_types=1);

namespace App\Service\Transfer\Rule;

use App\Service\Transfer\TransferContext;
use App\Service\Transfer\TransferRuleInterface;

/**
 * Enforces per-transaction maximum and minimum amount caps.
 *
 * Fraud prevention: blocks arbitrarily large transfers that should go
 * through manual review, and micro-transactions that could exploit fee rounding.
 *
 * Caps are configurable per-currency via constructor injection (services.yaml).
 */
final class TransactionAmountLimitRule implements TransferRuleInterface
{
    /**
     * @param array<string, string> $maxAmountByCurrency Currency-keyed max caps, e.g. ['USD' => '100000.0000', 'EUR' => '100000.0000']
     * @param string $defaultMaxAmount Fallback if currency not in map
     * @param string $minAmount Minimum transaction amount (prevents micro-transaction fee arbitrage)
     */
    public function __construct(
        private readonly array  $maxAmountByCurrency = [],
        private readonly string $defaultMaxAmount = '100000.0000',
        private readonly string $minAmount = '0.1000',
    ) {}

    public function apply(TransferContext $context): void
    {
        $amount   = $context->getRequest()->amount;
        $currency = $context->getRequest()->currency;

        // Minimum check
        if (bccomp($amount, $this->minAmount, 4) < 0) {
            throw new \DomainException(sprintf(
                'Transfer amount %s %s is below the minimum of %s %s.',
                $amount,
                $currency,
                $this->minAmount,
                $currency,
            ));
        }

        // Maximum check (per-currency)
        $maxAmount = $this->maxAmountByCurrency[$currency] ?? $this->defaultMaxAmount;

        if (bccomp($amount, $maxAmount, 4) > 0) {
            throw new \DomainException(sprintf(
                'Transfer amount %s %s exceeds the per-transaction maximum of %s %s. Large transfers require manual review.',
                $amount,
                $currency,
                $maxAmount,
                $currency,
            ));
        }
    }

    public function getPriority(): int
    {
        return 25; // After ownership check, before currency mismatch
    }
}
