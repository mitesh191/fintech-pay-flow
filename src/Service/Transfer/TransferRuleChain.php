<?php

declare(strict_types=1);

namespace App\Service\Transfer;

/**
 * Composes an ordered list of TransferRuleInterfaces into a single callable.
 *
 * Rules are sorted by priority (ascending) at construction time so the
 * cheapest / most-likely-to-fail checks execute first, saving DB round-trips.
 */
final class TransferRuleChain
{
    /** @var TransferRuleInterface[] */
    private readonly array $rules;

    /**
     * @param iterable<TransferRuleInterface> $rules
     */
    public function __construct(iterable $rules)
    {
        $sorted = iterator_to_array($rules, false);
        usort($sorted, static fn(TransferRuleInterface $a, TransferRuleInterface $b): int =>
            $a->getPriority() <=> $b->getPriority()
        );
        $this->rules = $sorted;
    }

    /**
     * Run every rule in priority order. Stops at the first exception.
     *
     * @throws \DomainException|\RuntimeException
     */
    public function apply(TransferContext $context): void
    {
        foreach ($this->rules as $rule) {
            $rule->apply($context);
        }
    }
}
