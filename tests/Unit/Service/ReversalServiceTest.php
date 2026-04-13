<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Enum\TransactionStatus;
use App\Exception\ReversalNotAllowedException;
use App\Repository\AccountRepositoryInterface;
use App\Repository\LedgerRepositoryInterface;
use App\Repository\OutboxEventRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Service\Audit\AuditLoggerInterface;
use App\Service\IdempotencyServiceInterface;
use App\Service\RequestContext;
use App\Service\ReversalService;
use App\Service\Transfer\LedgerEntryFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Unit tests for ReversalService.
 *
 * DDD: Reversals are first-class domain operations — not just balance deltas.
 * Every reversal produces a new Transaction (linked via originalTransaction),
 * mirror-image ledger entries, an outbox event, and an audit record.
 *
 * Security: only the original caller (same callerApiKeyId) may reverse
 * their own transfer.
 */
final class ReversalServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AccountRepositoryInterface&MockObject $accountRepo;
    private TransactionRepositoryInterface&MockObject $txRepo;
    private LedgerRepositoryInterface&MockObject $ledgerRepo;
    private OutboxEventRepositoryInterface&MockObject $outboxRepo;
    private IdempotencyServiceInterface&MockObject $idempotency;
    private AuditLoggerInterface&MockObject $auditLogger;
    private LoggerInterface&MockObject $logger;
    private ReversalService $service;

    private string $srcId;
    private string $dstId;
    private Account $source;
    private Account $destination;

    protected function setUp(): void
    {
        $this->srcId = (string) Uuid::v7();
        $this->dstId = (string) Uuid::v7();

        $this->source      = $this->accountWithId($this->srcId, 'Alice', 'USD', '800.0000');
        $this->destination = $this->accountWithId($this->dstId, 'Bob',   'USD', '200.0000');

        $this->em          = $this->createMock(EntityManagerInterface::class);
        $this->accountRepo = $this->createMock(AccountRepositoryInterface::class);
        $this->txRepo      = $this->createMock(TransactionRepositoryInterface::class);
        $this->ledgerRepo  = $this->createMock(LedgerRepositoryInterface::class);
        $this->outboxRepo  = $this->createMock(OutboxEventRepositoryInterface::class);
        $this->idempotency = $this->createMock(IdempotencyServiceInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->service = new ReversalService(
            em:                 $this->em,
            accountRepo:        $this->accountRepo,
            txRepo:             $this->txRepo,
            ledgerRepo:         $this->ledgerRepo,
            outboxRepo:         $this->outboxRepo,
            idempotency:        $this->idempotency,
            auditLogger:        $this->auditLogger,
            logger:             $this->logger,
            ledgerEntryFactory: new LedgerEntryFactory(),
            requestContext:     new RequestContext(),
        );
    }

    // ─── Guard clauses ────────────────────────────────────────────────────────

    public function test_throws_for_invalid_uuid_transaction_id(): void
    {
        $this->expectException(ReversalNotAllowedException::class);
        $this->service->reverse('not-a-uuid', 'refund', 'caller-id');
    }

    public function test_throws_when_original_transaction_not_found(): void
    {
        $this->txRepo->method('findByUuid')->willReturn(null);

        $this->expectException(ReversalNotAllowedException::class);
        $this->service->reverse((string) Uuid::v7(), 'refund', 'caller-id');
    }

    public function test_throws_when_original_transaction_is_not_completed(): void
    {
        $originalTx = $this->makeTx(status: TransactionStatus::Failed);
        $this->txRepo->method('findByUuid')->willReturn($originalTx);

        $this->expectException(ReversalNotAllowedException::class);
        $this->service->reverse((string) $originalTx->getId(), 'refund', 'caller-id');
    }

    public function test_throws_when_original_transaction_is_already_reversed(): void
    {
        $originalTx = $this->makeTx(status: TransactionStatus::Completed);
        $originalTx->markReversed();

        $this->txRepo->method('findByUuid')->willReturn($originalTx);

        $this->expectException(ReversalNotAllowedException::class);
        $this->service->reverse((string) $originalTx->getId(), 'refund', 'caller-id');
    }

    public function test_throws_when_caller_does_not_own_source_account(): void
    {
        $originalTx = $this->makeTx();
        $this->txRepo->method('findByUuid')->willReturn($originalTx);

        $this->expectException(ReversalNotAllowedException::class);
        $this->service->reverse((string) $originalTx->getId(), 'refund', 'different-caller');
    }

    // ─── Idempotency ──────────────────────────────────────────────────────────

    public function test_returns_existing_reversal_on_idempotency_hit(): void
    {
        $apiKey     = new \App\Entity\ApiKey('key', 'raw-secret');
        $this->setApiKeyId($apiKey, $this->srcId);

        $originalTx  = $this->makeTxWithApiKey($apiKey);
        $reversalTx  = $this->makeReversalTx($originalTx);

        $this->txRepo->method('findByUuid')->willReturn($originalTx);
        $this->txRepo->method('findByIdempotencyKey')->willReturn($reversalTx);

        $result = $this->service->reverse(
            (string) $originalTx->getId(),
            'refund',
            $this->srcId,
        );

        $this->assertSame($reversalTx, $result);
    }

    // ─── Happy path ────────────────────────────────────────────────────────────

    public function test_happy_path_returns_reversal_transaction(): void
    {
        $this->stubHappyPath();

        $apiKey     = new \App\Entity\ApiKey('key', 'raw-secret');
        $this->setApiKeyId($apiKey, $this->srcId);
        $originalTx = $this->makeTxWithApiKey($apiKey);

        $this->txRepo->expects($this->once())->method('findByUuid')->willReturn($originalTx);
        $this->txRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->em->method('refresh'); // re-check status under lock → still Completed

        $result = $this->service->reverse(
            (string) $originalTx->getId(),
            'error correction',
            $this->srcId,
        );

        $this->assertInstanceOf(Transaction::class, $result);
    }

    public function test_happy_path_debits_destination_on_reversal(): void
    {
        $this->stubHappyPath();

        $apiKey     = new \App\Entity\ApiKey('key', 'raw-secret');
        $this->setApiKeyId($apiKey, $this->srcId);
        $originalTx = $this->makeTxWithApiKey($apiKey);

        $this->txRepo->expects($this->once())->method('findByUuid')->willReturn($originalTx);
        $this->txRepo->method('findByIdempotencyKey')->willReturn(null);

        $destBalanceBefore = $this->destination->getBalance();
        $this->service->reverse((string) $originalTx->getId(), 'refund', $this->srcId);

        $this->assertLessThan($destBalanceBefore, $this->destination->getBalance());
    }

    public function test_happy_path_credits_source_on_reversal(): void
    {
        $this->stubHappyPath();

        $apiKey     = new \App\Entity\ApiKey('key', 'raw-secret');
        $this->setApiKeyId($apiKey, $this->srcId);
        $originalTx = $this->makeTxWithApiKey($apiKey);

        $this->txRepo->expects($this->once())->method('findByUuid')->willReturn($originalTx);
        $this->txRepo->method('findByIdempotencyKey')->willReturn(null);

        $srcBalanceBefore = $this->source->getBalance();
        $this->service->reverse((string) $originalTx->getId(), 'refund', $this->srcId);

        $this->assertGreaterThan($srcBalanceBefore, $this->source->getBalance());
    }

    public function test_original_transaction_marked_reversed_after_reversal(): void
    {
        $this->stubHappyPath();

        $apiKey     = new \App\Entity\ApiKey('key', 'raw-secret');
        $this->setApiKeyId($apiKey, $this->srcId);
        $originalTx = $this->makeTxWithApiKey($apiKey);

        $this->txRepo->expects($this->once())->method('findByUuid')->willReturn($originalTx);
        $this->txRepo->method('findByIdempotencyKey')->willReturn(null);

        $this->service->reverse((string) $originalTx->getId(), 'refund', $this->srcId);

        $this->assertSame(TransactionStatus::Reversed, $originalTx->getStatus());
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function stubHappyPath(): void
    {
        $this->idempotency->method('acquireLock')->willReturn(true);
        $this->accountRepo->method('findPairWithLock')->willReturn([$this->source, $this->destination]);
    }

    private function makeTx(TransactionStatus $status = TransactionStatus::Completed): Transaction
    {
        $tx = new Transaction(
            idempotencyKey:     hash('sha256', 'k1'),
            sourceAccount:      $this->source,
            destinationAccount: $this->destination,
            amount:             '100.0000',
            currency:           'USD',
            description:        'Test',
        );

        if ($status === TransactionStatus::Failed) {
            $tx->markFailed('fail reason');
        } elseif ($status === TransactionStatus::Completed) {
            $tx->markCompleted();
        } elseif ($status === TransactionStatus::Reversed) {
            $tx->markCompleted();
            $tx->markReversed();
        }

        return $tx;
    }

    private function makeTxWithApiKey(\App\Entity\ApiKey $apiKey): Transaction
    {
        $srcWithKey = $this->accountWithId($this->srcId, 'Alice', 'USD', '800.0000');
        $this->setAccountApiKey($srcWithKey, $apiKey);

        $tx = new Transaction(
            idempotencyKey:     hash('sha256', 'k-with-key'),
            sourceAccount:      $srcWithKey,
            destinationAccount: $this->destination,
            amount:             '100.0000',
            currency:           'USD',
            description:        'Test',
        );
        $tx->markCompleted();

        // Override source used by findPairWithLock for this tx's accounts
        $this->accountRepo->method('findPairWithLock')->willReturn([$srcWithKey, $this->destination]);

        return $tx;
    }

    private function makeReversalTx(Transaction $original): Transaction
    {
        $rev = new Transaction(
            idempotencyKey:     hash('sha256', $this->srcId . ':reversal:' . $original->getId()),
            sourceAccount:      $original->getSourceAccount(),
            destinationAccount: $original->getDestinationAccount(),
            amount:             $original->getAmount(),
            currency:           $original->getCurrency(),
            description:        'Reversal',
        );
        $rev->markCompleted();

        return $rev;
    }

    private function accountWithId(string $id, string $owner, string $currency, string $balance): Account
    {
        $account = new Account($owner, $currency, $balance);

        $prop = new \ReflectionProperty(Account::class, 'id');
        $prop->setValue($account, Uuid::fromString($id));

        return $account;
    }

    private function setApiKeyId(\App\Entity\ApiKey $apiKey, string $id): void
    {
        $prop = new \ReflectionProperty(\App\Entity\ApiKey::class, 'id');
        $prop->setValue($apiKey, Uuid::fromString($id));
    }

    private function setAccountApiKey(Account $account, \App\Entity\ApiKey $apiKey): void
    {
        $prop = new \ReflectionProperty(Account::class, 'apiKey');
        $prop->setValue($account, $apiKey);
    }
}
