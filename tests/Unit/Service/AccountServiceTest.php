<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\CreateAccountRequest;
use App\Entity\Account;
use App\Entity\ApiKey;
use App\Exception\AccountNotFoundException;
use App\Exception\NonZeroBalanceException;
use App\Repository\AccountRepositoryInterface;
use App\Service\AccountService;
use App\ValueObject\Money;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Unit tests for AccountService.
 *
 * SRP: AccountService delegates persistence to the repository and
 * balance/state invariants to the Account aggregate.  Tests here
 * assert orchestration contracts — not the internals of Account.
 *
 * All external dependencies (repository, logger) are mocked so these tests
 * are lightning-fast and do not require a database.
 */
final class AccountServiceTest extends TestCase
{
    private AccountRepositoryInterface&MockObject $accountRepo;
    private LoggerInterface&MockObject $logger;
    private AccountService $service;

    protected function setUp(): void
    {
        $this->accountRepo = $this->createMock(AccountRepositoryInterface::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->service     = new AccountService($this->accountRepo, $this->logger);
    }

    // ─── create() ─────────────────────────────────────────────────────────────

    public function test_create_persists_and_returns_account(): void
    {
        $request = new CreateAccountRequest(ownerName: 'Alice', currency: 'USD');

        $this->accountRepo
            ->expects($this->once())
            ->method('save');

        $account = $this->service->create($request);

        $this->assertInstanceOf(Account::class, $account);
        $this->assertSame('Alice', $account->getOwnerName());
        $this->assertSame('USD',   $account->getCurrency());
    }

    public function test_create_attaches_api_key(): void
    {
        $apiKey  = $this->makeApiKey();
        $request = new CreateAccountRequest(ownerName: 'Bob', currency: 'EUR');

        $account = $this->service->create($request, $apiKey);

        $this->assertSame($apiKey, $account->getApiKey());
    }

    public function test_create_uses_initial_balance_from_request(): void
    {
        $request = new CreateAccountRequest(ownerName: 'Carol', currency: 'USD', initialBalance: '500.0000');
        $account = $this->service->create($request);

        $this->assertSame('500.0000', $account->getBalance());
    }

    public function test_create_logs_info(): void
    {
        $request = new CreateAccountRequest(ownerName: 'Dan', currency: 'USD');

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Account created', $this->isType('array'));

        $this->service->create($request);
    }

    // ─── getById() ────────────────────────────────────────────────────────────

    public function test_get_by_id_returns_active_account(): void
    {
        $account = $this->makeActiveAccount();

        $this->accountRepo
            ->method('findByIdWithLock')
            ->willReturn($account);

        $found = $this->service->getById((string) $account->getId());

        $this->assertSame($account, $found);
    }

    public function test_get_by_id_throws_on_invalid_uuid(): void
    {
        $this->expectException(AccountNotFoundException::class);
        $this->service->getById('not-a-uuid');
    }

    public function test_get_by_id_throws_when_account_not_found(): void
    {
        $this->accountRepo
            ->method('findByIdWithLock')
            ->willReturn(null);

        $this->expectException(AccountNotFoundException::class);
        $this->service->getById((string) Uuid::v7());
    }

    public function test_get_by_id_throws_when_account_is_inactive(): void
    {
        $account = $this->makeActiveAccount();
        $account->deactivate();

        $this->accountRepo
            ->method('findByIdWithLock')
            ->willReturn($account);

        $this->expectException(AccountNotFoundException::class);
        $this->service->getById((string) $account->getId());
    }

    // ─── updateOwnerName() ────────────────────────────────────────────────────

    public function test_update_owner_name_persists_and_returns_account(): void
    {
        $account = $this->makeActiveAccount('Alice');

        $this->accountRepo
            ->expects($this->once())
            ->method('save');

        $result = $this->service->updateOwnerName($account, 'Alice Renamed');

        $this->assertSame($result, $account);
        $this->assertSame('Alice Renamed', $account->getOwnerName());
    }

    public function test_update_owner_name_logs_info(): void
    {
        $account = $this->makeActiveAccount();

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Account owner name updated', $this->isType('array'));

        $this->service->updateOwnerName($account, 'New Name');
    }

    public function test_update_owner_name_throws_for_blank_name(): void
    {
        $account = $this->makeActiveAccount();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateOwnerName($account, '');
    }

    // ─── deactivate() ─────────────────────────────────────────────────────────

    public function test_deactivate_succeeds_for_zero_balance_account(): void
    {
        $account = $this->makeActiveAccount();

        $this->accountRepo
            ->expects($this->once())
            ->method('save');

        $this->service->deactivate($account);

        $this->assertFalse($account->isActive());
    }

    public function test_deactivate_throws_for_non_zero_balance(): void
    {
        $account = $this->makeActiveAccount();
        $account->credit(Money::of('100.00', 'USD'));

        $this->expectException(NonZeroBalanceException::class);
        $this->service->deactivate($account);
    }

    public function test_deactivate_logs_info(): void
    {
        $account = $this->makeActiveAccount();

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Account deactivated', $this->isType('array'));

        $this->service->deactivate($account);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeActiveAccount(string $owner = 'Test User', string $currency = 'USD'): Account
    {
        return new Account(ownerName: $owner, currency: $currency);
    }

    private function makeApiKey(): ApiKey
    {
        return new ApiKey('test-key', 'raw-token-value');
    }
}
