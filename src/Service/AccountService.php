<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CreateAccountRequest;
use App\Entity\Account;
use App\Entity\ApiKey;
use App\Exception\AccountNotFoundException;
use App\Exception\NonZeroBalanceException;
use App\Repository\AccountRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class AccountService implements AccountServiceInterface
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function create(CreateAccountRequest $request, ?ApiKey $apiKey = null): Account
    {
        $account = new Account(
            ownerName:      $request->ownerName,
            currency:       $request->currency,
            initialBalance: $request->initialBalance,
            apiKey:         $apiKey,
        );

        $this->accountRepository->save($account, flush: true);

        $this->logger->info('Account created', [
            'account_id' => (string) $account->getId(),
            'owner'      => $request->ownerName,
            'currency'   => $request->currency,
        ]);

        return $account;
    }

    public function getById(string $id): Account
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new AccountNotFoundException($id);
        }

        $account = $this->accountRepository->findByIdWithLock($uuid, lock: false);

        if ($account === null || !$account->isActive()) {
            throw new AccountNotFoundException($id);
        }

        return $account;
    }

    public function updateOwnerName(Account $account, string $ownerName): Account
    {
        $account->renameOwner($ownerName);
        $this->accountRepository->save($account, flush: true);

        $this->logger->info('Account owner name updated', [
            'account_id' => (string) $account->getId(),
            'owner'      => $ownerName,
        ]);

        return $account;
    }

    public function deactivate(Account $account): void
    {
        if (bccomp($account->getBalance(), '0', 4) !== 0) {
            throw new NonZeroBalanceException();
        }

        $account->deactivate();
        $this->accountRepository->save($account, flush: true);

        $this->logger->info('Account deactivated', [
            'account_id' => (string) $account->getId(),
        ]);
    }
}
