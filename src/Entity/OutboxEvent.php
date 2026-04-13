<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OutboxStatus;
use App\Repository\OutboxEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Transactional Outbox pattern — reliable event publishing at scale.
 *
 * The Problem
 * ────────────
 * Naively publishing to Kafka/RabbitMQ inside a DB transaction creates a
 * two-phase commit problem: the broker and the DB can disagree if one fails.
 * At 2 M tx/s this discrepancy accumulates into large reconciliation debt.
 *
 * The Solution
 * ─────────────
 * Write the OutboxEvent row inside the SAME database transaction as the
 * transfer (atomic).  A background relay process (ProcessOutboxCommand)
 * reads PENDING rows, publishes to the broker with at-least-once delivery,
 * and marks rows PUBLISHED.
 *
 * Consumers must be idempotent (they deduplicate by aggregate_id + event_type).
 *
 * Scale notes
 * ────────────
 * • idx_oe_status_scheduled covers the SELECT … WHERE status = 'pending'
 *   ORDER BY scheduled_at query run by ProcessOutboxCommand.
 * • Max-retries prevents poison pills from blocking the relay indefinitely.
 * • The table should be partitioned by created_at (monthly) and purged of
 *   PUBLISHED rows older than 30 days to keep table size bounded.
 */
#[ORM\Entity(repositoryClass: OutboxEventRepository::class)]
#[ORM\Table(name: 'outbox_events')]
#[ORM\Index(name: 'idx_oe_status_scheduled', columns: ['status', 'scheduled_at'])]
#[ORM\Index(name: 'idx_oe_aggregate',        columns: ['aggregate_id', 'event_type'])]
class OutboxEvent
{
    private const DEFAULT_MAX_RETRIES = 5;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    /** e.g. 'transfer.completed', 'account.deactivated' */
    #[ORM\Column(length: 100)]
    private string $eventType;

    /** UUID of the root aggregate (Transaction, Account…). */
    #[ORM\Column(length: 36)]
    private string $aggregateId;

    /** Serialised event payload consumed by subscribers. */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(enumType: OutboxStatus::class)]
    private OutboxStatus $status;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $retryCount = 0;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $maxRetries;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** When the relay worker should next attempt to publish this event. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $scheduledAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $eventType,
        string $aggregateId,
        array  $payload,
        int    $maxRetries  = self::DEFAULT_MAX_RETRIES,
        ?\DateTimeImmutable $scheduledAt = null,
    ) {
        $this->id          = Uuid::v7();
        $this->eventType   = $eventType;
        $this->aggregateId = $aggregateId;
        $this->payload     = $payload;
        $this->maxRetries  = $maxRetries;
        $this->status      = OutboxStatus::Pending;
        $this->createdAt   = new \DateTimeImmutable();
        $this->scheduledAt = $scheduledAt ?? $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): OutboxStatus
    {
        return $this->status;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getScheduledAt(): \DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    // ── State transitions ────────────────────────────────────────────────────

    public function markProcessing(): void
    {
        $this->status = OutboxStatus::Processing;
    }

    public function markPublished(): void
    {
        $this->status      = OutboxStatus::Published;
        $this->processedAt = new \DateTimeImmutable();
    }

    /**
     * Record a delivery failure and schedule an exponential back-off retry.
     * After maxRetries the event is permanently marked FAILED.
     */
    public function recordFailure(string $error): void
    {
        $this->lastError  = $error;
        $this->retryCount++;

        if ($this->retryCount >= $this->maxRetries) {
            $this->status      = OutboxStatus::Failed;
            $this->processedAt = new \DateTimeImmutable();
        } else {
            // Exponential back-off: 2^retryCount seconds (max 15 min)
            $delay             = min(2 ** $this->retryCount, 900);
            $this->scheduledAt = new \DateTimeImmutable("+{$delay} seconds");
            $this->status      = OutboxStatus::Pending;
        }
    }

    public function isRetryable(): bool
    {
        return $this->retryCount < $this->maxRetries
            && $this->status !== OutboxStatus::Published;
    }
}
