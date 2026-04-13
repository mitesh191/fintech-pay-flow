<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\Entity\Transaction;
use App\Exception\SameAccountTransferException;
use App\Repository\AccountRepositoryInterface;
use App\Repository\LedgerRepositoryInterface;
use App\Repository\OutboxEventRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Service\Audit\AuditLoggerInterface;
use App\Service\Compliance\ComplianceCheckInterface;
use App\Service\Fee\FeeCalculatorInterface;
use App\Service\IdempotencyServiceInterface;
use App\Service\RequestContext;
use App\Service\Transfer\LedgerEntryFactory;
use App\Service\Transfer\TransferRuleChain;
use App\Service\TransferService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Unit tests for TransferService — the core orchestration service.
 *
 * Each external dependency is mocked.  The TransferRuleChain is injected
 * with zero rules so these tests focus on orchestration, not rule logic
 * (rules are tested individually in Rule/*Test.php).
 *
 * Fintech: every happy-path run must produce one Transaction, debit source,
 * credit destination, and persist double-entry ledger lines.
 */
final class TransferServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AccountRepositoryInterface&MockObject $accountRepo;
    private TransactionRepositoryInterface&MockObject $txRepo;
    private LedgerRepositoryInterface&MockObject $ledgerRepo;
    private OutboxEventRepositoryInterface&MockObject $outboxRepo;
    private IdempotencyServiceInterface&MockObject $idempotency;
    private FeeCalculatorInterface&MockObject $feeCalculator;
    private AuditLoggerInterface&MockObject $auditLogger;
    private LoggerInterface&MockObject $logger;
    private ComplianceCheckInterface&MockObject $complianceCheck;
    private RequestContext $requestContext;
    private TransferService $service;

    private string $srcId;
    private string $dstId;
    private Account $source;
    private Account $destination;

    protected function setUp(): void
    {
        $this->srcId = (string) Uuid::v7();
        $this->dstId = (string) Uuid::v7();

        $this->source      = $this->accountWithId($this->srcId, 'Alice', 'USD', '1000.0000');
        $this->destination = $this->accountWithId($this->dstId, 'Bob',   'USD');

        $this->em              = $this->createMock(EntityManagerInterface::class);
        $this->accountRepo     = $this->createMock(AccountRepositoryInterface::class);
        $this->txRepo          = $this->createMock(TransactionRepositoryInterface::class);
        $this->ledgerRepo      = $this->createMock(LedgerRepositoryInterface::class);
        $this->outboxRepo      = $this->createMock(OutboxEventRepositoryInterface::class);
        $this->idempotency     = $this->createMock(IdempotencyServiceInterface::class);
        $this->feeCalculator   = $this->createMock(FeeCalculatorInterface::class);
        $this->auditLogger     = $this->createMock(AuditLoggerInterface::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->complianceCheck = $this->createMock(ComplianceCheckInterface::class);
        $this->requestContext  = new RequestContext();

        $this->service = new TransferService(
            em:                 $this->em,
            accountRepo:        $this->accountRepo,
            txRepo:             $this->txRepo,
            ledgerRepo:         $this->ledgerRepo,
            outboxRepo:         $this->outboxRepo,
            idempotency:        $this->idempotency,
            ruleChain:          new TransferRuleChain([]), // no rules — tested separately
            feeCalculator:      $this->feeCalculator,
            auditLogger:        $this->auditLogger,
            logger:             $this->logger,
            ledgerEntryFactory: new LedgerEntryFactory(),
            complianceCheck:    $this->complianceCheck,
            requestContext:     $this->requestContext,
        );
    }

    // ─── Same-account guard ────────────────────────────────────────────────────

    public function test_throws_same_account_exception_for_same_source_and_dest(): void
    {
        $sameId  = (string) Uuid::v7();
        $request = $this->request(sourceId: $sameId, destId: $sameId);

        $this->expectException(SameAccountTransferException::class);
        $this->service->transfer($request, 'caller-id');
    }

    // ─── Idempotency: Redis fast path ─────────────────────────────────────────

    public function test_returns_existing_transaction_on_valid_redis_cache_hit(): void
    {
        $existingTx = $this->makeCompletedTx();

        $this->idempotency->method('getCachedResult')->willReturn([
            'transaction_id'        => (string) $existingTx->getId(),
            'source_account_id'     => $this->srcId,
            'destination_account_id'=> $this->dstId,
            'amount'                => '100.00',
            'currency'              => 'USD',
        ]);
        $this->txRepo->method('findByUuid')->willReturn($existingTx);

        $result = $this->service->transfer($this->request(), 'caller-id');

        $this->assertSame($existingTx, $result);
    }

    // ─── Idempotency: DB fallback ──────────────────────────────────────────────

    public function test_returns_existing_transaction_on_db_idempotency_hit(): void
    {
        $existingTx = $this->makeCompletedTx();

        $this->idempotency->method('getCachedResult')->willReturn(null);
        $this->txRepo->method('findByIdempotencyKey')->willReturn($existingTx);

        $result = $this->service->transfer($this->request(), 'caller-id');

        $this->assertSame($existingTx, $result);
    }

    // ─── Happy path ────────────────────────────────────────────────────────────

    public function test_happy_path_returns_completed_transaction(): void
    {
        $this->stubHappyPath();

        $result = $this->service->transfer($this->request(), 'caller-id');

        $this->assertInstanceOf(Transaction::class, $result);
    }

    public function test_happy_path_debits_source_account(): void
    {
        $this->stubHappyPath();
        $balanceBefore = $this->source->getBalance();

        $this->service->transfer($this->request(), 'caller-id');

        $this->assertLessThan($balanceBefore, $this->source->getBalance());
    }

    public function test_happy_path_credits_destination_account(): void
    {
        $this->stubHappyPath();
        $balanceBefore = $this->destination->getBalance();

        $this->service->transfer($this->request(), 'caller-id');

        $this->assertGreaterThan($balanceBefore, $this->destination->getBalance());
    }

    public function test_happy_path_commits_transaction(): void
    {
        $this->stubHappyPath();

        $this->em->expects($this->once())->method('commit');

        $this->service->transfer($this->request(), 'caller-id');
    }

    public function test_happy_path_flushes_once(): void
    {
        $this->stubHappyPath();

        $this->em->expects($this->once())->method('flush');

        $this->service->transfer($this->request(), 'caller-id');
    }

    public function test_happy_path_releases_lock_in_finally(): void
    {
        $this->stubHappyPath();

        $this->idempotency->expects($this->once())->method('releaseLock');

        $this->service->transfer($this->request(), 'caller-id');
    }

    public function test_happy_path_caches_result(): void
    {
        $this->stubHappyPath();

        $this->idempotency->expects($this->once())->method('cacheResult');

        $this->service->transfer($this->request(), 'caller-id');
    }

    // ─── Lock contention ──────────────────────────────────────────────────────

    public function test_throws_runtime_exception_when_lock_not_acquired_and_no_existing_tx(): void
    {
        $this->idempotency->method('getCachedResult')->willReturn(null);
        $this->txRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->idempotency->method('acquireLock')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->service->transfer($this->request(), 'caller-id');
    }

    public function test_returns_existing_tx_when_lock_lost_but_tx_found_in_db(): void
    {
        $existingTx = $this->makeCompletedTx();

        $this->idempotency->method('getCachedResult')->willReturn(null);
        // findByIdempotencyKey: first call → null, second call (inside lock-failure branch) → existing
        $this->txRepo->method('findByIdempotencyKey')
            ->willReturnOnConsecutiveCalls(null, $existingTx);
        $this->idempotency->method('acquireLock')->willReturn(false);

        $result = $this->service->transfer($this->request(), 'caller-id');

        $this->assertSame($existingTx, $result);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function stubHappyPath(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturn(1);
        $this->em->method('getConnection')->willReturn($connection);

        $this->idempotency->method('getCachedResult')->willReturn(null);
        $this->txRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->idempotency->method('acquireLock')->willReturn(true);

        $this->accountRepo->method('findPairWithLock')->willReturn([$this->source, $this->destination]);

        $this->feeCalculator->method('calculate')->willReturn('0.0000');
        $this->complianceCheck->method('screen'); // no-op
    }

    private function request(string $sourceId = '', string $destId = '', string $amount = '100.00'): TransferRequest
    {
        return new TransferRequest(
            sourceAccountId:      $sourceId ?: $this->srcId,
            destinationAccountId: $destId   ?: $this->dstId,
            amount:               $amount,
            currency:             'USD',
            idempotencyKey:       'test-idempotency-key',
        );
    }

    private function makeCompletedTx(): Transaction
    {
        $tx = new Transaction(
            idempotencyKey:     'k1',
            sourceAccount:      $this->source,
            destinationAccount: $this->destination,
            amount:             '100.00',
            currency:           'USD',
            description:        'Test',
        );
        $tx->markCompleted();

        return $tx;
    }

    private function accountWithId(
        string $id,
        string $owner    = 'Test',
        string $currency = 'USD',
        string $balance  = '0.0000',
    ): Account {
        $account = new Account($owner, $currency, $balance);

        $prop = new \ReflectionProperty(Account::class, 'id');
        $prop->setValue($account, Uuid::fromString($id));

        return $account;
    }
}
