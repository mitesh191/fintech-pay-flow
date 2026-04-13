<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Transaction;

/**
 * Raised once a transfer has been committed to the database and the ledger
 * entries have been written.
 *
 * Downstream consumers:
 *   - Notification service  → push / email receipt
 *   - Fraud engine          → real-time velocity analysis
 *   - Analytics pipeline    → business intelligence dashboards
 *   - Reconciliation worker → cross-system balance verification
 */
final class TransferCompletedEvent implements DomainEventInterface
{
    private readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        private readonly string $transactionId,
        private readonly string $sourceAccountId,
        private readonly string $destinationAccountId,
        private readonly string $amount,
        private readonly string $feeAmount,
        private readonly string $currency,
        private readonly string $callerApiKeyId,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public static function fromTransaction(Transaction $tx, string $feeAmount, string $callerApiKeyId): self
    {
        return new self(
            transactionId:        $tx->getId(),
            sourceAccountId:      (string) $tx->getSourceAccount()->getId(),
            destinationAccountId: (string) $tx->getDestinationAccount()->getId(),
            amount:               $tx->getAmount(),
            feeAmount:            $feeAmount,
            currency:             $tx->getCurrency(),
            callerApiKeyId:       $callerApiKeyId,
            occurredAt:           $tx->getCreatedAt(),
        );
    }

    public function getEventType(): string
    {
        return 'transfer.completed';
    }

    public function getAggregateId(): string
    {
        return $this->transactionId;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toPayload(): array
    {
        return [
            'transaction_id'        => $this->transactionId,
            'source_account_id'     => $this->sourceAccountId,
            'destination_account_id'=> $this->destinationAccountId,
            'amount'                => $this->amount,
            'fee_amount'            => $this->feeAmount,
            'currency'              => $this->currency,
            'caller_api_key_id'     => $this->callerApiKeyId,
            'occurred_at'           => $this->occurredAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
