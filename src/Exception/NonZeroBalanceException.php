<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Raised when attempting to deactivate an account that still carries a balance.
 * A financial system must never silently discard funds; the caller is required
 * to drain the balance (withdraw or transfer out) before closing an account.
 */
final class NonZeroBalanceException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Account cannot be deactivated while it holds a non-zero balance.');
    }
}
