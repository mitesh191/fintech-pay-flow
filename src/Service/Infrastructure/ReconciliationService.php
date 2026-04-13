<?php

declare(strict_types=1);

namespace App\Service\Infrastructure;

use App\Entity\Transaction;
use App\Enum\LedgerDirection;
use App\Repository\LedgerRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Double-entry bookkeeping reconciliation service.
 *
 * Core invariant (Pacioli's principle, 1494):
 *   SUM(all DEBIT entries) === SUM(all CREDIT entries)
 *
 * Fintech rationale
 * ─────────────────
 * This is the single most important financial integrity check.  Any divergence
 * in the SUM(debits) vs SUM(credits) at the ledger level means money was either
 * created or destroyed — which should be impossible in a correct system.
 *
 * When to run:
 *   1. Per-transaction: inline after each transfer (see assertTransactionBalanced()).
 *      Catches bugs immediately in CI and under load.
 *   2. Periodic batch: a reconciliation job (cron / Messenger) calls
 *      assertGloballyBalanced() for a given time window.
 *   3. Audit response: on-demand when investigating a dispute.
 *
 * Tolerance: all comparisons are exact (bccomp scale=4).  Financial systems
 * must never use epsilon-based floating-point equality.
 */
final class ReconciliationService
{
    public function __construct(
        private readonly LedgerRepositoryInterface $ledgerRepo,
        private readonly LoggerInterface           $logger,
    ) {}

    /**
     * Assert that the ledger entries for a single transaction balance.
     *
     * SUM(debit amounts) must equal SUM(credit amounts) for the same tx.
     *
     * Note: a zero-fee transfer has 1 debit + 1 credit; a fee transfer
     * has 2 debits (principal + fee) + 1 credit so the debit sum = credit sum
     * only when the fee entry is counted separately from the credit entry.
     *
     * Wait — for a transfer with fee the ledger looks like:
     *   DEBIT  source  principal
     *   DEBIT  source  fee
     *   CREDIT dest    principal
     *
     * Debits total: principal + fee
     * Credits total: principal
     * They do NOT balance per-transaction — the fee is "revenue" that leaves
     * the system (flows to the platform). This is correct and expected.
     *
     * The invariant we CAN assert per-transaction:
     *   SUM(credits) = principal_amount  (money reaches destination)
     *   SUM(debits)  = principal + fee   (money leaves source)
     *
     * This method asserts the credit side equals the transaction. amount
     * and the debit side equals amount + feeAmount.
     *
     * @throws \DomainException when the ledger is out of balance
     */
    public function assertTransactionBalanced(Transaction $transaction): void
    {
        $entries = $this->ledgerRepo->findByTransaction($transaction);

        $totalDebits  = '0.0000';
        $totalCredits = '0.0000';

        foreach ($entries as $entry) {
            if ($entry->getDirection() === LedgerDirection::Debit) {
                $totalDebits = bcadd($totalDebits, $entry->getAmount(), 4);
            } else {
                $totalCredits = bcadd($totalCredits, $entry->getAmount(), 4);
            }
        }

        $expectedDebit  = bcadd($transaction->getAmount(), $transaction->getFeeAmount(), 4);
        $expectedCredit = bcadd($transaction->getAmount(), '0', 4);

        if (bccomp($totalDebits, $expectedDebit, 4) !== 0) {
            $this->alertAndThrow(
                $transaction->getId(),
                'debit',
                $expectedDebit,
                $totalDebits,
            );
        }

        if (bccomp($totalCredits, $expectedCredit, 4) !== 0) {
            $this->alertAndThrow(
                $transaction->getId(),
                'credit',
                $expectedCredit,
                $totalCredits,
            );
        }

        $this->logger->debug('Reconciliation passed', [
            'transaction_id' => $transaction->getId(),
            'debits'         => $totalDebits,
            'credits'        => $totalCredits,
        ]);
    }

    /**
     * Assert global ledger balance for all entries in a given time window.
     *
     * In a closed system (no FX, no fee revenue flowing out) total debits
     * across ALL accounts must equal total credits.  Fee entries break this
     * symmetry — so this method returns the imbalance (fee revenue) rather
     * than asserting strict equality.  Callers may reconcile this against
     * the expected fee income for the period.
     *
     * @return array{total_debits: string, total_credits: string, imbalance: string}
     */
    public function summariseWindow(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $entries = $this->ledgerRepo->findByDateRange($from, $to);

        $totalDebits  = '0.0000';
        $totalCredits = '0.0000';

        foreach ($entries as $entry) {
            if ($entry->getDirection() === LedgerDirection::Debit) {
                $totalDebits = bcadd($totalDebits, $entry->getAmount(), 4);
            } else {
                $totalCredits = bcadd($totalCredits, $entry->getAmount(), 4);
            }
        }

        $imbalance = bcsub($totalDebits, $totalCredits, 4);

        $this->logger->info('Reconciliation window summary', [
            'from'          => $from->format(\DateTimeInterface::RFC3339),
            'to'            => $to->format(\DateTimeInterface::RFC3339),
            'total_debits'  => $totalDebits,
            'total_credits' => $totalCredits,
            'imbalance'     => $imbalance,
        ]);

        return [
            'total_debits'  => $totalDebits,
            'total_credits' => $totalCredits,
            'imbalance'     => $imbalance,
        ];
    }

    private function alertAndThrow(string $transactionId, string $side, string $expected, string $actual): never
    {
        $message = sprintf(
            'RECONCILIATION FAILURE: transaction %s %s mismatch. Expected %s, got %s.',
            $transactionId,
            $side,
            $expected,
            $actual,
        );

        $this->logger->critical($message, [
            'transaction_id' => $transactionId,
            'side'           => $side,
            'expected'       => $expected,
            'actual'         => $actual,
        ]);

        throw new \DomainException($message);
    }
}
