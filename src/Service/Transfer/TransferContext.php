<?php

declare(strict_types=1);

namespace App\Service\Transfer;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\ValueObject\Money;

/**
 * Immutable value-object threaded through the transfer rule chain.
 *
 * Built in two phases:
 *   1. Pre-lock  : request + caller metadata (`create()`).
 *   2. Post-lock : accounts added via `withAccounts()`.
 *   3. Post-fee  : fee injected via `withFeeAmount()`.
 *
 * Keeping a plain object (vs. modifying the DTO) lets rules read
 * pre/post-fee amounts without mutating the original request.
 *
 * Money value objects
 * ──────────────────────────────────────────────────────────────────────
 * Amount and currency are exposed as a single `Money` value object —
 * the canonical fintech DDD pattern that prevents silent currency
 * mix-ups and ensures bcmath precision at every boundary.
 */
final class TransferContext
{
    /** Net fee in the same currency as the principal.  Defaults to zero. */
    private string $feeAmount = '0.0000';

    private function __construct(
        private readonly TransferRequest $request,
        private readonly string          $callerApiKeyId,
        private readonly string          $effectiveKey,
        private readonly ?Account        $sourceAccount,
        private readonly ?Account        $destinationAccount,
    ) {}

    // ── Property accessors (encapsulated; never expose internal state directly) ─

    public function getRequest(): TransferRequest
    {
        return $this->request;
    }

    public function getCallerApiKeyId(): string
    {
        return $this->callerApiKeyId;
    }

    public function getEffectiveKey(): string
    {
        return $this->effectiveKey;
    }

    public function getSourceAccount(): ?Account
    {
        return $this->sourceAccount;
    }

    public function getDestinationAccount(): ?Account
    {
        return $this->destinationAccount;
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    public static function create(
        TransferRequest $request,
        string          $callerApiKeyId,
        string          $effectiveKey,
    ): self {
        return new self($request, $callerApiKeyId, $effectiveKey, null, null);
    }

    /** Returns a new context with the pair of locked accounts attached. */
    public function withAccounts(Account $source, Account $destination): self
    {
        $new = new self(
            $this->request,
            $this->callerApiKeyId,
            $this->effectiveKey,
            $source,
            $destination,
        );
        $new->feeAmount = $this->feeAmount;

        return $new;
    }

    /**
     * Returns a new context with the calculated fee applied.
     *
     * OCP: `TransferService` calls this after `FeeCalculatorInterface::calculate()`
     * so the context is always fully formed before the rule chain runs.
     */
    public function withFeeAmount(string $feeAmount): self
    {
        $new            = clone $this;
        $new->feeAmount = bcadd($feeAmount, '0', 4); // normalise to 4dp

        return $new;
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    /** Raw fee string (4dp) — used by callers that need a string for bcmath. */
    public function getFeeAmount(): string
    {
        return $this->feeAmount;
    }

    /**
     * Transfer principal as an immutable Money value object.
     *
     * Amount + currency are inseparable in fintech — exposing them as
     * one Money value makes currency-mismatch bugs a compile-time impossibility
     * rather than a runtime surprise.
     */
    public function getPrincipal(): Money
    {
        return Money::of($this->request->amount, $this->request->currency);
    }

    /**
     * Processing fee as a Money value object (same currency as principal).
     */
    public function getFee(): Money
    {
        return Money::of($this->feeAmount, $this->request->currency);
    }

    /**
     * Total amount to debit from the source account (principal + fee).
     *
     * Returned as Money to guarantee the arithmetic is currency-safe.
     */
    public function getTotalDebit(): Money
    {
        return $this->getPrincipal()->add($this->getFee());
    }
}

