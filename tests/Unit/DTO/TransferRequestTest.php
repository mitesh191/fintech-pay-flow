<?php

declare(strict_types=1);

namespace App\Tests\Unit\DTO;

use App\DTO\TransferRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TransferRequest DTO construction and fromArray factory.
 *
 * SOLID/SRP: validate one behaviour per test.
 * DDD: TransferRequest is the command object crossing the anti-corruption layer.
 */
final class TransferRequestTest extends TestCase
{
    public function test_from_array_maps_all_fields(): void
    {
        $dto = TransferRequest::fromArray([
            'source_account_id'      => 'uuid-src',
            'destination_account_id' => 'uuid-dst',
            'amount'                 => '100.50',
            'currency'               => 'USD',
            'idempotency_key'        => 'key-abc',
            'description'            => 'Test transfer',
        ]);

        $this->assertSame('uuid-src',      $dto->sourceAccountId);
        $this->assertSame('uuid-dst',      $dto->destinationAccountId);
        $this->assertSame('100.50',        $dto->amount);
        $this->assertSame('USD',           $dto->currency);
        $this->assertSame('key-abc',       $dto->idempotencyKey);
        $this->assertSame('Test transfer', $dto->description);
    }

    public function test_from_array_uppercases_currency(): void
    {
        $dto = TransferRequest::fromArray([
            'source_account_id' => 'a', 'destination_account_id' => 'b',
            'amount' => '1', 'currency' => 'eur', 'idempotency_key' => 'k',
        ]);

        $this->assertSame('EUR', $dto->currency);
    }

    public function test_from_array_trims_currency_whitespace(): void
    {
        $dto = TransferRequest::fromArray([
            'source_account_id' => 'a', 'destination_account_id' => 'b',
            'amount' => '1', 'currency' => ' GBP ', 'idempotency_key' => 'k',
        ]);

        $this->assertSame('GBP', $dto->currency);
    }

    public function test_from_array_description_defaults_to_null(): void
    {
        $dto = TransferRequest::fromArray([
            'source_account_id' => 'a', 'destination_account_id' => 'b',
            'amount' => '1', 'currency' => 'USD', 'idempotency_key' => 'k',
        ]);

        $this->assertNull($dto->description);
    }

    public function test_from_array_casts_missing_fields_to_empty_string(): void
    {
        $dto = TransferRequest::fromArray([]);

        $this->assertSame('', $dto->sourceAccountId);
        $this->assertSame('', $dto->destinationAccountId);
        $this->assertSame('', $dto->amount);
        $this->assertSame('', $dto->currency);
        $this->assertSame('', $dto->idempotencyKey);
        $this->assertNull($dto->description);
    }

    public function test_all_properties_are_readonly(): void
    {
        $dto        = new TransferRequest('a', 'b', '10', 'USD', 'k');
        $reflection = new \ReflectionClass($dto);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('Property $%s must be readonly.', $property->getName()),
            );
        }
    }

    public function test_description_defaults_to_null_in_constructor(): void
    {
        $dto = new TransferRequest('a', 'b', '10', 'USD', 'k');

        $this->assertNull($dto->description);
    }
}
