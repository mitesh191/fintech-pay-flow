<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLogEntry>
 */
final class AuditLogRepository extends ServiceEntityRepository implements AuditLogRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLogEntry::class);
    }

    public function save(AuditLogEntry $entry, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entry);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return AuditLogEntry[]
     */
    public function findByEntity(string $entityType, string $entityId, int $page = 1, int $limit = 50): array
    {
        return $this->createQueryBuilder('al')
            ->where('al.entityType = :type')
            ->andWhere('al.entityId = :id')
            ->setParameter('type', $entityType)
            ->setParameter('id', $entityId)
            ->orderBy('al.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AuditLogEntry[]
     */
    public function findByActor(string $actorId, int $page = 1, int $limit = 50): array
    {
        return $this->createQueryBuilder('al')
            ->where('al.actorId = :actorId')
            ->setParameter('actorId', $actorId)
            ->orderBy('al.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
