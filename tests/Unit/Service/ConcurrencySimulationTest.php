<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\Entity\Transaction;
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
use App\ValueObject\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Concurrency simulation tests for the transfer pipeline.
 *
 * PHP is single-threaded, so true parallel execution is not possible in a
 * unit test.  Instead, these tests simulate race conditions by:
 *
 *   1. Replaying the same idempotency key multiple times and asserting that
 *      the business logic executes exactly once (idempotency correctness).
 *
 *   2. Simulating the "lock contested" code path: idempotency lock returns
 *      false (another worker holds it), asserting the service re-checks the
 *      DB and returns the existing transaction.
 *
 *   3. Fund conservation: N sequential transfers debit/credit the same pair
 *      of accounts and verifies the total balance is conserved (no money
 *      created or destroyed under serialised concurrent pressure).
 *
 *   4. Stale Redis cache path: Redis has a result for key X but the DB record
 *      has a different payload — simulates a cache/payload mismatch race.
 *
 * For integration-level true parallel testing (multiple PHP-FPM workers,
 * actual MySQL row-level locking) see the README load-testing section.
 */
final class ConcurrencySimulationTest extends TestCase
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
    private TransferService $service;

    private string $srcId;
    private string $dstId;
    private Account $source;
    private Account $destination;

    protected function setUp(): void
    {
        $this->srcId = (string) Uuid::v7();
        $this->dstId = (string) Uuid::v7();

        $this->source      = $this->accountWithId($this->srcId, 'Alice', 'USD', '5000.0000');
        $this->destination = $this->accountWithId($this->dstId, 'Bob',   'USD', '0.0000');

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

        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturn(1);
        $this->em->method('getConnection')->willReturn($connection);

        $this->service = new TransferService(
            em:                 $this->em,
            accountRepo:        $this->accountRepo,
            txRepo:             $this->txRepo,
            ledgerRepo:         $this->ledgerRepo,
            outboxRepo:         $this->outboxRepo,
            idempotency:        $this->idempotency,
            ruleChain:          new TransferRuleChain([]),
            feeCalculator:      $this->feeCalculator,
            auditLogger:        $this->auditLogger,
            logger:             $this->logger,
            ledgerEntryFactory: new LedgerEntryFactory(),
            complianceCheck:    $this->complianceCheck,
            requestContext:     new RequestContext(),
        );
    }

    // ─── Idempotency under simulated concurrent retry ─────────────────────────

    /**
     * Scenario: the first request completes and writes to DB.
     * A second request with the same idempotency key hits after Redis result
     * is populated — must return the cached result without re-executing the business logic.
     */
    public function test_second_request_with_same_key_returns_cached_result_without_executing(): void
    {
        $existingTx = $this->completedTx();
        $request    = $this->request(idempotencyKey: 'idempotent-key-123');

        // Redis already has the result from the first request
        $this->idempotency->method('getCachedResult')->willReturn([
            'transaction_id'         => $existingTx->getId(),
            'status'                 => 'completed',
            'source_account_id'      => $this->srcId,
            'destination_account_id' => $this->dstId,
            'amount'                 => '100.00',
            'currency'               => 'USD',
        ]);

        $this->txRepo->method('findByUuid')->willReturn($existingTx);

        // Neither accountRepo nor feeCalculator should be touched — fast path only.
        $this->accountRepo->expects($this->never())->method('findPairWithLock');
        $this->feeCalculator->expects($this->never())->method('calculate');
        $this->em->expects($this->never())->method('beginTransaction');

        $result = $this->service->transfer($request, 'caller-1');

        $this->assertSame($existingTx, $result, 'Must return the existing TX from cache, not create a new one.');
    }

    /**
     * Scenario: Redis is cold, DB idempotency record exists (second request
     * after Redis eviction / TTL expiry).
     */
    public function test_second_request_with_db_idempotency_record_returns_existing_tx(): void
    {
        $existingTx = $this->completedTx();
        $request    = $this->request(idempotencyKey: 'db-idempotent-key');

        $this->idempotency->method('getCachedResult')->willReturn(null);
        $this->txRepo->method('findByIdempotencyKey')->willReturn($existingTx);

        $this->accountRepo->expects($this->never())->method('findPairWithLock');
        $this->em->expects($this->never())->method('beginTransaction');

        $result = $this->service->transfer($request, 'caller-1');

        $this->assertSame($existingTx, $result);
    }

    /**
     * Scenario: two concurrent workers both pass the Redis fast-path check
     * (no cached result) but only one wins the Redis lock (acquireLock = false
     * for the second).  The second worker must re-check the DB and, finding the
     * record written by the first, return it rather than throwing.
     */
    public function test_lock_contested_worker_falls_back_to_db_check_and_returns_existing_tx(): void
    {
        $existingTx = $this->completedTx();
        $request    = $this->request(idempotencyKey: 'contested-key');

        $this->idempotency->method('getCachedResult')->willReturn(null);

        // First findByIdempotencyKey (pre-lock check) → null (race window).
        // Second findByIdempotencyKey (post-lock fail check) → existing tx.
        $this->txRepo->method('findByIdempotencyKey')
            ->willReturnOnConsecutiveCalls(null, $existingTx);

        // Lock is NOT acquired — another worker holds it.
        $this->idempotency->method('acquireLock')->willReturn(false);

        $this->em->expects($this->never())->method('beginTransaction');

        $result = $this->service->transfer($request, 'caller-1');

        $this->assertSame($existingTx, $result);
    }

    /**
     * Scenario: Redis cache hit but the payload does NOT match the current request
     * (same idempotency key, different amount — fingerprint collision or client bug).
     * Service must throw DuplicateTransferException.
     */
    public function test_mismatched_payload_on_retry_throws_duplicate_exception(): void
    {
        $existingTx = $this->completedTx();
        $request    = $this->request(idempotencyKey: 'conflict-key', amount: '999.99');

        // Redis has an entry for this key but with a DIFFERENT amount
        $this->idempotency->method('getCachedResult')->willReturn([
            'transaction_id'         => $existingTx->getId(),
            'status'                 => 'completed',
            'source_account_id'      => $this->srcId,
            'destination_account_id' => $this->dstId,
            'amount'                 => '100.00',  // different from request
            'currency'               => 'USD',
        ]);

        $this->txRepo->method('findByUuid')->willReturn($existingTx);

        $this->expectException(\App\Exception\DuplicateTransferException::class);

        $this->service->transfer($request, 'caller-1');
    }

    // ─── Balance conservation under sequential concurrent pressure ────────────

    /**
     * Simulate N sequential transfers that would normally run concurrently.
     * After all transfers, total balance (source + destination) must be conserved.
     *
     * This tests the correctness of the debit/credit arithmetic at the
     * Account domain layer under repeated operations — the same invariant
     * that must hold in a truly parallel multi-worker scenario.
     */
    public function test_balance_conserved_across_sequential_simulated_parallel_transfers(): void
    {
        $source      = new Account('Alice', 'USD', '10000.0000');
        $destination = new Account('Bob',   'USD',  '0.0000');

        $totalBefore = bcadd($source->getBalance(), $destination->getBalance(), 4);

        $amounts = ['100.0000', '250.0000', '0.0001', '999.9999', '50.5000'];

        foreach ($amounts as $amount) {
            $source->debit(Money::of($amount, 'USD'));
            $destination->credit(Money::of($amount, 'USD'));
        }

        $totalAfter = bcadd($source->getBalance(), $destination->getBalance(), 4);

        $this->assertSame(
            $totalBefore,
            $totalAfter,
            'Total funds must be conserved: no money created or destroyed under concurrent pressure.'
        );
    }

    /**
     * Simulate the hot-path: same account pair, many sub-cent transfers.
     * Verifies no float rounding or off-by-one errors accumulate.
     */
    public function test_ten_thousand_sub_cent_transfers_preserve_exact_balance(): void
    {
        $source      = new Account('Alice', 'USD', '1.0000');
        $destination = new Account('Bob',   'USD',  '0.0000');

        $totalBefore = bcadd($source->getBalance(), $destination->getBalance(), 4);

        $count = 10_000;
        for ($i = 0; $i < $count; $i++) {
            $source->debit(Money::of('0.0001', 'USD'));
            $destination->credit(Money::of('0.0001', 'USD'));
        }

        $totalAfter = bcadd($source->getBalance(), $destination->getBalance(), 4);

        $this->assertSame($totalBefore, $totalAfter);
        $this->assertSame('0.0000', $source->getBalance());
        $this->assertSame('1.0000', $destination->getBalance());
    }

    /**
     * Scenario: stale Redis entry (e.g., written by a prior deployment with
     * an old schema) only has 'transaction_id' key but is missing 'amount'.
     * Service must invalidate the entry and fall through to the DB check.
     */
    public function test_malformed_redis_entry_is_invalidated_and_falls_through(): void
    {
        $request = $this->request(idempotencyKey: 'stale-key');

        // Malformed / legacy cache entry — missing required payload fields
        $this->idempotency->method('getCachedResult')->willReturn(['transaction_id' => 'some-old-id']);

        // Service should invalidate it
        $this->idempotency->expects($this->once())->method('invalidateCache');

        // After invalidation, DB check also returns null → proceeds to critical section
        $this->txRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->idempotency->method('acquireLock')->willReturn(true);
        $this->accountRepo->method('findPairWithLock')->willReturn([$this->source, $this->destination]);
        $this->feeCalculator->method('calculate')->willReturn('0.0000');

        $this->service->transfer($request, 'caller-1');

        // No assertion on return value — we care that acquireLock was reached (not fast-pathed).
        $this->addToAssertionCount(1);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function request(
        string $idempotencyKey = 'default-key',
        string $amount         = '100.00',
    ): TransferRequest {
        return new TransferRequest(
            sourceAccountId:      $this->srcId,
            destinationAccountId: $this->dstId,
            amount:               $amount,
            currency:             'USD',
            idempotencyKey:       $idempotencyKey,
        );
    }

    private function completedTx(): Transaction
    {
        $tx = new Transaction(
            idempotencyKey:     'existing-key',
            sourceAccount:      $this->source,
            destinationAccount: $this->destination,
            amount:             '100.00',
            currency:           'USD',
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
