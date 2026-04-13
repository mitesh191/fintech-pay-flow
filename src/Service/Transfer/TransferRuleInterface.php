<?php

declare(strict_types=1);

namespace App\Service\Transfer;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Every transfer validation rule must implement this interface.
 *
 * Rules are executed sequentially after accounts are locked in the DB.
 * A rule throws a domain exception to halt the pipeline; an absence of
 * exceptions means the rule passed.
 *
 * OCP: new validation rules (velocity limits, sanction screening, AML
 *      flags, credit check) are added as new classes — TransferService
 *      never changes.
 *
 * ISP: rules deal only with TransferContext; they never inspect the
 *      HTTP layer or Doctrine infrastructure.
 */
#[AutoconfigureTag('transfer.rule')]
interface TransferRuleInterface
{
    /**
     * Validate the context. Throw a domain exception on failure.
     *
     * @throws \DomainException|\RuntimeException
     */
    public function apply(TransferContext $context): void;

    /**
     * Lower number = runs first.
     * Typical ordering:
     *   10 → AccountActiveRule
     *   20 → AccountOwnershipRule
     *   30 → CurrencyMismatchRule
     *   40 → DailyAmountLimitRule
     *   50 → MaxSingleTransferRule
     */
    public function getPriority(): int;
}
