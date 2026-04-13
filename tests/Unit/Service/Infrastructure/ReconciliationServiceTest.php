<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Infrastructure;

use App\Entity\Account;
use App\Entity\LedgerEntry;
use App\Entity\Transaction;
use App\Enum\LedgerDirection;
use App\Repository\LedgerRepositoryInterface;
use App\Service\Infrastructure\ReconciliationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Unit tests for ReconciliationService.
 *
 * Validates the SUM(debits) == SUM(credits) invariant for the per-transaction
 * reconciliation path.
 */
final class ReconciliationServiceTest extends TestCase
{
    private LedgerRepositoryInterface&MockObject $ledgerRepo;
    private LoggerInterface&MockObject $logger;
    private ReconciliationService $service;

    protected function setUp(): void
    {
        $this->ledgerRepo = $this->createMock(LedgerRepositoryInterface::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->service    = new ReconciliationService($this->ledgerRepo, $this->logger);
    }

    // ─── assertTransactionBalanced ────────────────────────────────────────────

    public function test_passes_when_debits_and_credits_match_zero_fee(): void
    {
        [$source, $destination, $tx] = $this->buildTx('100.0000', '0.0000');

        $entries = [
            $this->ledgerEntry($tx, $source,      LedgerDirection::Debit,  '100.0000'),
            $this->ledgerEntry($tx, $destination, LedgerDirection::Credit, '100.0000'),
        ];

        $this->ledgerRepo->method('findByTransaction')->willReturn($entries);

        // No exception = pass
        $this->service->assertTransactionBalanced($tx);
        $this->addToAssertionCount(1);
    }

    public function test_passes_when_debits_and_credits_match_with_fee(): void
    {
        [$source, $destination, $tx] = $this->buildTx('100.0000', '2.5000');

        // principal debit + fee debit = 102.5000, credit = 100.0000
        $entries = [
            $this->ledgerEntry($tx, $source,      LedgerDirection::Debit,  '100.0000'),
            $this->ledgerEntry($tx, $source,      LedgerDirection::Debit,    '2.5000'),
            $this->ledgerEntry($tx, $destination, LedgerDirection::Credit, '100.0000'),
        ];

        $this->ledgerRepo->method('findByTransaction')->willReturn($entries);

        $this->service->assertTransactionBalanced($tx);
        $this->addToAssertionCount(1);
    }

    public function test_throws_when_debit_sum_is_wrong(): void
    {
        [$source, $destination, $tx] = $this->buildTx('100.0000', '0.0000');

        // Deliberate error: debit is 90 instead of 100
        $entries = [
            $this->ledgerEntry($tx, $source,      LedgerDirection::Debit,   '90.0000'),
            $this->ledgerEntry($tx, $destination, LedgerDirection::Credit, '100.0000'),
        ];

        $this->ledgerRepo->method('findByTransaction')->willReturn($entries);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/debit mismatch/');

        $this->service->assertTransactionBalanced($tx);
    }

    public function test_throws_when_credit_sum_is_wrong(): void
    {
        [$source, $destination, $tx] = $this->buildTx('100.0000', '0.0000');

        // Deliberate error: credit is 50 instead of 100
        $entries = [
            $this->ledgerEntry($tx, $source,      LedgerDirection::Debit,  '100.0000'),
            $this->ledgerEntry($tx, $destination, LedgerDirection::Credit,  '50.0000'),
        ];

        $this->ledgerRepo->method('findByTransaction')->willReturn($entries);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/credit mismatch/');

        $this->service->assertTransactionBalanced($tx);
    }

    public function test_throws_when_no_ledger_entries_exist(): void
    {
        [,, $tx] = $this->buildTx('100.0000', '0.0000');

        $this->ledgerRepo->method('findByTransaction')->willReturn([]);

        $this->expectException(\DomainException::class);

        $this->service->assertTransactionBalanced($tx);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** @return array{Account, Account, Transaction} */
    private function buildTx(string $amount, string $feeAmount): array
    {
        $source      = new Account('Alice', 'USD', '1000.0000');
        $destination = new Account('Bob',   'USD',    '0.0000');

        $tx = new Transaction(
            idempotencyKey:     'recon-test-' . uniqid(),
            sourceAccount:      $source,
            destinationAccount: $destination,
            amount:             $amount,
            currency:           'USD',
            feeAmount:          $feeAmount,
        );
        $tx->markCompleted();

        return [$source, $destination, $tx];
    }

    private function ledgerEntry(
        Transaction     $tx,
        Account         $account,
        LedgerDirection $direction,
        string          $amount,
    ): LedgerEntry {
        return new LedgerEntry(
            transaction:   $tx,
            account:       $account,
            direction:     $direction,
            amount:        $amount,
            currency:      'USD',
            balanceBefore: '0.0000',
            balanceAfter:  '0.0000',
            entryType:     'transfer',
        );
    }
}
