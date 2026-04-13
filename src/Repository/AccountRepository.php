<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

final class AccountRepository extends ServiceEntityRepository implements AccountRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    public function save(Account $account, bool $flush = false): void
    {
        $this->getEntityManager()->persist($account);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByIdWithLock(Uuid $id, bool $lock = true): ?Account
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid');

        // Eagerly fetch the owning ApiKey when no row-level lock is needed so
        // ownership checks in controllers never trigger a second round-trip.
        // PESSIMISTIC_WRITE cannot be combined with JOIN FETCH in most drivers,
        // so the lock path falls back to a single-entity query (Doctrine's ghost
        // object still returns the PK without an extra query in that case).
        if (!$lock) {
            $qb->addSelect('ak')
               ->leftJoin('a.apiKey', 'ak');
        }

        $query = $qb->getQuery();

        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        return $query->getOneOrNullResult();
    }

    /**
     * Locks both accounts in a deterministic order (lower UUID first) to prevent deadlocks.
     *
     * @return Account[]
     */
    public function findPairWithLock(string $sourceId, string $destinationId): array
    {
        // Do NOT filter by active status here. Load both accounts under
        // pessimistic lock regardless of active flag. AccountActiveRule will
        // check the active flag after the lock is held, closing the race window
        // where a concurrent deactivation could slip through unserialized.
        return $this->createQueryBuilder('a')
            ->addSelect('ak')
            ->leftJoin('a.apiKey', 'ak')
            ->where('a.id = :sourceId OR a.id = :destinationId')
            ->setParameter('sourceId', Uuid::fromString($sourceId), 'uuid')
            ->setParameter('destinationId', Uuid::fromString($destinationId), 'uuid')
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    /**
     * Returns only active accounts — deactivated accounts are considered closed
     * and must not be visible to end-users or API consumers.
     * When $apiKeyId is supplied only accounts owned by that API key are returned.
     *
     * @return Account[]
     */
    public function findPaginated(int $page = 1, int $limit = 20, ?Uuid $apiKeyId = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.active = true')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($apiKeyId !== null) {
            $qb->andWhere('a.apiKey = :apiKeyId')
               ->setParameter('apiKeyId', $apiKeyId, 'uuid');
        }

        return $qb->getQuery()->getResult();
    }

    public function countAll(?Uuid $apiKeyId = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.active = true');

        if ($apiKeyId !== null) {
            $qb->andWhere('a.apiKey = :apiKeyId')
               ->setParameter('apiKeyId', $apiKeyId, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
