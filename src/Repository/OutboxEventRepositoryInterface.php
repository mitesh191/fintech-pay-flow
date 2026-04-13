<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OutboxEvent;
use App\Enum\OutboxStatus;

interface OutboxEventRepositoryInterface
{
    public function save(OutboxEvent $event, bool $flush = false): void;

    /**
     * Batch-save multiple outbox events (single flush).
     *
     * @param OutboxEvent[] $events
     */
    public function saveAll(array $events, bool $flush = false): void;

    /**
     * Fetch the next batch of events ready to be relayed.
     *
     * Selects PENDING events whose scheduledAt ≤ now, ordered by scheduledAt ASC
     * (oldest first) so no event is starved indefinitely.
     *
     * @return OutboxEvent[]
     */
    public function findDueForProcessing(int $batchSize = 100): array;

    /**
     * @return OutboxEvent[]
     */
    public function findByStatus(OutboxStatus $status, int $limit = 100): array;

    public function countByStatus(OutboxStatus $status): int;
}
