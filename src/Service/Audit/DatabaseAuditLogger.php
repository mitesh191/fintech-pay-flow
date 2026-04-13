<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\AuditLogEntry;
use App\Repository\AuditLogRepositoryInterface;
use App\Service\RequestContext;
use Psr\Log\LoggerInterface;

/**
 * Persists audit entries to the relational database.
 *
 * Writes are best-effort within the same unit-of-work as the transfer:
 * the entry is persisted (not flushed) in TransferService before commit
 * so it shares the atomic DB transaction.  If the transfer rolls back,
 * the audit entry rolls back with it — no ghost records.
 */
final class DatabaseAuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private readonly AuditLogRepositoryInterface $auditLogRepository,
        private readonly LoggerInterface $logger,
        private readonly RequestContext $requestContext,
    ) {}

    public function log(
        string  $entityType,
        string  $entityId,
        string  $action,
        string  $actorId,
        array   $payload   = [],
        ?string $ipAddress = null,
        bool    $flush     = false,
    ): void {
        try {
            // Use explicitly passed IP, or fall back to request-scoped context.
            $resolvedIp = $ipAddress ?? $this->requestContext->getClientIp();

            $entry = new AuditLogEntry(
                entityType: $entityType,
                entityId:   $entityId,
                action:     $action,
                actorId:    $actorId,
                payload:    $payload,
                ipAddress:  $resolvedIp,
            );

            $this->auditLogRepository->save($entry, flush: $flush);
        } catch (\Throwable $e) {
            // Audit failures must never abort a financial transaction.
            // Log the failure for ops alerting and continue.
            $this->logger->critical('Audit log write failed', [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'action'      => $action,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
