<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Transfer;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\Service\Transfer\TransferContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the TransferContext immutable value object.
 *
 * DDD: TransferContext encapsulates all read access during the rule chain.
 * Immutability is critical — a rule must never mutate context for subsequent rules.
 */
final class TransferContextTest extends TestCase
{
    // ─── Factory ──────────────────────────────────────────────────────────────

    public function test_create_stores_request_and_caller_id(): void
    {
        $request = $this->request();
        $ctx     = TransferContext::create($request, 'caller-1', 'effective-key-1');

        $this->assertSame($request,          $ctx->getRequest());
        $this->assertSame('caller-1',        $ctx->getCallerApiKeyId());
        $this->assertSame('effective-key-1', $ctx->getEffectiveKey());
    }

    public function test_create_has_null_accounts(): void
    {
        $ctx = TransferContext::create($this->request(), 'c1', 'k1');

        $this->assertNull($ctx->getSourceAccount());
        $this->assertNull($ctx->getDestinationAccount());
    }

    // ─── withAccounts() ───────────────────────────────────────────────────────

    public function test_with_accounts_returns_new_instance(): void
    {
        $ctx = TransferContext::create($this->request(), 'c1', 'k1');
        $src = new Account('Alice', 'USD');
        $dst = new Account('Bob',   'USD');

        $next = $ctx->withAccounts($src, $dst);

        $this->assertNotSame($ctx,  $next);
        $this->assertNull($ctx->getSourceAccount(), 'original must remain immutable');
    }

    public function test_with_accounts_sets_source_and_destination(): void
    {
        $src = new Account('Alice', 'USD');
        $dst = new Account('Bob',   'USD');

        $ctx = TransferContext::create($this->request(), 'c1', 'k1')
            ->withAccounts($src, $dst);

        $this->assertSame($src, $ctx->getSourceAccount());
        $this->assertSame($dst, $ctx->getDestinationAccount());
    }

    public function test_with_accounts_preserves_fee_amount(): void
    {
        $src = new Account('Alice', 'USD');
        $dst = new Account('Bob',   'USD');

        $ctx = TransferContext::create($this->request(), 'c1', 'k1')
            ->withFeeAmount('5.0000')
            ->withAccounts($src, $dst);

        $this->assertSame('5.0000', $ctx->getFeeAmount());
    }

    // ─── withFeeAmount() ──────────────────────────────────────────────────────

    public function test_with_fee_amount_returns_new_instance(): void
    {
        $ctx  = TransferContext::create($this->request(), 'c1', 'k1');
        $next = $ctx->withFeeAmount('2.5000');

        $this->assertNotSame($ctx, $next);
        $this->assertSame('0.0000', $ctx->getFeeAmount(), 'original must remain immutable');
    }

    public function test_with_fee_amount_normalises_to_four_dp(): void
    {
        $ctx = TransferContext::create($this->request(), 'c1', 'k1')
            ->withFeeAmount('2.5');

        $this->assertSame('2.5000', $ctx->getFeeAmount());
    }

    public function test_fee_defaults_to_zero(): void
    {
        $ctx = TransferContext::create($this->request(), 'c1', 'k1');

        $this->assertSame('0.0000', $ctx->getFeeAmount());
    }

    // ─── Money accessors ──────────────────────────────────────────────────────

    public function test_get_principal_returns_money(): void
    {
        $ctx = TransferContext::create(
            $this->request(amount: '100.00', currency: 'USD'), 'c1', 'k1'
        );

        $this->assertSame('100.0000', $ctx->getPrincipal()->getAmount());
        $this->assertSame('USD',      $ctx->getPrincipal()->getCurrency());
    }

    public function test_get_fee_returns_money(): void
    {
        $ctx = TransferContext::create($this->request(currency: 'EUR'), 'c1', 'k1')
            ->withFeeAmount('3.0000');

        $this->assertSame('3.0000', $ctx->getFee()->getAmount());
        $this->assertSame('EUR',    $ctx->getFee()->getCurrency());
    }

    public function test_get_total_debit_equals_principal_plus_fee(): void
    {
        $ctx = TransferContext::create(
            $this->request(amount: '100.00', currency: 'USD'), 'c1', 'k1'
        )->withFeeAmount('2.5000');

        $this->assertSame('102.5000', $ctx->getTotalDebit()->getAmount());
    }

    public function test_get_total_debit_with_zero_fee_equals_principal(): void
    {
        $ctx = TransferContext::create(
            $this->request(amount: '200.00', currency: 'USD'), 'c1', 'k1'
        );

        $this->assertSame('200.0000', $ctx->getTotalDebit()->getAmount());
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function request(string $amount = '100.00', string $currency = 'USD'): TransferRequest
    {
        return new TransferRequest(
            sourceAccountId:      'src-id',
            destinationAccountId: 'dst-id',
            amount:               $amount,
            currency:             $currency,
            idempotencyKey:       'k1',
        );
    }
}
