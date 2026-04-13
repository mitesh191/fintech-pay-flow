<?php

declare(strict_types=1);

namespace App\Service\Fee;

use App\Service\Transfer\TransferContext;

/**
 * Charges a flat percentage of the principal amount.
 *
 * Example: 0.25 % on a 1000.00 USD transfer = 2.5000 USD fee.
 * The fee is truncated (not rounded) to 4 decimal places to ensure
 * the system never charges fractionally more than stated.
 *
 * Usage (services.yaml):
 *   App\Service\Fee\FeeCalculatorInterface:
 *       class: App\Service\Fee\PercentageFeeCalculator
 *       arguments:
 *           $ratePercent: '%env(string:TRANSFER_FEE_PERCENT)%'
 *           $maxFeeAmount: '%env(string:TRANSFER_FEE_MAX)%'
 */
final class PercentageFeeCalculator implements FeeCalculatorInterface
{
    public function __construct(
        /** Fee rate expressed as a percentage string, e.g. '0.25' means 0.25 %. */
        private readonly string  $ratePercent  = '0.25',
        /** Hard cap on fee per transaction (e.g. '30.0000'). Null = no cap. */
        private readonly ?string $maxFeeAmount = null,
    ) {}

    public function calculate(TransferContext $context): string
    {
        // Multiply: amount × ratePercent / 100, entirely in bcmath — zero float operations.
        $scale = 4;
        $fee   = bcdiv(
            bcmul($context->getRequest()->amount, $this->ratePercent, $scale + 4),
            '100',
            $scale,
        );

        // Apply cap if configured
        if ($this->maxFeeAmount !== null && bccomp($fee, $this->maxFeeAmount, $scale) > 0) {
            return bcadd($this->maxFeeAmount, '0', $scale); // normalise to 4dp
        }

        return $fee;
    }
}
