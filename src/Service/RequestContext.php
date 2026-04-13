<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Request-scoped service that holds metadata from the current HTTP request.
 *
 * Populated by RequestContextListener on every request.
 * Injected into services (e.g. DatabaseAuditLogger) that need the client IP
 * without coupling to HttpFoundation.
 */
final class RequestContext
{
    private ?string $clientIp = null;

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function setClientIp(?string $ip): void
    {
        $this->clientIp = $ip;
    }
}
