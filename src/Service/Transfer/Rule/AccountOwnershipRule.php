<?php

declare(strict_types=1);

namespace App\Service\Transfer\Rule;

use App\Service\Transfer\TransferContext;
use App\Service\Transfer\TransferRuleInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Enforces that the authenticated caller (API key) owns the source account.
 *
 * Returns AccessDeniedHttpException which maps to HTTP 403.  The caller is
 * never told whether a different account exists — that is handled by
 * AccountActiveRule returning 404 before this rule if applicable.
 *
 * Why not 404 here?  By the time this rule runs both accounts have been
 * confirmed active, so pretending the account doesn't exist would be a lie
 * and would make debugging harder for legitimate callers with misconfigured
 * credentials.
 */
final class AccountOwnershipRule implements TransferRuleInterface
{
    public function apply(TransferContext $context): void
    {
        $sourceOwner = $context->getSourceAccount()?->getApiKey()?->getId();

        if ($sourceOwner === null || (string) $sourceOwner !== $context->getCallerApiKeyId()) {
            throw new AccessDeniedHttpException('You do not own the source account.');
        }
    }

    public function getPriority(): int
    {
        return 20;
    }
}
