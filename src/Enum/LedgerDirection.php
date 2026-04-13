<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Canonical direction values for a double-entry ledger line.
 *
 * Every financial event produces exactly two entries:
 *   DEBIT  the source account  (money leaves)
 *   CREDIT the destination    (money arrives)
 *
 * Fee entries produce an additional DEBIT on the source account.
 */
enum LedgerDirection: string
{
    case Debit  = 'debit';
    case Credit = 'credit';
}
