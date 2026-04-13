<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\Integration\SecureWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for the /health endpoint.
 *
 * Health checks must be publicly accessible (no auth required) and must
 * reflect real dependency status — database + Redis connectivity.
 */
final class HealthControllerTest extends SecureWebTestCase
{
    public function test_health_returns_200_without_auth(): void
    {
        static::$client->request('GET', '/health');

        $this->assertSame(Response::HTTP_OK, static::$client->getResponse()->getStatusCode());
    }

    public function test_health_body_contains_database_key(): void
    {
        static::$client->request('GET', '/health');

        $body = $this->json();
        $this->assertArrayHasKey('database', $body['checks']);
    }

    public function test_health_body_contains_redis_key(): void
    {
        static::$client->request('GET', '/health');

        $body = $this->json();
        $this->assertArrayHasKey('redis', $body['checks']);
    }

    public function test_health_database_status_is_ok(): void
    {
        static::$client->request('GET', '/health');

        $body = $this->json();
        $this->assertTrue($body['checks']['database']['ok'] ?? false);
    }
}
