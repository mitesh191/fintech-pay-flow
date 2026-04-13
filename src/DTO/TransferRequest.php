<?php

declare(strict_types=1);

namespace App\DTO;

use App\Validator\Iso4217Currency;
use Symfony\Component\Validator\Constraints as Assert;

final class TransferRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public readonly string $sourceAccountId,

        #[Assert\NotBlank]
        #[Assert\Uuid]
        public readonly string $destinationAccountId,

        #[Assert\NotBlank]
        #[Assert\Positive]
        #[Assert\Regex(pattern: '/^\d+(\.\d{1,4})?$/', message: 'amount must be a positive decimal.')]
        public readonly string $amount,

        #[Assert\NotBlank]
        #[Assert\Length(exactly: 3)]
        #[Assert\Regex(pattern: '/^[A-Z]{3}$/')]
        #[Iso4217Currency]
        public readonly string $currency,

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly string $idempotencyKey,

        #[Assert\Length(max: 500)]
        public readonly ?string $description = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceAccountId:      (string) ($data['source_account_id'] ?? ''),
            destinationAccountId: (string) ($data['destination_account_id'] ?? ''),
            amount:               (string) ($data['amount'] ?? ''),
            currency:             strtoupper(trim((string) ($data['currency'] ?? ''))),
            idempotencyKey:       (string) ($data['idempotency_key'] ?? ''),
            description:          isset($data['description']) ? (string) $data['description'] : null,
        );
    }
}
