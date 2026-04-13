<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApiKeyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents an API client credential.
 *
 * Only the SHA-256 hash of the raw token is stored — the raw value is never
 * persisted and therefore cannot be recovered from a database breach.
 *
 * Issuance flow:
 *   1. Generate 32 cryptographically-random bytes: bin2hex(random_bytes(32))
 *   2. Hash the raw token: hash('sha256', $rawToken)
 *   3. Store the hash; return the raw token to the client once only.
 */
#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'api_keys')]
class ApiKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $name;

    /** SHA-256(raw_bearer_token) — constant-time comparison safe. */
    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $keyHash;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $rawToken)
    {
        $this->id        = Uuid::v7();
        $this->name      = $name;
        $this->keyHash   = self::hashToken($rawToken);
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * One-way SHA-256 hash.  Used by the authenticator to look up API keys
     * without ever storing the raw token.
     */
    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function revoke(): void
    {
        $this->active = false;
    }
}
