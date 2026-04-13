<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Predis\ClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/health', name: 'health_')]
final class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClientInterface $redis,
        private readonly string $environment,
    ) {}

    #[Route('', name: 'check', methods: ['GET'])]
    public function check(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis'    => $this->checkRedis(),
        ];

        $healthy    = array_reduce($checks, static fn (bool $ok, array $c) => $ok && $c['ok'], true);
        $statusCode = $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return $this->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $statusCode);
    }

    private function checkDatabase(): array
    {
        try {
            $this->connection->executeQuery('SELECT 1');
            return ['ok' => true];
        } catch (\Throwable $e) {
            // Never expose internal error messages in production — they may reveal
            // DB host names, credentials, or internal topology.
            return ['ok' => false, 'error' => $this->safeErrorMessage($e)];
        }
    }

    private function checkRedis(): array
    {
        try {
            $this->redis->ping();
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->safeErrorMessage($e)];
        }
    }

    /**
     * In production return a generic string; in non-production surfaces the raw
     * message to assist with debugging without requiring log access.
     */
    private function safeErrorMessage(\Throwable $e): string
    {
        return $this->environment === 'prod' ? 'check_failed' : $e->getMessage();
    }
}
