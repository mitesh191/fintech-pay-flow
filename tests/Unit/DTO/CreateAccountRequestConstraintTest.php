<?php

declare(strict_types=1);

namespace App\Tests\Unit\DTO;

use App\DTO\CreateAccountRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Constraint validation tests for CreateAccountRequest.
 *
 * Uses a real Symfony Validator to verify every #[Assert\*] attribute.
 * No Kernel required — tests the DTO boundary in pure isolation.
 */
final class CreateAccountRequestConstraintTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    private function valid(array $overrides = []): CreateAccountRequest
    {
        return new CreateAccountRequest(
            ownerName:      $overrides['ownerName']      ?? 'Jane Doe',
            currency:       $overrides['currency']       ?? 'USD',
            initialBalance: $overrides['initialBalance'] ?? '0.0000',
        );
    }

    private function hasViolations(CreateAccountRequest $dto): bool
    {
        return count($this->validator->validate($dto)) > 0;
    }

    private function violationCount(CreateAccountRequest $dto): int
    {
        return count($this->validator->validate($dto));
    }

    // ─── Baseline ─────────────────────────────────────────────────────────────

    public function test_fully_valid_dto_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid()));
    }

    // ─── ownerName ────────────────────────────────────────────────────────────

    public function test_blank_owner_name_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['ownerName' => ''])));
    }

    public function test_owner_name_of_256_chars_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['ownerName' => str_repeat('A', 256)])));
    }

    public function test_owner_name_of_255_chars_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['ownerName' => str_repeat('A', 255)])));
    }

    public function test_unicode_owner_name_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['ownerName' => 'Héctor Martínez-González'])));
    }

    public function test_owner_name_with_apostrophe_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['ownerName' => "O'Brien-Smith"])));
    }

    // ─── currency ─────────────────────────────────────────────────────────────

    public function test_blank_currency_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['currency' => ''])));
    }

    public function test_two_letter_currency_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['currency' => 'US'])));
    }

    public function test_four_letter_currency_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['currency' => 'EURO'])));
    }

    public function test_lowercase_currency_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['currency' => 'eur'])));
    }

    public function test_mixed_case_currency_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['currency' => 'Usd'])));
    }

    #[DataProvider('validCurrencies')]
    public function test_valid_iso4217_currency_passes(string $code): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['currency' => $code])));
    }

    public static function validCurrencies(): iterable
    {
        yield 'USD' => ['USD'];
        yield 'EUR' => ['EUR'];
        yield 'GBP' => ['GBP'];
        yield 'BRL' => ['BRL'];
        yield 'JPY' => ['JPY'];
        yield 'CHF' => ['CHF'];
        yield 'AUD' => ['AUD'];
        yield 'SGD' => ['SGD'];
    }

    // ─── initialBalance ───────────────────────────────────────────────────────

    public function test_zero_initial_balance_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['initialBalance' => '0.0000'])));
    }

    public function test_zero_without_decimals_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['initialBalance' => '0'])));
    }

    public function test_positive_balance_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['initialBalance' => '1000.00'])));
    }

    public function test_negative_balance_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['initialBalance' => '-100.00'])));
    }

    public function test_five_decimal_places_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['initialBalance' => '1.23456'])));
    }

    public function test_four_decimal_places_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['initialBalance' => '1.2345'])));
    }

    public function test_alpha_initial_balance_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['initialBalance' => 'abc'])));
    }

    public function test_whitespace_initial_balance_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['initialBalance' => ' 100.00'])));
    }
}
