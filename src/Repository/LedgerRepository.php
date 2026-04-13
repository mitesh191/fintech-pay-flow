<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LedgerEntry;
use App\Entity\Transaction;
use App\Enum\LedgerDirection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LedgerEntry>
 */
final class LedgerRepository extends ServiceEntityRepository implements LedgerRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LedgerEntry::class);
    }

    public function save(LedgerEntry $entry, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entry);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function saveAll(array $entries, bool $flush = false): void
    {
        $em = $this->getEntityManager();
        foreach ($entries as $entry) {
            $em->persist($entry);
        }

        if ($flush) {
            $em->flush();
        }
    }

    /**
     * @return LedgerEntry[]
     */
    public function findByTransaction(Transaction $transaction): array
    {
        return $this->createQueryBuilder('le')
            ->where('le.transaction = :tx')
            ->setParameter('tx', $transaction)
            ->orderBy('le.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return LedgerEntry[]
     */
    public function findByAccount(Uuid $accountId, int $page = 1, int $limit = 50): array
    {
        return $this->createQueryBuilder('le')
            ->where('le.account = :id')
            ->setParameter('id', $accountId, 'uuid')
            ->orderBy('le.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByAccount(Uuid $accountId): int
    {
        return (int) $this->createQueryBuilder('le')
            ->select('COUNT(le.id)')
            ->where('le.account = :id')
            ->setParameter('id', $accountId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Reconstructs account balance at a given instant by summing all ledger lines.
     *
     * SUM(CREDIT amounts) - SUM(DEBIT amounts) = balance
     *
     * Useful for reconciliation: compare against accounts.balance and alert on discrepancy.
     */
    public function computeBalanceAt(Uuid $accountId, \DateTimeImmutable $at): string
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
            SELECT
                SUM(CASE WHEN direction = :credit THEN amount ELSE 0 END) -
                SUM(CASE WHEN direction = :debit  THEN amount ELSE 0 END)
            FROM ledger_entries
            WHERE account_id = :accountId
              AND created_at <= :at
        SQL;

        $result = $conn->fetchOne($sql, [
            'accountId' => $accountId->toBinary(),
            'at'        => $at->format('Y-m-d H:i:s.u'),
            'credit'    => LedgerDirection::Credit->value,
            'debit'     => LedgerDirection::Debit->value,
        ]);

        return $result !== false && $result !== null ? (string) $result : '0.0000';
    }

    /**
     * @return LedgerEntry[]
     */
    public function findByDateRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('le')
            ->where('le.createdAt >= :from')
            ->andWhere('le.createdAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('le.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
