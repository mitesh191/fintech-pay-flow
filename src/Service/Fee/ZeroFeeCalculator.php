<?php

declare(strict_types=1);

namespace App\Service\Fee;

use App\Service\Transfer\TransferContext;

/**
 * No-op fee calculator — the default for internal / peer-to-peer transfers.
 *
 * Replace with PercentageFeeCalculator (or a composite) in services.yaml
 * to charge processing fees without touching TransferService.
 */
final class ZeroFeeCalculator implements FeeCalculatorInterface
{
    public function calculate(TransferContext $context): string
    {
        return '0.0000';
    }
}
