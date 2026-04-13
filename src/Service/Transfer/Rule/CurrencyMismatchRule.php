<?php

declare(strict_types=1);

namespace App\Service\Transfer\Rule;

use App\Exception\CurrencyMismatchException;
use App\Service\Transfer\TransferContext;
use App\Service\Transfer\TransferRuleInterface;

/**
 * Prevents cross-currency transfers — intentional design constraint.
 *
 * All three currencies (source account, destination account, and the
 * explicit transfer request currency) must agree.  Requiring the caller
 * to state the currency explicitly provides an additional sanity check:
 * clients that mis-specify the currency fail here rather than silently
 * converting at the wrong rate later in the pipeline.
 *
 * DESIGN DECISION: Cross-currency (FX) transfers are explicitly out-of-scope.
 * Supporting USD → EUR etc. would require an FxRateProviderInterface +
 * FxConversionService that wraps an external rate feed and creates a
 * two-leg transaction (debit in source currency, credit in destination
 * currency at an agreed rate). If cross-currency support is needed in the
 * future, replace this rule with an FxConversionRule and inject the FX service.
 */
final class CurrencyMismatchRule implements TransferRuleInterface
{
    public function apply(TransferContext $context): void
    {
        $requestCurrency = $context->getRequest()->currency;

        if (
            $context->getSourceAccount()?->getCurrency()      !== $requestCurrency ||
            $context->getDestinationAccount()?->getCurrency() !== $requestCurrency
        ) {
            throw new CurrencyMismatchException();
        }
    }

    public function getPriority(): int
    {
        return 30;
    }
}
