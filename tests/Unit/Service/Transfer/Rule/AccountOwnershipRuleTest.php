<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Transfer\Rule;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\Entity\ApiKey;
use App\Service\Transfer\Rule\AccountOwnershipRule;
use App\Service\Transfer\TransferContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Unit tests for AccountOwnershipRule (priority=20).
 *
 * Security: prevents one API key from debiting another tenant's account.
 * Returns 403 — not 404 — because by this point both accounts are confirmed active.
 */
final class AccountOwnershipRuleTest extends TestCase
{
    private AccountOwnershipRule $rule;

    protected function setUp(): void
    {
        $this->rule = new AccountOwnershipRule();
    }

    public function test_priority_is_20(): void
    {
        $this->assertSame(20, $this->rule->getPriority());
    }

    public function test_passes_when_caller_owns_source_account(): void
    {
        $apiKey  = new ApiKey('owner', 'raw-secret');
        $source  = $this->makeAccount('Alice', $apiKey);
        $dest    = new Account('Bob', 'USD');

        $ctx = $this->context($source, $dest, (string) $apiKey->getId());

        $this->rule->apply($ctx); // no exception
        $this->addToAssertionCount(1);
    }

    public function test_throws_when_caller_does_not_own_source_account(): void
    {
        $ownerKey = new ApiKey('owner', 'owner-secret');
        $source   = $this->makeAccount('Alice', $ownerKey);
        $dest     = new Account('Bob', 'USD');

        $ctx = $this->context($source, $dest, 'different-caller-id');

        $this->expectException(AccessDeniedHttpException::class);
        $this->rule->apply($ctx);
    }

    public function test_throws_when_source_has_no_api_key(): void
    {
        $source = new Account('Alice', 'USD'); // no ApiKey
        $dest   = new Account('Bob', 'USD');

        $ctx = $this->context($source, $dest, 'some-caller-id');

        $this->expectException(AccessDeniedHttpException::class);
        $this->rule->apply($ctx);
    }

    public function test_throws_when_caller_id_is_empty(): void
    {
        $apiKey = new ApiKey('owner', 'raw-secret');
        $source = $this->makeAccount('Alice', $apiKey);
        $dest   = new Account('Bob', 'USD');

        $ctx = $this->context($source, $dest, '');

        $this->expectException(AccessDeniedHttpException::class);
        $this->rule->apply($ctx);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeAccount(string $owner, ApiKey $apiKey): Account
    {
        return new Account(ownerName: $owner, currency: 'USD', apiKey: $apiKey);
    }

    private function context(Account $src, Account $dst, string $callerApiKeyId): TransferContext
    {
        $request = new TransferRequest('src', 'dst', '100', 'USD', 'k');

        return TransferContext::create($request, $callerApiKeyId, hash('sha256', $callerApiKeyId . ':k'))
            ->withAccounts($src, $dst);
    }
}
