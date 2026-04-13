<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;

interface ReversalServiceInterface
{
    /**
     * Reverse a completed transfer, returning funds to the original source.
     *
     * @throws \App\Exception\ReversalNotAllowedException
     * @throws \App\Exception\AccountNotFoundException
     * @throws \App\Exception\InsufficientFundsException
     */
    public function reverse(string $originalTransactionId, string $reason, string $callerApiKeyId): Transaction;
}
