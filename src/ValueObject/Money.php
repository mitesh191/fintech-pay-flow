<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Immutable fintech Money value object.
 *
 * DDD / Fintech rationale
 * ──────────────────────────────────────────────────────────────────────
 * Treating amount as a bare string (or float!) throughout a payment
 * system is the root cause of:
 *   • Silent currency mix-ups (adding USD to EUR without crashing)
 *   • Float precision errors (0.1 + 0.2 ≠ 0.3 in IEEE-754)
 *   • Missing invariant enforcement (negative amounts, empty currency)
 *
 * Money encapsulates amount + currency as one indivisible concept.
 * All arithmetic uses bcmath — the only safe choice for financial figures.
 *
 * Scale: this object is cheap to create (no DB / IO) and fully
 * threadsafe because it is immutable.  Every arithmetic method
 * returns a new instance.
 */
final class Money
{
    private const SCALE = 4;

    private function __construct(
        private readonly string $amount,
        private readonly string $currency,
    ) {}

    // ── Construction ──────────────────────────────────────────────────────────

    /**
     * Named constructor — normalises amount to 4 decimal places and
     * uppercases the currency code.
     *
     * @throws \InvalidArgumentException on negative amount or empty currency
     */
    public static function of(string $amount, string $currency): self
    {
        $currency = strtoupper(trim($currency));

        if ($currency === '' || strlen($currency) !== 3) {
            throw new \InvalidArgumentException(
                sprintf('Currency must be a 3-letter ISO 4217 code, got "%s".', $currency)
            );
        }

        // Normalise to 4dp and validate non-negative
        $normalised = bcadd($amount, '0', self::SCALE);

        if (bccomp($normalised, '0', self::SCALE) < 0) {
            throw new \InvalidArgumentException(
                sprintf('Money amount must be non-negative, got "%s".', $amount)
            );
        }

        return new self($normalised, $currency);
    }

    /** Zero amount for a given currency (useful as neutral element for fold/reduce). */
    public static function zero(string $currency): self
    {
        return self::of('0', $currency);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    // ── Currency guard ────────────────────────────────────────────────────────

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    // ── Arithmetic (all return new immutable instances) ───────────────────────

    /**
     * @throws \DomainException when currencies differ
     */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            bcadd($this->amount, $other->amount, self::SCALE),
            $this->currency,
        );
    }

    /**
     * @throws \DomainException when currencies differ or result would be negative
     */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        $result = bcsub($this->amount, $other->amount, self::SCALE);

        if (bccomp($result, '0', self::SCALE) < 0) {
            throw new \DomainException(
                sprintf(
                    'Subtraction would produce negative Money: %s - %s %s.',
                    $this->amount,
                    $other->amount,
                    $this->currency,
                )
            );
        }

        return new self($result, $this->currency);
    }

    // ── Comparison ────────────────────────────────────────────────────────────

    /** Returns -1, 0, or 1 (same semantics as bccomp / spaceship operator). */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, self::SCALE);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isEqualTo(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    // ── Display ───────────────────────────────────────────────────────────────

    public function __toString(): string
    {
        return "{$this->amount} {$this->currency}";
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function assertSameCurrency(self $other): void
    {
        if (!$this->isSameCurrency($other)) {
            throw new \DomainException(
                sprintf(
                    'Currency mismatch: cannot operate on %s and %s.',
                    $this->currency,
                    $other->currency,
                )
            );
        }
    }
}
