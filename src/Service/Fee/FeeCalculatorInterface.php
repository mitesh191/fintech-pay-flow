<?php

declare(strict_types=1);

namespace App\Service\Fee;

use App\Service\Transfer\TransferContext;

/**
 * Calculates the processing fee for a transfer.
 *
 * OCP: swap in a PercentageFeeCalculator, TieredFeeCalculator, or
 *      CurrencyFeeCalculator without changing TransferService.
 *
 * The fee is injected into TransferContext.feeAmount before the rule
 * chain runs so DailyAmountLimitRule can optionally include fees in
 * the velocity check.
 */
interface FeeCalculatorInterface
{
    /**
     * Return the fee amount (as a DECIMAL-safe string with 4 decimal places)
     * for the given transfer context.
     *
     * Implementations MUST return a non-negative value.  Returning '0.0000'
     * means no fee is charged.
     */
    public function calculate(TransferContext $context): string;
}
