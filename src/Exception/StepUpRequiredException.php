<?php

declare(strict_types=1);

namespace App\Exception;

final class StepUpRequiredException extends \DomainException
{
    public function __construct(string $currency, string $threshold)
    {
        parent::__construct(sprintf(
            'Step-up authentication required for transfers exceeding %s %s. '
            . 'Include a valid X-Step-Up-Token header.',
            $threshold,
            $currency,
        ));
    }
}
