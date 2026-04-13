<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Account;

/**
 * Single source of truth for Account → array serialization.
 *
 * Exposes balance as a string (4dp) from the Money VO's amount accessor,
 * ensuring callers always receive a normalised decimal representation.
 */
final class AccountNormalizer
{
    public function normalize(Account $account): array
    {
        return [
            'id'             => (string) $account->getId(),
            'account_number' => $account->getAccountNumber(),
            'owner_name'     => $account->getOwnerName(),
            'currency'       => $account->getCurrency(),
            'balance'        => $account->getBalanceMoney()->getAmount(),
            'active'         => $account->isActive(),
            'created_at'     => $account->getCreatedAt()->format(\DateTimeInterface::RFC3339),
            'updated_at'     => $account->getUpdatedAt()->format(\DateTimeInterface::RFC3339),
        ];
    }
}
