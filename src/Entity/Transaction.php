<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TransactionStatus;
use App\Repository\TransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'transactions')]
class Transaction
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $idempotencyKey;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $sourceAccount;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $destinationAccount;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 4)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: TransactionStatus::class)]
    private TransactionStatus $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failureReason = null;

    /**
     * Processing fee charged on this transfer (0.0000 = free).
     * Stored alongside the principal so statements are complete.
     */
    #[ORM\Column(type: 'decimal', precision: 20, scale: 4, options: ['default' => '0.0000'])]
    private string $feeAmount = '0.0000';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /**
     * For reversal transactions: references the original transaction being reversed.
     * Null for normal transfers.
     */
    #[ORM\ManyToOne(targetEntity: Transaction::class)]
    #[ORM\JoinColumn(name: 'original_transaction_id', nullable: true, onDelete: 'SET NULL')]
    private ?Transaction $originalTransaction = null;

    public function __construct(
        string $idempotencyKey,
        Account $sourceAccount,
        Account $destinationAccount,
        string $amount,
        string $currency,
        ?string $description = null,
        string $feeAmount = '0.0000',
    ) {
        $this->id                 = Uuid::v7();
        $this->idempotencyKey     = $idempotencyKey;
        $this->sourceAccount      = $sourceAccount;
        $this->destinationAccount = $destinationAccount;
        $this->amount             = $amount;
        $this->currency           = $currency;
        $this->description        = $description;
        $this->feeAmount          = bcadd($feeAmount, '0', 4);
        $this->status             = TransactionStatus::Pending;
        $this->createdAt          = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id->toRfc4122();
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getSourceAccount(): Account
    {
        return $this->sourceAccount;
    }

    public function getDestinationAccount(): Account
    {
        return $this->destinationAccount;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getStatus(): TransactionStatus
    {
        return $this->status;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getFeeAmount(): string
    {
        return $this->feeAmount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getOriginalTransaction(): ?Transaction
    {
        return $this->originalTransaction;
    }

    public function setOriginalTransaction(Transaction $original): void
    {
        $this->originalTransaction = $original;
    }

    public function markCompleted(): void
    {
        if ($this->status !== TransactionStatus::Pending) {
            throw new \DomainException(
                sprintf('Cannot complete a transaction that is already %s.', $this->status->value)
            );
        }

        $this->status      = TransactionStatus::Completed;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $reason = ''): void
    {
        if ($this->status !== TransactionStatus::Pending) {
            throw new \DomainException(
                sprintf('Cannot fail a transaction that is already %s.', $this->status->value)
            );
        }

        $this->status        = TransactionStatus::Failed;
        $this->failureReason = $reason ?: null;
        $this->completedAt   = new \DateTimeImmutable();
    }

    public function markReversed(): void
    {
        if ($this->status !== TransactionStatus::Completed) {
            throw new \DomainException(
                sprintf('Cannot reverse a transaction that is %s.', $this->status->value)
            );
        }

        $this->status      = TransactionStatus::Reversed;
        $this->completedAt = new \DateTimeImmutable();
    }
}
