<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\Transaction;

/**
 * Raised when the same idempotency key arrives with different payload.
 * When the payload matches the original request the existing Transaction
 * is returned directly — no exception is thrown.
 */
final class DuplicateTransferException extends \RuntimeException
{
    public function __construct(
        private readonly Transaction $existingTransaction,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Idempotency key "%s" was already used with a different payload.',
                $existingTransaction->getIdempotencyKey(),
            ),
            $code,
            $previous,
        );
    }

    public function getExistingTransaction(): Transaction
    {
        return $this->existingTransaction;
    }
}
