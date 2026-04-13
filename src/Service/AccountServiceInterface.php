<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CreateAccountRequest;
use App\Entity\Account;
use App\Entity\ApiKey;

/**
 * DIP contract for account management.
 *
 * AccountController and any other consumer depends on this interface,
 * not on the concrete AccountService class.  Swapping implementations
 * (e.g. a CachedAccountService, a ReadModelAccountService) requires
 * zero changes to any caller.
 */
interface AccountServiceInterface
{
    public function create(CreateAccountRequest $request, ?ApiKey $apiKey = null): Account;

    /**
     * @throws \App\Exception\AccountNotFoundException when the account does not exist or is inactive
     */
    public function getById(string $id): Account;

    public function updateOwnerName(Account $account, string $ownerName): Account;

    /**
     * @throws \App\Exception\NonZeroBalanceException when the account still holds funds
     */
    public function deactivate(Account $account): void;
}
