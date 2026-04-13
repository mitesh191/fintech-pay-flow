<?php

declare(strict_types=1);

namespace App\Service\Transfer\Rule;

use App\Exception\StepUpRequiredException;
use App\Service\Auth\StepUpAuthenticationInterface;
use App\Service\Transfer\TransferContext;
use App\Service\Transfer\TransferRuleInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Enforces step-up (second-factor) authentication for high-value transfers.
 *
 * PSD2 SCA compliance: transfers exceeding the configured threshold (default
 * 500.00 in any currency) require a second factor submitted via the
 * X-Step-Up-Token header. The token is verified against StepUpAuthenticationInterface.
 *
 * This rule runs after ownership and amount checks (priority 45) to avoid
 * unnecessary SCA calls on requests that will fail for other reasons.
 *
 * Threshold is configurable per-currency via constructor injection.
 */
final class StepUpRequiredRule implements TransferRuleInterface
{
    /**
     * @param array<string, string> $thresholdByCurrency Currency-keyed thresholds, e.g. ['EUR' => '30.0000', 'USD' => '50.0000']
     * @param string $defaultThreshold Fallback threshold if currency not in map
     */
    public function __construct(
        private readonly StepUpAuthenticationInterface $stepUpAuth,
        private readonly RequestStack                  $requestStack,
        private readonly string                        $defaultThreshold = '500.0000',
        private readonly array                         $thresholdByCurrency = [],
    ) {}

    public function apply(TransferContext $context): void
    {
        $amount   = $context->getRequest()->amount;
        $currency = $context->getRequest()->currency;
        $threshold = $this->thresholdByCurrency[$currency] ?? $this->defaultThreshold;

        // Below threshold — no step-up required
        if (bccomp($amount, $threshold, 4) <= 0) {
            return;
        }

        // Extract step-up token from the request header
        $request = $this->requestStack->getCurrentRequest();
        $token   = $request?->headers->get('X-Step-Up-Token', '');

        if ($token === null || $token === '') {
            throw new StepUpRequiredException($currency, $threshold);
        }

        if (!$this->stepUpAuth->verify($context->getCallerApiKeyId(), $token)) {
            throw new StepUpRequiredException($currency, $threshold);
        }
    }

    public function getPriority(): int
    {
        return 45; // After daily limit check, before final execution
    }
}
