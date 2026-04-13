<?php

declare(strict_types=1);

namespace App\Tests\Unit\DTO;

use App\DTO\TransferRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Constraint validation tests for TransferRequest.
 *
 * Uses a real Symfony Validator (no Kernel) to verify every #[Assert\*]
 * attribute is enforced correctly.  Tests the anti-corruption layer
 * gate that keeps invalid data out of the domain.
 */
final class TransferRequestConstraintTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    private function valid(array $overrides = []): TransferRequest
    {
        return new TransferRequest(
            sourceAccountId:      $overrides['sourceAccountId']      ?? (string) Uuid::v7(),
            destinationAccountId: $overrides['destinationAccountId'] ?? (string) Uuid::v7(),
            amount:               $overrides['amount']               ?? '100.00',
            currency:             $overrides['currency']             ?? 'USD',
            idempotencyKey:       $overrides['idempotencyKey']       ?? bin2hex(random_bytes(16)),
            description:          array_key_exists('description', $overrides) ? $overrides['description'] : null,
        );
    }

    private function hasViolations(TransferRequest $dto): bool
    {
        return count($this->validator->validate($dto)) > 0;
    }

    private function violationCount(TransferRequest $dto): int
    {
        return count($this->validator->validate($dto));
    }

    // ─── Baseline ─────────────────────────────────────────────────────────────

    public function test_fully_valid_dto_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid()));
    }

    // ─── sourceAccountId ──────────────────────────────────────────────────────

    public function test_blank_source_account_id_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['sourceAccountId' => ''])));
    }

    public function test_non_uuid_source_account_id_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['sourceAccountId' => 'not-a-uuid'])));
    }

    public function test_sql_injection_in_source_account_id_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['sourceAccountId' => "'; DROP TABLE accounts; --"])));
    }

    // ─── destinationAccountId ─────────────────────────────────────────────────

    public function test_blank_destination_account_id_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['destinationAccountId' => ''])));
    }

    public function test_non_uuid_destination_account_id_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['destinationAccountId' => 'foo-bar-baz'])));
    }

    // ─── amount ───────────────────────────────────────────────────────────────

    public function test_blank_amount_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['amount' => ''])));
    }

    public function test_zero_amount_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['amount' => '0'])));
    }

    public function test_zero_with_decimals_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['amount' => '0.0000'])));
    }

    public function test_negative_amount_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['amount' => '-10.00'])));
    }

    public function test_non_numeric_amount_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['amount' => 'hundred'])));
    }

    public function test_five_decimal_places_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['amount' => '1.12345'])));
    }

    public function test_one_decimal_place_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['amount' => '100.5'])));
    }

    public function test_four_decimal_places_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['amount' => '0.0001'])));
    }

    public function test_whole_number_amount_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['amount' => '1000'])));
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
        $this->assertTrue($this->hasViolations($this->valid(['currency' => 'USDD'])));
    }

    public function test_lowercase_currency_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['currency' => 'usd'])));
    }

    public function test_numeric_currency_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['currency' => '123'])));
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
        yield 'JPY' => ['JPY'];
        yield 'BRL' => ['BRL'];
        yield 'CHF' => ['CHF'];
    }

    // ─── idempotencyKey ───────────────────────────────────────────────────────

    public function test_blank_idempotency_key_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['idempotencyKey' => ''])));
    }

    public function test_idempotency_key_of_256_chars_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['idempotencyKey' => str_repeat('x', 256)])));
    }

    public function test_idempotency_key_of_255_chars_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['idempotencyKey' => str_repeat('x', 255)])));
    }

    public function test_idempotency_key_of_one_char_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['idempotencyKey' => 'x'])));
    }

    // ─── description ──────────────────────────────────────────────────────────

    public function test_null_description_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['description' => null])));
    }

    public function test_description_of_501_chars_fails(): void
    {
        $this->assertTrue($this->hasViolations($this->valid(['description' => str_repeat('x', 501)])));
    }

    public function test_description_of_500_chars_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['description' => str_repeat('x', 500)])));
    }

    public function test_empty_string_description_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['description' => ''])));
    }

    public function test_unicode_description_passes(): void
    {
        $this->assertSame(0, $this->violationCount($this->valid(['description' => 'Pagamento — Março 2026'])));
    }
}
