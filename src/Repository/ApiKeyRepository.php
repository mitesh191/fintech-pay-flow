<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiKey>
 */
final class ApiKeyRepository extends ServiceEntityRepository implements ApiKeyRepositoryInterface
{
    /** @var array<string, ApiKey|null> Per-request memo — avoids duplicate SQL for the same token. */
    private array $hashCache = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    /**
     * Used by the authenticator on every request — must be fast.
     * The UNIQUE index on key_hash ensures a single B-tree lookup.
     * Result is memoised for the lifetime of the PHP-FPM worker request
     * so the authenticator + user-provider never fire duplicate SQL.
     */
    public function findByHash(string $keyHash): ?ApiKey
    {
        if (array_key_exists($keyHash, $this->hashCache)) {
            return $this->hashCache[$keyHash];
        }

        $apiKey = $this->findOneBy(['keyHash' => $keyHash, 'active' => true]);
        $this->hashCache[$keyHash] = $apiKey;

        return $apiKey;
    }

    public function save(ApiKey $apiKey, bool $flush = false): void
    {
        $this->getEntityManager()->persist($apiKey);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
