<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\OutboxEvent;
use App\Entity\Transaction;
use App\Enum\TransactionStatus;
use App\Event\TransferReversedEvent;
use App\Exception\AccountNotFoundException;
use App\Exception\ReversalNotAllowedException;
use App\Repository\AccountRepositoryInterface;
use App\Repository\LedgerRepositoryInterface;
use App\Repository\OutboxEventRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Service\Audit\AuditLoggerInterface;
use App\Service\Transfer\LedgerEntryFactory;
use App\ValueObject\Money;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handles transfer reversals (chargebacks, error corrections, compliance recalls).
 *
 * A reversal creates a new Transaction that mirrors the original:
 *   - The original destination is debited (funds reclaimed)
 *   - The original source is credited (funds returned)
 *   - Any fee charged on the original is also returned
 *   - The original transaction is marked as Reversed
 *   - Double-entry reversal ledger lines are written
 *
 * The reversal shares the original's currency — cross-currency reversals
 * are not supported (same constraint as transfers).
 */
final class ReversalService implements ReversalServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface         $em,
        private readonly AccountRepositoryInterface     $accountRepo,
        private readonly TransactionRepositoryInterface $txRepo,
        private readonly LedgerRepositoryInterface      $ledgerRepo,
        private readonly OutboxEventRepositoryInterface $outboxRepo,
        private readonly IdempotencyServiceInterface    $idempotency,
        private readonly AuditLoggerInterface           $auditLogger,
        private readonly LoggerInterface                $logger,
        private readonly LedgerEntryFactory             $ledgerEntryFactory,
        private readonly RequestContext                 $requestContext,
    ) {}

    public function reverse(string $originalTransactionId, string $reason, string $callerApiKeyId): Transaction
    {
        // Validate the original transaction exists
        try {
            $originalUuid = Uuid::fromString($originalTransactionId);
        } catch (\InvalidArgumentException) {
            throw new ReversalNotAllowedException('Invalid transaction ID.');
        }

        $original = $this->txRepo->findByUuid($originalUuid);
        if ($original === null) {
            throw new ReversalNotAllowedException('Original transaction not found.');
        }

        // Idempotency: check DB first — before any state validation — so that a
        // true idempotent replay is returned even after the original TX has been
        // marked as reversed. The effective key is scoped to (caller, original TX)
        // so a different caller cannot see another caller's reversal.
        $effectiveKey = hash('sha256', $callerApiKeyId . ':reversal:' . $originalTransactionId);

        $existing = $this->txRepo->findByIdempotencyKey($effectiveKey);
        if ($existing !== null) {
            return $existing;
        }

        // Only completed transactions can be reversed
        if ($original->getStatus() !== TransactionStatus::Completed) {
            throw new ReversalNotAllowedException(
                sprintf('Transaction is %s — only completed transactions can be reversed.', $original->getStatus()->value)
            );
        }

        // Ownership check: caller must own the source account of the original
        $sourceOwner = $original->getSourceAccount()->getApiKey()?->getId();
        if ($sourceOwner === null || (string) $sourceOwner !== $callerApiKeyId) {
            throw new ReversalNotAllowedException('You do not own the source account of this transaction.');
        }

        $token        = bin2hex(random_bytes(16));
        $lockAcquired = false;
        $txStarted    = false;

        try {
            if (!$this->idempotency->acquireLock($effectiveKey, $token)) {
                $existing = $this->txRepo->findByIdempotencyKey($effectiveKey);
                if ($existing !== null) {
                    return $existing;
                }
                throw new \RuntimeException('Reversal is already in progress. Please retry shortly.');
            }
            $lockAcquired = true;

            // Lock both accounts in deterministic order (same as transfers)
            $this->em->beginTransaction();
            $txStarted = true;

            $rawAccounts = $this->accountRepo->findPairWithLock(
                (string) $original->getSourceAccount()->getId(),
                (string) $original->getDestinationAccount()->getId(),
            );

            $originalSource = $this->resolveAccount($rawAccounts, (string) $original->getSourceAccount()->getId());
            $originalDest   = $this->resolveAccount($rawAccounts, (string) $original->getDestinationAccount()->getId());

            // Re-check status under lock to prevent double-reversal races
            $this->em->refresh($original);
            if ($original->getStatus() !== TransactionStatus::Completed) {
                throw new ReversalNotAllowedException('Transaction was already reversed by another request.');
            }

            // Destination must have sufficient funds to return
            $totalReturn = bcadd($original->getAmount(), $original->getFeeAmount(), 4);
            $currency    = $original->getCurrency();

            // Debit destination (reclaim principal)
            $destBalanceBefore = $originalDest->getBalance();
            $originalDest->debit(Money::of($original->getAmount(), $currency));
            $destBalanceAfter = $originalDest->getBalance();

            // Credit source (return principal + fee)
            $sourceBalanceBefore = $originalSource->getBalance();
            $originalSource->credit(Money::of($totalReturn, $currency));
            $sourceBalanceAfter = $originalSource->getBalance();

            // Mark original as reversed
            $original->markReversed();

            // Create reversal transaction (source/destination are swapped conceptually,
            // but we keep the same source/destination as the original for traceability)
            $reversalTx = new Transaction(
                idempotencyKey:     $effectiveKey,
                sourceAccount:      $original->getSourceAccount(),
                destinationAccount: $original->getDestinationAccount(),
                amount:             $original->getAmount(),
                currency:           $original->getCurrency(),
                description:        sprintf('Reversal of %s: %s', $originalTransactionId, $reason),
            );
            $reversalTx->setOriginalTransaction($original);
            $reversalTx->markCompleted();

            // Double-entry reversal ledger lines
            $ledgerEntries = $this->ledgerEntryFactory->buildReversalEntries(
                $reversalTx,
                $originalSource,  $sourceBalanceBefore, $sourceBalanceAfter,
                $originalDest,    $destBalanceBefore,   $destBalanceAfter,
                $original->getFeeAmount(),
                $original->getCurrency(),
            );

            // Transactional outbox
            $domainEvent = TransferReversedEvent::fromTransactions(
                $reversalTx, $original, $callerApiKeyId, $reason,
            );
            $outboxEvent = new OutboxEvent(
                eventType:   $domainEvent->getEventType(),
                aggregateId: $domainEvent->getAggregateId(),
                payload:     $domainEvent->toPayload(),
            );

            // Audit
            $this->auditLogger->log(
                entityType: 'transfer',
                entityId:   $reversalTx->getId(),
                action:     'transfer.reversed',
                actorId:    $callerApiKeyId,
                payload: [
                    'original_transaction_id' => $originalTransactionId,
                    'amount'                  => $original->getAmount(),
                    'fee_returned'            => $original->getFeeAmount(),
                    'currency'                => $original->getCurrency(),
                    'reason'                  => $reason,
                ],
            );

            // Single flush
            $this->em->persist($originalSource);
            $this->em->persist($originalDest);
            $this->em->persist($original);
            $this->em->persist($reversalTx);
            $this->ledgerRepo->saveAll($ledgerEntries);
            $this->outboxRepo->save($outboxEvent);
            $this->em->flush();
            $this->em->commit();

            $this->logger->info('Transfer reversed', [
                'reversal_transaction_id' => $reversalTx->getId(),
                'original_transaction_id' => $originalTransactionId,
                'amount'                  => $original->getAmount(),
                'fee_returned'            => $original->getFeeAmount(),
                'currency'                => $original->getCurrency(),
                'reason'                  => $reason,
            ]);

            return $reversalTx;

        } catch (ReversalNotAllowedException|\App\Exception\InsufficientFundsException $e) {
            if ($txStarted) { $this->safeRollback(); }
            throw $e;
        } catch (\Throwable $e) {
            if ($txStarted) { $this->safeRollback(); }
            $this->logger->error('Unexpected reversal failure', [
                'original_transaction_id' => $originalTransactionId,
                'error'                   => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($lockAcquired) {
                $this->idempotency->releaseLock($effectiveKey, $token);
            }
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
        }
    }
}
