<?php

declare(strict_types=1);

namespace App\EventSubscriber;

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
use App\Security\ApiUser;
use App\Service\Audit\AuditLoggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class ExceptionSubscriber implements EventSubscriberInterface
{
    private const DOMAIN_MAP = [
        AccountNotFoundException::class     => Response::HTTP_NOT_FOUND,
        InsufficientFundsException::class   => Response::HTTP_UNPROCESSABLE_ENTITY,
        CurrencyMismatchException::class    => Response::HTTP_UNPROCESSABLE_ENTITY,
        SameAccountTransferException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
        NonZeroBalanceException::class      => Response::HTTP_UNPROCESSABLE_ENTITY,
        DailyLimitExceededException::class  => Response::HTTP_UNPROCESSABLE_ENTITY,
        ComplianceViolationException::class => Response::HTTP_FORBIDDEN,
        DuplicateTransferException::class   => Response::HTTP_CONFLICT,
        ReversalNotAllowedException::class  => Response::HTTP_UNPROCESSABLE_ENTITY,
        StepUpRequiredException::class      => Response::HTTP_FORBIDDEN,
    ];

    public function __construct(
        private readonly LoggerInterface        $logger,
        private readonly AuditLoggerInterface   $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface  $tokenStorage,
        private readonly string                 $environment,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 0]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request   = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            $message    = $exception->getMessage() ?: Response::$statusTexts[$statusCode] ?? 'Error';

            // Skip 404/405 — routing noise with no operational value
            if (!in_array($statusCode, [Response::HTTP_NOT_FOUND, Response::HTTP_METHOD_NOT_ALLOWED], true)) {
                $this->logger->warning('API request failed', $this->buildLogContext($exception, $request, $statusCode));
            }
        } elseif (isset(self::DOMAIN_MAP[$exception::class])) {
            $statusCode = self::DOMAIN_MAP[$exception::class];
            $message    = $exception->getMessage();

            $this->logger->warning('API domain rule violated', $this->buildLogContext($exception, $request, $statusCode));
        } else {
            $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
            $message    = 'An unexpected error occurred.';

            $this->logger->error('Unhandled exception', $this->buildLogContext($exception, $request, $statusCode) + [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }

        $body = ['error' => $message];

        if ($this->environment !== 'prod') {
            $body['debug'] = [
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
            ];
        }

        $event->setResponse(new JsonResponse($body, $statusCode));

        $this->writeAuditEntry($exception, $request, $statusCode);
    }

    private function writeAuditEntry(\Throwable $exception, Request $request, int $statusCode): void
    {
        // Skip routing noise — these have no operational or compliance value
        if (
            $exception instanceof HttpExceptionInterface
            && in_array($statusCode, [Response::HTTP_NOT_FOUND, Response::HTTP_METHOD_NOT_ALLOWED], true)
        ) {
            return;
        }

        $requestId = (string) ($request->attributes->get('request_id') ?? 'unknown');
        $user      = $this->tokenStorage->getToken()?->getUser();
        $actorId   = ($user instanceof ApiUser) ? $user->getUserIdentifier() : 'anonymous';

        $action = match (true) {
            $exception instanceof InsufficientFundsException    => 'api.insufficient_funds',
            $exception instanceof DailyLimitExceededException   => 'api.daily_limit_exceeded',
            $exception instanceof ComplianceViolationException  => 'api.compliance_violation',
            $exception instanceof DuplicateTransferException    => 'api.duplicate_transfer',
            $exception instanceof ReversalNotAllowedException   => 'api.reversal_not_allowed',
            $exception instanceof StepUpRequiredException       => 'api.step_up_required',
            $exception instanceof AccountNotFoundException      => 'api.account_not_found',
            $exception instanceof CurrencyMismatchException     => 'api.currency_mismatch',
            $exception instanceof SameAccountTransferException  => 'api.same_account_transfer',
            $exception instanceof NonZeroBalanceException       => 'api.non_zero_balance',
            $statusCode === Response::HTTP_UNAUTHORIZED         => 'api.unauthorized',
            $statusCode === Response::HTTP_FORBIDDEN            => 'api.forbidden',
            $statusCode === Response::HTTP_TOO_MANY_REQUESTS    => 'api.rate_limited',
            $statusCode >= 500                                  => 'api.server_error',
            default                                             => 'api.request_failed',
        };

        try {
            // Clear any pending dirty state from the failed request so we don't
            // accidentally flush half-written transactional entities alongside
            // this audit entry.
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            }

            $this->auditLogger->log(
                entityType: 'api_request',
                entityId:   $requestId,
                action:     $action,
                actorId:    $actorId,
                payload:    [
                    'method'      => $request->getMethod(),
                    'path'        => $request->getPathInfo(),
                    'http_status' => $statusCode,
                    'exception'   => $exception::class,
                    'reason'      => $exception->getMessage(),
                ],
                flush: true,
            );
        } catch (\Throwable) {
            // Audit failure must never alter the error response sent to the client.
            // DatabaseAuditLogger already logs internally via logger->critical().
        }
    }

    private function buildLogContext(\Throwable $e, Request $request, int $status): array
    {
        return [
            'request_id' => $request->attributes->get('request_id'),
            'method'     => $request->getMethod(),
            'path'       => $request->getPathInfo(),
            'status'     => $status,
            'exception'  => $e::class,
            'message'    => $e->getMessage(),
        ];
    }
}

