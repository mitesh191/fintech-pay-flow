<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Immutable, append-only audit trail.
 *
 * Regulatory requirement (PCI-DSS req 10, FFIEC, ISO 27001 A.12.4):
 * every financial action must be traceable to a specific actor, timestamp,
 * and payload snapshot.  This table is NEVER updated or deleted at the
 * application layer — compliant archival moves rows to cold storage after
 * a retention period (e.g. 7 years for financial records).
 *
 * idx_al_entity_created supports "show all actions on account X" queries.
 * idx_al_actor_created  supports "show all actions by API key Y" queries.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log_entries')]
#[ORM\Index(name: 'idx_al_entity_created', columns: ['entity_type', 'entity_id', 'created_at'])]
#[ORM\Index(name: 'idx_al_actor_created',  columns: ['actor_id', 'created_at'])]
class AuditLogEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    /** e.g. 'account', 'transfer', 'api_key' */
    #[ORM\Column(length: 50)]
    private string $entityType;

    /** UUID of the entity being acted upon. */
    #[ORM\Column(length: 36)]
    private string $entityId;

    /** e.g. 'transfer.initiated', 'account.deactivated', 'api_key.revoked' */
    #[ORM\Column(length: 100)]
    private string $action;

    /** UUID of the API key that triggered this action. */
    #[ORM\Column(length: 36)]
    private string $actorId;

    /** Client IP address at time of request. Null when called from CLI. */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress;

    /** Snapshot of the relevant data at time of action (for forensic replay). */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string  $entityType,
        string  $entityId,
        string  $action,
        string  $actorId,
        array   $payload    = [],
        ?string $ipAddress  = null,
    ) {
        $this->id         = Uuid::v7();
        $this->entityType = $entityType;
        $this->entityId   = $entityId;
        $this->action     = $action;
        $this->actorId    = $actorId;
        $this->payload    = $payload;
        $this->ipAddress  = $ipAddress;
        $this->createdAt  = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getActorId(): string
    {
        return $this->actorId;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
