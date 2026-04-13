<?php

declare(strict_types=1);

namespace App\Tests\Unit\DTO;

use App\DTO\CreateAccountRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CreateAccountRequest DTO construction and fromArray.
 */
final class CreateAccountRequestTest extends TestCase
{
    public function test_from_array_maps_all_fields(): void
    {
        $dto = CreateAccountRequest::fromArray([
            'owner_name'      => 'Alice Smith',
            'currency'        => 'USD',
            'initial_balance' => '500.0000',
        ]);

        $this->assertSame('Alice Smith', $dto->ownerName);
        $this->assertSame('USD',         $dto->currency);
        $this->assertSame('500.0000',    $dto->initialBalance);
    }

    public function test_from_array_uppercases_currency(): void
    {
        $dto = CreateAccountRequest::fromArray(['owner_name' => 'Bob', 'currency' => 'gbp']);

        $this->assertSame('GBP', $dto->currency);
    }

    public function test_from_array_trims_owner_name(): void
    {
        $dto = CreateAccountRequest::fromArray(['owner_name' => '  Dan  ', 'currency' => 'USD']);

        $this->assertSame('Dan', $dto->ownerName);
    }

    public function test_from_array_trims_currency_whitespace(): void
    {
        $dto = CreateAccountRequest::fromArray(['owner_name' => 'Eve', 'currency' => ' EUR ']);

        $this->assertSame('EUR', $dto->currency);
    }

    public function test_from_array_defaults_initial_balance_to_zero(): void
    {
        $dto = CreateAccountRequest::fromArray(['owner_name' => 'Carol', 'currency' => 'USD']);

        $this->assertSame('0.0000', $dto->initialBalance);
    }

    public function test_from_array_trims_initial_balance_whitespace(): void
    {
        $dto = CreateAccountRequest::fromArray([
            'owner_name' => 'Frank', 'currency' => 'USD', 'initial_balance' => ' 100.50 ',
        ]);

        $this->assertSame('100.50', $dto->initialBalance);
    }

    public function test_all_properties_are_readonly(): void
    {
        $dto        = new CreateAccountRequest(ownerName: 'Eve', currency: 'USD');
        $reflection = new \ReflectionClass($dto);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('Property $%s must be readonly.', $property->getName()),
            );
        }
    }

    public function test_initial_balance_defaults_to_zero_in_constructor(): void
    {
        $dto = new CreateAccountRequest(ownerName: 'Grace', currency: 'USD');

        $this->assertSame('0.0000', $dto->initialBalance);
    }
}
