<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OutboxEvent;
use App\Enum\OutboxStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OutboxEvent>
 */
final class OutboxEventRepository extends ServiceEntityRepository implements OutboxEventRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OutboxEvent::class);
    }

    public function save(OutboxEvent $event, bool $flush = false): void
    {
        $this->getEntityManager()->persist($event);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function saveAll(array $events, bool $flush = false): void
    {
        $em = $this->getEntityManager();
        foreach ($events as $event) {
            $em->persist($event);
        }

        if ($flush) {
            $em->flush();
        }
    }

    /**
     * @return OutboxEvent[]
     */
    public function findDueForProcessing(int $batchSize = 100): array
    {
        return $this->createQueryBuilder('oe')
            ->where('oe.status = :pending')
            ->andWhere('oe.scheduledAt <= :now')
            ->setParameter('pending', OutboxStatus::Pending->value)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('oe.scheduledAt', 'ASC')
            ->setMaxResults($batchSize)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return OutboxEvent[]
     */
    public function findByStatus(OutboxStatus $status, int $limit = 100): array
    {
        return $this->createQueryBuilder('oe')
            ->where('oe.status = :status')
            ->setParameter('status', $status->value)
            ->orderBy('oe.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(OutboxStatus $status): int
    {
        return (int) $this->createQueryBuilder('oe')
            ->select('COUNT(oe.id)')
            ->where('oe.status = :status')
            ->setParameter('status', $status->value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
