<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Transaction;

final class TransferReversedEvent implements DomainEventInterface
{
    private readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        private readonly string $reversalTransactionId,
        private readonly string $originalTransactionId,
        private readonly string $sourceAccountId,
        private readonly string $destinationAccountId,
        private readonly string $amount,
        private readonly string $currency,
        private readonly string $callerApiKeyId,
        private readonly string $reason,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public static function fromTransactions(
        Transaction $reversal,
        Transaction $original,
        string $callerApiKeyId,
        string $reason,
    ): self {
        return new self(
            reversalTransactionId: $reversal->getId(),
            originalTransactionId: $original->getId(),
            sourceAccountId:       (string) $original->getSourceAccount()->getId(),
            destinationAccountId:  (string) $original->getDestinationAccount()->getId(),
            amount:                $original->getAmount(),
            currency:              $original->getCurrency(),
            callerApiKeyId:        $callerApiKeyId,
            reason:                $reason,
            occurredAt:            $reversal->getCreatedAt(),
        );
    }

    public function getEventType(): string
    {
        return 'transfer.reversed';
    }

    public function getAggregateId(): string
    {
        return $this->reversalTransactionId;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toPayload(): array
    {
        return [
            'reversal_transaction_id' => $this->reversalTransactionId,
            'original_transaction_id' => $this->originalTransactionId,
            'source_account_id'       => $this->sourceAccountId,
            'destination_account_id'  => $this->destinationAccountId,
            'amount'                  => $this->amount,
            'currency'                => $this->currency,
            'caller_api_key_id'       => $this->callerApiKeyId,
            'reason'                  => $this->reason,
            'occurred_at'             => $this->occurredAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
