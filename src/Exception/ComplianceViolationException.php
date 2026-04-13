<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Raised when a transfer fails a compliance screening check
 * (sanctions, PEP, AML pattern detection).
 */
final class ComplianceViolationException extends \DomainException
{
    public function __construct(
        private readonly string $reason,
        private readonly string $screeningProvider = 'unknown',
    ) {
        parent::__construct(sprintf(
            'Transfer blocked by compliance screening (%s): %s',
            $screeningProvider,
            $reason,
        ));
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getScreeningProvider(): string
    {
        return $this->screeningProvider;
    }
}
