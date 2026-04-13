<?php

declare(strict_types=1);

namespace App\Service\Transfer;

use App\Entity\Account;
use App\Entity\LedgerEntry;
use App\Entity\Transaction;
use App\Enum\LedgerDirection;

/**
 * Constructs the double-entry ledger lines for a completed transfer.
 *
 * SRP: this domain factory owns one responsibility — knowing the rules
 * of double-entry bookkeeping for transfers.  TransferService delegates
 * here so it stays focused on orchestration.
 *
 * Every transfer emits:
 *   DEBIT  source       principal         (entryType: 'transfer')
 *   DEBIT  source       fee               (entryType: 'fee', only when fee > 0)
 *   CREDIT destination  principal         (entryType: 'transfer')
 *
 * balance_before / balance_after on each entry allow regulators to
 * reconstruct the full account history from ledger_entries alone,
 * independent of the current accounts.balance column.
 */
final class LedgerEntryFactory
{
    /**
     * @return LedgerEntry[]  Always 2 entries (fee=0) or 3 entries (fee>0).
     */
    public function buildTransferEntries(
        Transaction $transaction,
        Account     $source,
        string      $sourceBalanceBefore,
        string      $sourceBalanceAfter,
        Account     $destination,
        string      $destBalanceBefore,
        string      $destBalanceAfter,
        string      $feeAmount,
        string      $currency,
    ): array {
        $hasFee  = bccomp($feeAmount, '0', 4) !== 0;
        $entries = [];

        // DEBIT source — principal
        $entries[] = new LedgerEntry(
            transaction:   $transaction,
            account:       $source,
            direction:     LedgerDirection::Debit,
            amount:        $transaction->getAmount(),
            currency:      $currency,
            balanceBefore: $sourceBalanceBefore,
            balanceAfter:  $hasFee
                ? bcsub($sourceBalanceBefore, $transaction->getAmount(), 4)
                : $sourceBalanceAfter,
            entryType: 'transfer',
        );

        // DEBIT source — fee (only when fee > 0)
        if ($hasFee) {
            $intermediateBalance = bcsub($sourceBalanceBefore, $transaction->getAmount(), 4);

            $entries[] = new LedgerEntry(
                transaction:   $transaction,
                account:       $source,
                direction:     LedgerDirection::Debit,
                amount:        $feeAmount,
                currency:      $currency,
                balanceBefore: $intermediateBalance,
                balanceAfter:  $sourceBalanceAfter,
                entryType:     'fee',
            );
        }

        // CREDIT destination — principal
        $entries[] = new LedgerEntry(
            transaction:   $transaction,
            account:       $destination,
            direction:     LedgerDirection::Credit,
            amount:        $transaction->getAmount(),
            currency:      $currency,
            balanceBefore: $destBalanceBefore,
            balanceAfter:  $destBalanceAfter,
            entryType:     'transfer',
        );

        return $entries;
    }

    /**
     * Build reversal ledger entries — the mirror image of the original transfer.
     *
     * A reversal CREDIT-s the original source (money returned) and DEBIT-s the
     * original destination (money reclaimed).  Fee reversal (if any) produces
     * an additional CREDIT on the original source.
     *
     * @return LedgerEntry[]
     */
    public function buildReversalEntries(
        Transaction $reversalTransaction,
        Account     $originalSource,
        string      $sourceBalanceBefore,
        string      $sourceBalanceAfter,
        Account     $originalDestination,
        string      $destBalanceBefore,
        string      $destBalanceAfter,
        string      $feeAmount,
        string      $currency,
    ): array {
        $hasFee  = bccomp($feeAmount, '0', 4) !== 0;
        $entries = [];

        // DEBIT destination — reclaim principal
        $entries[] = new LedgerEntry(
            transaction:   $reversalTransaction,
            account:       $originalDestination,
            direction:     LedgerDirection::Debit,
            amount:        $reversalTransaction->getAmount(),
            currency:      $currency,
            balanceBefore: $destBalanceBefore,
            balanceAfter:  $destBalanceAfter,
            entryType:     'reversal',
        );

        // CREDIT source — return principal
        $entries[] = new LedgerEntry(
            transaction:   $reversalTransaction,
            account:       $originalSource,
            direction:     LedgerDirection::Credit,
            amount:        $reversalTransaction->getAmount(),
            currency:      $currency,
            balanceBefore: $sourceBalanceBefore,
            balanceAfter:  $hasFee
                ? bcadd($sourceBalanceBefore, $reversalTransaction->getAmount(), 4)
                : $sourceBalanceAfter,
            entryType:     'reversal',
        );

        // CREDIT source — return fee (only when original had a fee)
        if ($hasFee) {
            $intermediateBalance = bcadd($sourceBalanceBefore, $reversalTransaction->getAmount(), 4);

            $entries[] = new LedgerEntry(
                transaction:   $reversalTransaction,
                account:       $originalSource,
                direction:     LedgerDirection::Credit,
                amount:        $feeAmount,
                currency:      $currency,
                balanceBefore: $intermediateBalance,
                balanceAfter:  $sourceBalanceAfter,
                entryType:     'reversal',
            );
        }

        return $entries;
    }
}
