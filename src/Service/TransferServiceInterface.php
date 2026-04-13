<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\TransferRequest;
use App\Entity\Transaction;

/**
 * DIP contract for the transfer pipeline.
 *
 * TransferController depends on this interface, not the concrete
 * TransferService.  Swapping implementations (e.g. AsyncTransferService
 * that queues the work to Messenger) requires zero changes to controllers.
 */
interface TransferServiceInterface
{
    /**
     * Execute a fund transfer and return the resulting Transaction.
     *
     * Idempotent: re-submitting an identical request with the same
     * idempotencyKey returns the original Transaction without side effects.
     *
     * @throws \App\Exception\SameAccountTransferException
     * @throws \App\Exception\AccountNotFoundException
     * @throws \App\Exception\CurrencyMismatchException
     * @throws \App\Exception\InsufficientFundsException
     * @throws \App\Exception\DuplicateTransferException
     * @throws \App\Exception\DailyLimitExceededException
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     */
    public function transfer(TransferRequest $request, string $callerApiKeyId): Transaction;
}
