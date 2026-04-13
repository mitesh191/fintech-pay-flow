<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\Entity\OutboxEvent;
use App\Entity\Transaction;
use App\Event\TransferCompletedEvent;
use App\Exception\AccountNotFoundException;
use App\Exception\DuplicateTransferException;
use App\Exception\SameAccountTransferException;
use App\Repository\AccountRepositoryInterface;
use App\Repository\LedgerRepositoryInterface;
use App\Repository\OutboxEventRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Service\Audit\AuditLoggerInterface;
use App\Service\Compliance\ComplianceCheckInterface;
use App\Service\Fee\FeeCalculatorInterface;
use App\Service\Transfer\LedgerEntryFactory;
use App\Service\Transfer\TransferContext;
use App\Service\Transfer\TransferRuleChain;
use App\ValueObject\Money;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Orchestrates the full transfer pipeline.
 *
 * Responsibilities (SRP — each delegated):
 *   • Idempotency guard           → IdempotencyServiceInterface
 *   • Validation / business rules → TransferRuleChain
 *   • Fee calculation             → FeeCalculatorInterface
 *   • Debit / credit              → Account domain methods
 *   • Double-entry bookkeeping    → LedgerEntryFactory + LedgerRepository
 *   • Reliable event publishing   → OutboxEvent (transactional outbox)
 *   • Immutable audit trail       → AuditLoggerInterface
 *   • Structured logging          → LoggerInterface
 *
 * Scale design
 * ─────────────
 * Every write in the critical section (debit, credit, Transaction,
 * LedgerEntries, OutboxEvent, AuditLogEntry) is flushed in ONE
 * EntityManager::flush() call — one SQL round-trip, one commit.
 * This keeps p99 latency low under high concurrency.
 *
 * The outbox relay (ProcessOutboxCommand) publishes events to downstream
 * consumers asynchronously — the HTTP response is never blocked waiting
 * for Kafka/RabbitMQ acknowledgement.
 */
final class TransferService implements TransferServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface         $em,
        private readonly AccountRepositoryInterface     $accountRepo,
        private readonly TransactionRepositoryInterface $txRepo,
        private readonly LedgerRepositoryInterface      $ledgerRepo,
        private readonly OutboxEventRepositoryInterface $outboxRepo,
        private readonly IdempotencyServiceInterface    $idempotency,
        private readonly TransferRuleChain              $ruleChain,
        private readonly FeeCalculatorInterface         $feeCalculator,
        private readonly AuditLoggerInterface           $auditLogger,
        private readonly LoggerInterface                $logger,
        private readonly LedgerEntryFactory             $ledgerEntryFactory,
        private readonly ComplianceCheckInterface       $complianceCheck,
        private readonly RequestContext                 $requestContext,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────

    public function transfer(TransferRequest $request, string $callerApiKeyId): Transaction
    {
        // Pre-lock guard — stateless, no DB round-trip.
        if ($request->sourceAccountId === $request->destinationAccountId) {
            throw new SameAccountTransferException();
        }

        // Per-caller namespace: two callers can use the same client-supplied key.
        $effectiveKey = hash('sha256', $callerApiKeyId . ':' . $request->idempotencyKey);

        // ── Fast path 1: Redis result cache (zero DB reads on retry) ──────
        $cached = $this->idempotency->getCachedResult($effectiveKey);
        if ($cached !== null) {
            // Validate payload entirely from Redis — zero DB on happy path.
            if (isset($cached['transaction_id'], $cached['source_account_id'], $cached['destination_account_id'], $cached['amount'], $cached['currency'])) {
                $payloadOk = $cached['source_account_id']      === $request->sourceAccountId
                          && $cached['destination_account_id'] === $request->destinationAccountId
                          && bccomp($cached['amount'], $request->amount, 4) === 0
                          && $cached['currency']               === $request->currency;

                if ($payloadOk) {
                    // Payload matches — load the Transaction from DB only to return it.
                    $existing = $this->txRepo->findByUuid(Uuid::fromString($cached['transaction_id']));
                    if ($existing !== null) {
                        return $existing;
                    }
                    // Stale entry (Redis flush / TTL edge) — purge and fall through.
                    $this->idempotency->invalidateCache($effectiveKey);
                } else {
                    // Same idempotency key, different payload — load from DB for exception.
                    $existing = $this->txRepo->findByUuid(Uuid::fromString($cached['transaction_id']));
                    if ($existing !== null) {
                        throw new DuplicateTransferException($existing);
                    }
                    $this->idempotency->invalidateCache($effectiveKey);
                }
            } else {
                // Legacy/malformed cache entry — invalidate and fall through.
                $this->idempotency->invalidateCache($effectiveKey);
            }
        }

        // ── Fast path 2: DB idempotency record (cache cold / Redis flush) ──
        $existing = $this->txRepo->findByIdempotencyKey($effectiveKey);
        if ($existing !== null) {
            $this->assertPayloadMatches($existing, $request);
            return $existing;
        }

        // ── Critical section ───────────────────────────────────────────────
        $token        = bin2hex(random_bytes(16));
        $lockAcquired = false;
        $txStarted    = false;

        try {
            if (!$this->idempotency->acquireLock($effectiveKey, $token)) {
                // Another worker beat us — re-check DB before giving up.
                $existing = $this->txRepo->findByIdempotencyKey($effectiveKey);
                if ($existing !== null) {
                    $this->assertPayloadMatches($existing, $request);
                    return $existing;
                }
                throw new \RuntimeException('Transfer is already in progress. Please retry shortly.');
            }

            $lockAcquired = true;

            // Audit: record transfer initiation immediately after lock acquired,
            // BEFORE beginning the DB transaction. This establishes the authoritative
            // start time independent of commit latency — required for dispute resolution.
            // Written via DBAL (not ORM) so it persists independently of the main transaction.
            try {
                $this->em->getConnection()->insert('audit_log_entries', [
                    'id'          => Uuid::v7()->toRfc4122(),
                    'entity_type' => 'transfer',
                    'entity_id'   => $effectiveKey,
                    'action'      => 'transfer.initiated',
                    'actor_id'    => $callerApiKeyId,
                    'payload'     => json_encode([
                        'source_account_id'      => $request->sourceAccountId,
                        'destination_account_id' => $request->destinationAccountId,
                        'amount'                 => $request->amount,
                        'currency'               => $request->currency,
                    ], JSON_THROW_ON_ERROR),
                    'ip_address'  => $this->requestContext->getClientIp(),
                    'created_at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $auditErr) {
                $this->logger->warning('Failed to write transfer.initiated audit', [
                    'error' => $auditErr->getMessage(),
                ]);
            }

            // 1. Pessimistic row-level locks (deadlock-safe ordered by UUID).
            $this->em->beginTransaction();
            $txStarted = true;

            $rawAccounts = $this->accountRepo->findPairWithLock(
                $request->sourceAccountId,
                $request->destinationAccountId,
            );
            $source      = $this->resolveAccount($rawAccounts, $request->sourceAccountId);
            $destination = $this->resolveAccount($rawAccounts, $request->destinationAccountId);

            // 2. Build context and calculate fee before validation so
            //    DailyAmountLimitRule can optionally include fee in velocity.
            $context = TransferContext::create($request, $callerApiKeyId, $effectiveKey)
                ->withAccounts($source, $destination);
            $context = $context->withFeeAmount($this->feeCalculator->calculate($context));

            // 2b. KYC/AML/Sanctions screening — runs before business rules.
            $this->complianceCheck->screen($context);

            // 3. Run rule chain — throws domain exception on first violation.
            $this->ruleChain->apply($context);

            // 4. Mutate balances (domain methods enforce invariants).
            $sourceBalanceBefore = $source->getBalance();
            $source->debit($context->getTotalDebit()); // principal + fee as Money
            $sourceBalanceAfter  = $source->getBalance();

            $destBalanceBefore = $destination->getBalance();
            $destination->credit(Money::of($request->amount, $request->currency));
            $destBalanceAfter  = $destination->getBalance();

            // 5. Create Transaction entity (feeAmount injected at construction — DDD immutability).
            $transaction = new Transaction(
                idempotencyKey:     $effectiveKey,
                sourceAccount:      $source,
                destinationAccount: $destination,
                amount:             $request->amount,
                currency:           $request->currency,
                description:        $request->description,
                feeAmount:          $context->getFeeAmount(),
            );
            $transaction->markCompleted();

            // 6. Double-entry ledger lines (SRP — delegated to LedgerEntryFactory).
            $ledgerEntries = $this->ledgerEntryFactory->buildTransferEntries(
                $transaction,
                $source,      $sourceBalanceBefore, $sourceBalanceAfter,
                $destination, $destBalanceBefore,   $destBalanceAfter,
                $context->getFeeAmount(),
                $request->currency,
            );

            // 7. Transactional outbox (atomic with DB commit).
            $domainEvent = TransferCompletedEvent::fromTransaction(
                $transaction,
                $context->getFeeAmount(),
                $callerApiKeyId,
            );
            $outboxEvent = new OutboxEvent(
                eventType:   $domainEvent->getEventType(),
                aggregateId: $domainEvent->getAggregateId(),
                payload:     $domainEvent->toPayload(),
            );

            // 8. Audit log (persisted inside same transaction — rolls back if tx fails).
            $this->auditLogger->log(
                entityType: 'transfer',
                entityId:   $transaction->getId(),
                action:     'transfer.completed',
                actorId:    $callerApiKeyId,
                payload: [
                    'source_account_id'      => $request->sourceAccountId,
                    'destination_account_id' => $request->destinationAccountId,
                    'amount'                 => $request->amount,
                    'fee_amount'             => $context->getFeeAmount(),
                    'currency'               => $request->currency,
                ],
            );

            // 9. Single flush → one SQL batch, one commit.
            $this->em->persist($source);
            $this->em->persist($destination);
            $this->em->persist($transaction);
            $this->ledgerRepo->saveAll($ledgerEntries);
            $this->outboxRepo->save($outboxEvent);
            $this->em->flush();
            $this->em->commit();

            // 10. Warm Redis cache with full payload so retries validate from Redis
            //     entirely — zero DB queries on idempotent happy path.
            $this->idempotency->cacheResult($effectiveKey, [
                'transaction_id'        => $transaction->getId(),
                'status'                => $transaction->getStatus()->value,
                'source_account_id'     => $request->sourceAccountId,
                'destination_account_id'=> $request->destinationAccountId,
                'amount'                => $request->amount,
                'currency'              => $request->currency,
            ]);

            $this->logger->info('Transfer completed', [
                'transaction_id' => $transaction->getId(),
                'source'         => $request->sourceAccountId,
                'destination'    => $request->destinationAccountId,
                'amount'         => $request->amount,
                'fee'            => $context->getFeeAmount(),
                'currency'       => $request->currency,
            ]);

            return $transaction;

        } catch (
            SameAccountTransferException
            | \App\Exception\CurrencyMismatchException
            | \App\Exception\InsufficientFundsException
            | AccountNotFoundException
            | DuplicateTransferException
            | \App\Exception\DailyLimitExceededException
            | \App\Exception\ComplianceViolationException
            | \App\Exception\StepUpRequiredException
            | \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e
        ) {
            if ($txStarted) { $this->safeRollback(); }

            // Persist a Failed transaction in a separate unit-of-work so the
            // audit trail survives even when the transfer itself rolled back.
            $this->persistFailedTransaction($request, $effectiveKey, $callerApiKeyId, $e);

            throw $e;
        } catch (\Throwable $e) {
            if ($txStarted) { $this->safeRollback(); }

            $this->persistFailedTransaction($request, $effectiveKey, $callerApiKeyId, $e);

            $this->logger->error('Unexpected transfer failure', [
                'idempotency_key' => $effectiveKey,
                'error'           => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($lockAcquired) {
                $this->idempotency->releaseLock($effectiveKey, $token);
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Persist a Failed Transaction record in a separate DB transaction.
     *
     * Fintech audit requirement: every transfer attempt — including rejected
     * ones — must be recorded for compliance, fraud analysis, and dispute
     * resolution.  The failed record is written outside the main UoW so it
     * survives even when the primary transaction has already been rolled back.
     *
     * Uses a fresh EntityManager connection (DBAL-level) to avoid Doctrine
     * UoW contamination from the rolled-back main transaction.
     */
    private function persistFailedTransaction(
        TransferRequest $request,
        string          $effectiveKey,
        string          $callerApiKeyId,
        \Throwable      $exception,
    ): void {
        try {
            // Resolve source/destination as unmanaged references so the
            // failed Transaction FK columns are populated without loading
            // the full entity graph (the accounts may not even exist).
            $sourceRef = $this->em->getReference(Account::class, Uuid::fromString($request->sourceAccountId));
            $destRef   = $this->em->getReference(Account::class, Uuid::fromString($request->destinationAccountId));

            $failedTx = new Transaction(
                idempotencyKey:     $effectiveKey,
                sourceAccount:      $sourceRef,
                destinationAccount: $destRef,
                amount:             $request->amount,
                currency:           $request->currency,
                description:        $request->description,
            );
            $failedTx->markFailed($exception->getMessage());

            // Use DBAL directly to avoid Doctrine UoW state issues after rollback.
            $conn = $this->em->getConnection();
            $conn->insert('transactions', [
                'id'                  => $failedTx->getId(),
                'idempotency_key'     => $effectiveKey,
                'source_account_id'   => $request->sourceAccountId,
                'destination_account_id' => $request->destinationAccountId,
                'amount'              => $request->amount,
                'currency'            => $request->currency,
                'description'         => $request->description,
                'fee_amount'          => '0.0000',
                'status'              => 'failed',
                'failure_reason'      => mb_substr($exception->getMessage(), 0, 65535),
                'created_at'          => $failedTx->getCreatedAt()->format('Y-m-d H:i:s'),
                'completed_at'        => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            // Audit the failure via DBAL (EM may be closed after rollback).
            $conn->insert('audit_log_entries', [
                'id'          => Uuid::v7()->toRfc4122(),
                'entity_type' => 'transfer',
                'entity_id'   => $failedTx->getId(),
                'action'      => 'transfer.failed',
                'actor_id'    => $callerApiKeyId,
                'payload'     => json_encode([
                    'source_account_id'      => $request->sourceAccountId,
                    'destination_account_id' => $request->destinationAccountId,
                    'amount'                 => $request->amount,
                    'currency'               => $request->currency,
                    'failure_reason'         => $exception->getMessage(),
                    'exception_class'        => $exception::class,
                ], JSON_THROW_ON_ERROR),
                'ip_address'  => $this->requestContext->getClientIp(),
                'created_at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            $this->logger->info('Failed transfer recorded', [
                'transaction_id' => $failedTx->getId(),
                'source'         => $request->sourceAccountId,
                'destination'    => $request->destinationAccountId,
                'failure'        => $exception->getMessage(),
            ]);
        } catch (\Throwable $auditError) {
            // Audit persistence must never mask the original business exception.
            $this->logger->critical('Failed to persist failed-transfer audit record', [
                'idempotency_key' => $effectiveKey,
                'original_error'  => $exception->getMessage(),
                'audit_error'     => $auditError->getMessage(),
            ]);
        }
    }

    private function assertPayloadMatches(Transaction $existing, TransferRequest $request): void
    {
        $ok = (string) $existing->getSourceAccount()->getId()      === $request->sourceAccountId
           && (string) $existing->getDestinationAccount()->getId() === $request->destinationAccountId
           && bccomp($existing->getAmount(), $request->amount, 4)  === 0
           && $existing->getCurrency()                             === $request->currency;

        if (!$ok) {
            throw new DuplicateTransferException($existing);
        }
    }

    /** @param array<int, Account|null> $accounts */
    private function resolveAccount(array $accounts, string $accountId): Account
    {
        foreach ($accounts as $account) {
            if ($account !== null && (string) $account->getId() === $accountId) {
                return $account;
            }
        }
        throw new AccountNotFoundException($accountId);
    }

    private function safeRollback(): void
    {
        try {
            $this->em->rollback();
        } catch (\Throwable) {
            // Already rolled back or connection lost — nothing to do.
        }
    }
}
