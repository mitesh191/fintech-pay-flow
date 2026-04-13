<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLogEntry;

interface AuditLogRepositoryInterface
{
    public function save(AuditLogEntry $entry, bool $flush = false): void;

    /**
     * @return AuditLogEntry[]
     */
    public function findByEntity(string $entityType, string $entityId, int $page = 1, int $limit = 50): array;

    /**
     * @return AuditLogEntry[]
     */
    public function findByActor(string $actorId, int $page = 1, int $limit = 50): array;
}
