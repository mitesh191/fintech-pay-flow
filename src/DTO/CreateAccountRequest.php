<?php

declare(strict_types=1);

namespace App\DTO;

use App\Validator\Iso4217Currency;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated input for account creation.
 */
final class CreateAccountRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'owner_name is required.')]
        #[Assert\Length(
            min: 1,
            max: 255,
            maxMessage: 'owner_name must not exceed 255 characters.'
        )]
        public readonly string $ownerName,

        #[Assert\NotBlank(message: 'currency is required.')]
        #[Assert\Length(exactly: 3, exactMessage: 'currency must be a 3-letter ISO 4217 code.')]
        #[Assert\Regex(pattern: '/^[A-Z]{3}$/', message: 'currency must contain only uppercase letters.')]
        #[Iso4217Currency]
        public readonly string $currency,

        #[Assert\GreaterThanOrEqual(value: '0', message: 'initial_balance must be zero or positive.')]
        #[Assert\Regex(
            pattern: '/^\d+(\.\d{1,4})?$/',
            message: 'initial_balance must be a non-negative decimal.'
        )]
        public readonly string $initialBalance = '0.0000',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ownerName:      trim((string) ($data['owner_name'] ?? '')),
            currency:       strtoupper(trim((string) ($data['currency'] ?? ''))),
            initialBalance: trim((string) ($data['initial_balance'] ?? '0.0000')),
        );
    }
}
