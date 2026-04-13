<?php

declare(strict_types=1);

namespace App\Service\Audit;

/**
 * Abstraction over the audit-logging mechanism.
 *
 * DIP: TransferService and AccountService depend on this interface, not
 * the concrete DatabaseAuditLogger.  A NullAuditLogger can be injected
 * in tests; a ElasticSearchAuditLogger can replace the DB implementation
 * in production without changing any business logic.
 */
interface AuditLoggerInterface
{
    /**
     * Record a financial or administrative action.
     *
     * @param array<string, mixed> $payload Snapshot of relevant data at time of action.
     * @param bool $flush Flush immediately. Default false: caller controls the transaction
     *                    boundary (e.g. TransferService). Pass true only when writing
     *                    outside an active transaction (e.g. ExceptionSubscriber).
     */
    public function log(
        string  $entityType,
        string  $entityId,
        string  $action,
        string  $actorId,
        array   $payload   = [],
        ?string $ipAddress = null,
        bool    $flush     = false,
    ): void;
}
