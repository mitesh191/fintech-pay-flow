<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Transfer\Rule;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\Exception\AccountNotFoundException;
use App\Service\Transfer\Rule\AccountActiveRule;
use App\Service\Transfer\TransferContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AccountActiveRule (priority=10).
 *
 * Security: returning AccountNotFoundException (404) rather than a 403
 * prevents account enumeration — attackers learn nothing from the response.
 */
final class AccountActiveRuleTest extends TestCase
{
    private AccountActiveRule $rule;

    protected function setUp(): void
    {
        $this->rule = new AccountActiveRule();
    }

    public function test_priority_is_10(): void
    {
        $this->assertSame(10, $this->rule->getPriority());
    }

    public function test_passes_when_both_accounts_are_active(): void
    {
        $ctx = $this->context(
            source:      new Account('Alice', 'USD'),
            destination: new Account('Bob',   'USD'),
        );

        $this->rule->apply($ctx); // no exception = pass
        $this->addToAssertionCount(1);
    }

    public function test_throws_when_source_is_null(): void
    {
        $ctx = $this->context(source: null, destination: new Account('Bob', 'USD'));

        $this->expectException(AccountNotFoundException::class);
        $this->rule->apply($ctx);
    }

    public function test_throws_when_source_is_inactive(): void
    {
        $src = new Account('Alice', 'USD');
        $src->deactivate();

        $ctx = $this->context(source: $src, destination: new Account('Bob', 'USD'));

        $this->expectException(AccountNotFoundException::class);
        $this->rule->apply($ctx);
    }

    public function test_throws_when_destination_is_null(): void
    {
        $ctx = $this->context(source: new Account('Alice', 'USD'), destination: null);

        $this->expectException(AccountNotFoundException::class);
        $this->rule->apply($ctx);
    }

    public function test_throws_when_destination_is_inactive(): void
    {
        $dst = new Account('Bob', 'USD');
        $dst->deactivate();

        $ctx = $this->context(source: new Account('Alice', 'USD'), destination: $dst);

        $this->expectException(AccountNotFoundException::class);
        $this->rule->apply($ctx);
    }

    public function test_exception_message_contains_source_account_id_when_source_inactive(): void
    {
        $src = new Account('Alice', 'USD');
        $src->deactivate();

        $ctx = $this->context(
            source:      $src,
            destination: new Account('Bob', 'USD'),
            sourceId:    'src-uuid-1234',
        );

        try {
            $this->rule->apply($ctx);
            $this->fail('Expected AccountNotFoundException');
        } catch (AccountNotFoundException $e) {
            $this->assertStringContainsString('src-uuid-1234', $e->getMessage());
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function context(?Account $source, ?Account $destination, string $sourceId = 'src-id'): TransferContext
    {
        $request = new TransferRequest(
            sourceAccountId:      $sourceId,
            destinationAccountId: 'dst-id',
            amount:               '100.00',
            currency:             'USD',
            idempotencyKey:       'k1',
        );
        $ctx = TransferContext::create($request, 'caller-id', hash('sha256', 'caller-id:k1'));

        if ($source !== null && $destination !== null) {
            return $ctx->withAccounts($source, $destination);
        }

        // Use reflection to inject null accounts for negative tests
        $ref = new \ReflectionClass($ctx);
        $new = $ref->newInstanceWithoutConstructor();

        foreach (['request', 'callerApiKeyId', 'effectiveKey'] as $prop) {
            $p = $ref->getProperty($prop);
            $p->setValue($new, $p->getValue($ctx));
        }

        $srcProp = $ref->getProperty('sourceAccount');
        $srcProp->setValue($new, $source);

        $dstProp = $ref->getProperty('destinationAccount');
        $dstProp->setValue($new, $destination);

        return $new;
    }
}
