<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when a referenced account does not exist or is inactive.
 */
final class AccountNotFoundException extends \RuntimeException
{
    public function __construct(string $accountId, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Account "%s" was not found or is inactive.', $accountId),
            $code,
            $previous,
        );
    }
}
