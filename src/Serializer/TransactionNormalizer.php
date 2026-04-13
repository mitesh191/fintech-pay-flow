<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Transaction;

/**
 * Single source of truth for Transaction → array serialization.
 *
 * Extracting serialization from controllers follows SRP and ensures
 * every endpoint (TransferController, ReversalController, AccountController
 * transaction sub-resource) emits the exact same wire format.
 *
 * Adding a new field (e.g. `exchange_rate`, `reversal_deadline`) requires
 * one edit here — not scattered across every controller.
 */
final class TransactionNormalizer
{
    public function normalize(Transaction $tx): array
    {
        return [
            'id'                      => $tx->getId(),
            'status'                  => $tx->getStatus()->value,
            'source_account_id'       => (string) $tx->getSourceAccount()->getId(),
            'destination_account_id'  => (string) $tx->getDestinationAccount()->getId(),
            'amount'                  => $tx->getAmount(),
            'fee_amount'              => $tx->getFeeAmount(),
            'currency'                => $tx->getCurrency(),
            'description'             => $tx->getDescription(),
            'original_transaction_id' => $tx->getOriginalTransaction()?->getId(),
            'failure_reason'          => $tx->getFailureReason(),
            'created_at'              => $tx->getCreatedAt()->format(\DateTimeInterface::RFC3339),
            'completed_at'            => $tx->getCompletedAt()?->format(\DateTimeInterface::RFC3339),
        ];
    }

    /**
     * Convenience normalizer for a compact list view (account transaction sub-resource).
     * Omit reversal/failure fields that are irrelevant in list context.
     */
    public function normalizeForList(Transaction $tx): array
    {
        return [
            'id'                     => $tx->getId(),
            'status'                 => $tx->getStatus()->value,
            'source_account_id'      => (string) $tx->getSourceAccount()->getId(),
            'destination_account_id' => (string) $tx->getDestinationAccount()->getId(),
            'amount'                 => $tx->getAmount(),
            'fee_amount'             => $tx->getFeeAmount(),
            'currency'               => $tx->getCurrency(),
            'description'            => $tx->getDescription(),
            'created_at'             => $tx->getCreatedAt()->format(\DateTimeInterface::RFC3339),
            'completed_at'           => $tx->getCompletedAt()?->format(\DateTimeInterface::RFC3339),
        ];
    }
}
