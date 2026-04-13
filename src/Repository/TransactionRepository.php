<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use App\Enum\TransactionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
final class TransactionRepository extends ServiceEntityRepository implements TransactionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    public function findByIdempotencyKey(string $key): ?Transaction
    {
        return $this->createQueryBuilder('t')
            ->addSelect('sa', 'da')
            ->join('t.sourceAccount', 'sa')
            ->join('t.destinationAccount', 'da')
            ->where('t.idempotencyKey = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByUuid(Uuid $id): ?Transaction
    {
        return $this->createQueryBuilder('t')
            ->addSelect('sa', 'da')
            ->join('t.sourceAccount', 'sa')
            ->join('t.destinationAccount', 'da')
            ->where('t.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Transaction[]
     *
     * Uses a single UNION ALL query via DBAL for efficient pagination
     * across both sent and received transactions, leveraging DB-level
     * sorting and LIMIT instead of in-PHP merge.
     */
    public function findByAccount(Uuid $accountId, int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;
        $conn   = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
            SELECT id FROM (
                SELECT BIN_TO_UUID(id) AS id, created_at FROM transactions
                WHERE source_account_id = :accountId
                UNION ALL
                SELECT BIN_TO_UUID(id) AS id, created_at FROM transactions
                WHERE destination_account_id = :accountId
            ) AS combined
            ORDER BY created_at DESC
            LIMIT :lim OFFSET :off
        SQL;

        $rows = $conn->fetchAllAssociative($sql, [
            'accountId' => $accountId->toBinary(),
            'lim'       => $limit,
            'off'       => $offset,
        ], [
            'accountId' => 'binary',
            'lim'       => \Doctrine\DBAL\ParameterType::INTEGER,
            'off'       => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);

        if (empty($rows)) {
            return [];
        }

        $ids = array_map(static fn(array $row) => Uuid::fromString($row['id']), $rows);

        $results = $this->createQueryBuilder('t')
            ->addSelect('sa', 'da')
            ->join('t.sourceAccount', 'sa')
            ->join('t.destinationAccount', 'da')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids, 'uuid[]')
            ->getQuery()
            ->getResult();

        // Preserve the ORDER BY from the UNION query
        $indexed = [];
        foreach ($results as $tx) {
            $indexed[$tx->getId()] = $tx;
        }

        $ordered = [];
        foreach ($ids as $uid) {
            $key = $uid->toRfc4122();
            if (isset($indexed[$key])) {
                $ordered[] = $indexed[$key];
            }
        }

        return $ordered;
    }

    public function countByAccount(Uuid $accountId): int
    {
        // Same reasoning as findByAccount — avoid OR across two indexed columns.
        $sent = (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.sourceAccount = :id')
            ->setParameter('id', $accountId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        $received = (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.destinationAccount = :id')
            ->setParameter('id', $accountId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return $sent + $received;
    }

    /**
     * Returns all transactions, optionally scoped to accounts owned by a specific API key.
     * The JOIN on the source account's apiKey ensures callers only see their own transfers.
     *
     * @return Transaction[]
     */
    public function findAllPaginated(int $page = 1, int $limit = 20, ?Uuid $apiKeyId = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('sa', 'da')
            ->join('t.sourceAccount', 'sa')
            ->join('t.destinationAccount', 'da')
            ->orderBy('t.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($apiKeyId !== null) {
            $qb->andWhere('sa.apiKey = :apiKeyId')
               ->setParameter('apiKeyId', $apiKeyId, 'uuid');
        }

        return $qb->getQuery()->getResult();
    }

    public function countAll(?Uuid $apiKeyId = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)');

        if ($apiKeyId !== null) {
            $qb->join('t.sourceAccount', 'sa')
               ->andWhere('sa.apiKey = :apiKeyId')
               ->setParameter('apiKeyId', $apiKeyId, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return Transaction[]
     */
    public function findPendingOlderThan(\DateTimeImmutable $threshold): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->andWhere('t.createdAt < :threshold')
            ->setParameter('status', TransactionStatus::Pending->value)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    /**
     * Sums the principal amount of all COMPLETED transfers sent from $accountId
     * within the current calendar day in the given timezone.
     *
     * Uses idx_tx_source_created (source_account_id, created_at) — O(log N).
     */
    public function sumSentTodayByAccount(Uuid $accountId, string $timezone = 'UTC'): string
    {
        $tz = new \DateTimeZone($timezone);
        $todayStart = (new \DateTimeImmutable('today midnight', $tz))
            ->setTimezone(new \DateTimeZone('UTC'));

        $result = $this->createQueryBuilder('t')
            ->select('SUM(t.amount)')
            ->where('t.sourceAccount = :id')
            ->andWhere('t.createdAt >= :today')
            ->andWhere('t.status = :status')
            ->setParameter('id', $accountId, 'uuid')
            ->setParameter('today', $todayStart)
            ->setParameter('status', TransactionStatus::Completed->value)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? (string) $result : '0.0000';
    }

    public function save(Transaction $transaction, bool $flush = false): void
    {
        $this->getEntityManager()->persist($transaction);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

