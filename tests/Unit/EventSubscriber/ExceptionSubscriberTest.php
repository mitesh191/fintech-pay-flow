<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\ExceptionSubscriber;
use App\Exception\AccountNotFoundException;
use App\Exception\ComplianceViolationException;
use App\Exception\CurrencyMismatchException;
use App\Exception\DailyLimitExceededException;
use App\Exception\DuplicateTransferException;
use App\Exception\InsufficientFundsException;
use App\Exception\NonZeroBalanceException;
use App\Exception\ReversalNotAllowedException;
use App\Exception\SameAccountTransferException;
use App\Exception\StepUpRequiredException;
use App\Service\Audit\AuditLoggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Unit tests for ExceptionSubscriber.
 *
 * Security: domain exception messages must not leak internal state in production.
 * The 'debug' key is present only in non-production environments.
 *
 * Only /api paths are handled — non-API exceptions pass through.
 */
final class ExceptionSubscriberTest extends TestCase
{
    private LoggerInterface&MockObject      $logger;
    private AuditLoggerInterface&MockObject $auditLogger;
    private EntityManagerInterface&MockObject $entityManager;
    private TokenStorageInterface&MockObject  $tokenStorage;

    protected function setUp(): void
    {
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->auditLogger   = $this->createMock(AuditLoggerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->tokenStorage  = $this->createMock(TokenStorageInterface::class);

        $this->entityManager->method('isOpen')->willReturn(true);
        $this->tokenStorage->method('getToken')->willReturn(null);
    }

    private function makeSubscriber(string $env = 'test'): ExceptionSubscriber
    {
        return new ExceptionSubscriber(
            $this->logger,
            $this->auditLogger,
            $this->entityManager,
            $this->tokenStorage,
            $env,
        );
    }

    public function test_subscribes_to_kernel_exception(): void
    {
        $events = ExceptionSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::EXCEPTION, $events);
    }

    // ─── Non-API path passthrough ─────────────────────────────────────────────

    public function test_does_not_set_response_for_non_api_path(): void
    {
        $event = $this->makeEvent(new \RuntimeException('boom'), '/health');

        $this->makeSubscriber()->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    public function test_does_not_set_response_for_root_path(): void
    {
        $event = $this->makeEvent(new \RuntimeException('boom'), '/');

        $this->makeSubscriber()->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    // ─── Domain exception HTTP mapping ────────────────────────────────────────

    #[DataProvider('domainExceptionProvider')]
    public function test_maps_domain_exception_to_correct_status_code(
        \Throwable $exception,
        int        $expectedStatus,
    ): void {
        $event = $this->makeEvent($exception, '/api/transfers');

        $this->makeSubscriber()->onKernelException($event);

        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
        $this->assertSame($expectedStatus, $event->getResponse()->getStatusCode());
    }

    public static function domainExceptionProvider(): iterable
    {
        yield 'AccountNotFound→404'          => [new AccountNotFoundException('uuid'),         Response::HTTP_NOT_FOUND];
        yield 'InsufficientFunds→422'        => [new InsufficientFundsException(),              Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'CurrencyMismatch→422'         => [new CurrencyMismatchException(),               Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'SameAccountTransfer→422'      => [new SameAccountTransferException(),            Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'NonZeroBalance→422'           => [new NonZeroBalanceException(),                 Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'DailyLimitExceeded→422'       => [new DailyLimitExceededException('USD', '50000.0000', '49900.0000'), Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'ComplianceViolation→403'      => [new ComplianceViolationException('sanctions'), Response::HTTP_FORBIDDEN];
        yield 'StepUpRequired→403'           => [new StepUpRequiredException('USD', '500.0000'), Response::HTTP_FORBIDDEN];
        yield 'ReversalNotAllowed→422'       => [new ReversalNotAllowedException('already done'), Response::HTTP_UNPROCESSABLE_ENTITY];
    }

    public function test_maps_duplicate_transfer_to_409(): void
    {
        $source = new \App\Entity\Account('Alice', 'USD');
        $dest   = new \App\Entity\Account('Bob', 'USD');
        $tx     = new \App\Entity\Transaction('k1', $source, $dest, '100', 'USD', 'test');
        $tx->markCompleted();

        $event = $this->makeEvent(new DuplicateTransferException($tx), '/api/transfers');
        $this->makeSubscriber()->onKernelException($event);

        $this->assertSame(Response::HTTP_CONFLICT, $event->getResponse()->getStatusCode());
    }

    // ─── HTTP exception handling ──────────────────────────────────────────────

    public function test_maps_http_exception_to_its_own_status_code(): void
    {
        $event = $this->makeEvent(new NotFoundHttpException('Route not found'), '/api/anything');

        $this->makeSubscriber()->onKernelException($event);

        $this->assertSame(404, $event->getResponse()->getStatusCode());
    }

    public function test_maps_access_denied_http_exception_to_403(): void
    {
        $event = $this->makeEvent(new AccessDeniedHttpException('Forbidden'), '/api/anything');

        $this->makeSubscriber()->onKernelException($event);

        $this->assertSame(403, $event->getResponse()->getStatusCode());
    }

    // ─── Generic exception → 500 ──────────────────────────────────────────────

    public function test_unhandled_exception_returns_500(): void
    {
        $event = $this->makeEvent(new \RuntimeException('db connection lost'), '/api/transfers');

        $this->makeSubscriber()->onKernelException($event);

        $this->assertSame(500, $event->getResponse()->getStatusCode());
    }

    public function test_unhandled_exception_logs_error(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Unhandled exception', $this->callback(fn(array $ctx) =>
                isset($ctx['exception']) && isset($ctx['message'])
            ));

        $event = $this->makeEvent(new \RuntimeException('crash'), '/api/transfers');
        $this->makeSubscriber()->onKernelException($event);
    }

    // ─── Debug key in non-prod ────────────────────────────────────────────────

    public function test_debug_key_present_in_non_prod(): void
    {
        $event = $this->makeEvent(new AccountNotFoundException('id'), '/api/accounts/id');

        $this->makeSubscriber()->onKernelException($event);

        /** @var array $body */
        $body = json_decode((string) $event->getResponse()->getContent(), true);
        $this->assertArrayHasKey('debug', $body);
        $this->assertArrayHasKey('exception', $body['debug']);
        $this->assertArrayHasKey('message',   $body['debug']);
    }

    public function test_debug_key_absent_in_prod(): void
    {
        $event = $this->makeEvent(new AccountNotFoundException('id'), '/api/accounts/id');

        $this->makeSubscriber('prod')->onKernelException($event);

        /** @var array $body */
        $body = json_decode((string) $event->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('debug', $body);
    }

    // ─── Response format ──────────────────────────────────────────────────────

    public function test_response_body_contains_error_key(): void
    {
        $event = $this->makeEvent(new AccountNotFoundException('id-1'), '/api/accounts/id-1');

        $this->makeSubscriber()->onKernelException($event);

        /** @var array $body */
        $body = json_decode((string) $event->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $body);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeEvent(\Throwable $exception, string $path): ExceptionEvent
    {
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = Request::create($path);

        return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
    }
}
