<?php

declare(strict_types=1);

namespace App\Service\Transfer\Rule;

use App\Exception\AccountNotFoundException;
use App\Service\Transfer\TransferContext;
use App\Service\Transfer\TransferRuleInterface;

/**
 * Ensures both source and destination accounts exist and are not deactivated.
 *
 * Accounts are loaded with a pessimistic write lock before this rule runs,
 * so any concurrent deactivation is visible immediately.  Returning a 404
 * (AccountNotFoundException) — rather than a 403 — prevents account enumeration.
 */
final class AccountActiveRule implements TransferRuleInterface
{
    public function apply(TransferContext $context): void
    {
        if ($context->getSourceAccount() === null || !$context->getSourceAccount()->isActive()) {
            throw new AccountNotFoundException($context->getRequest()->sourceAccountId);
        }

        if ($context->getDestinationAccount() === null || !$context->getDestinationAccount()->isActive()) {
            throw new AccountNotFoundException($context->getRequest()->destinationAccountId);
        }
    }

    public function getPriority(): int
    {
        return 10;
    }
}
