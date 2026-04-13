<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Enum\TransactionStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Transaction aggregate.
 *
 * DDD: Transaction is the core domain aggregate for a fund transfer.
 * These tests verify the state machine transitions enforced by domain methods:
 *   Pending → Completed
 *   Pending → Failed
 *   Completed → Reversed
 *   and all invalid transitions (guarded by \DomainException)
 *
 * SOLID/SRP: each method tests a single state transition or invariant.
 */
final class TransactionTest extends TestCase
{
    private Account $source;
    private Account $destination;

    protected function setUp(): void
    {
        $this->source      = new Account('Alice', 'USD', '1000.0000');
        $this->destination = new Account('Bob',   'USD',  '500.0000');
    }

    private function make(
        string  $key = 'idem-key-001',
        string  $amount = '100.00',
        ?string $description = null,
        string  $fee = '0.0000',
    ): Transaction {
        return new Transaction(
            idempotencyKey:     $key,
            sourceAccount:      $this->source,
            destinationAccount: $this->destination,
            amount:             $amount,
            currency:           'USD',
            description:        $description,
            feeAmount:          $fee,
        );
    }

    // ─── Initial state ────────────────────────────────────────────────────────

    public function test_initial_status_is_pending(): void
    {
        $this->assertSame(TransactionStatus::Pending, $this->make()->getStatus());
    }

    public function test_completed_at_is_null_initially(): void
    {
        $this->assertNull($this->make()->getCompletedAt());
    }

    public function test_failure_reason_is_null_initially(): void
    {
        $this->assertNull($this->make()->getFailureReason());
    }

    public function test_id_is_uuid_v7(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $this->make()->getId(),
        );
    }

    public function test_two_transactions_have_different_ids(): void
    {
        $t1 = $this->make('k1');
        $t2 = $this->make('k2');

        $this->assertNotSame($t1->getId(), $t2->getId());
    }

    public function test_created_at_is_set_on_construction(): void
    {
        $before = new \DateTimeImmutable();
        $tx     = $this->make();

        $this->assertGreaterThanOrEqual($before, $tx->getCreatedAt());
    }

    public function test_fee_amount_normalised_to_four_decimals(): void
    {
        $tx = $this->make(fee: '5');

        $this->assertSame('5.0000', $tx->getFeeAmount());
    }

    public function test_zero_fee_stored_as_four_decimal_zero(): void
    {
        $tx = $this->make();

        $this->assertSame('0.0000', $tx->getFeeAmount());
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    public function test_idempotency_key_getter(): void
    {
        $tx = $this->make('unique-key-xyz');

        $this->assertSame('unique-key-xyz', $tx->getIdempotencyKey());
    }

    public function test_amount_getter(): void
    {
        $tx = $this->make(amount: '250.50');

        $this->assertSame('250.50', $tx->getAmount());
    }

    public function test_currency_getter(): void
    {
        $this->assertSame('USD', $this->make()->getCurrency());
    }

    public function test_description_getter_returns_value_when_set(): void
    {
        $tx = $this->make(description: 'Invoice #42');

        $this->assertSame('Invoice #42', $tx->getDescription());
    }

    public function test_description_getter_returns_null_when_not_set(): void
    {
        $this->assertNull($this->make()->getDescription());
    }

    public function test_source_and_destination_accounts_are_stored(): void
    {
        $tx = $this->make();

        $this->assertSame($this->source,      $tx->getSourceAccount());
        $this->assertSame($this->destination, $tx->getDestinationAccount());
    }

    // ─── markCompleted() ──────────────────────────────────────────────────────

    public function test_mark_completed_sets_completed_status(): void
    {
        $tx = $this->make();
        $tx->markCompleted();

        $this->assertSame(TransactionStatus::Completed, $tx->getStatus());
    }

    public function test_mark_completed_sets_completed_at(): void
    {
        $before = new \DateTimeImmutable();
        $tx     = $this->make();
        $tx->markCompleted();

        $this->assertNotNull($tx->getCompletedAt());
        $this->assertGreaterThanOrEqual($before, $tx->getCompletedAt());
    }

    public function test_mark_completed_leaves_failure_reason_null(): void
    {
        $tx = $this->make();
        $tx->markCompleted();

        $this->assertNull($tx->getFailureReason());
    }

    public function test_mark_completed_throws_when_already_completed(): void
    {
        $tx = $this->make();
        $tx->markCompleted();

        $this->expectException(\DomainException::class);
        $tx->markCompleted();
    }

    public function test_mark_completed_throws_when_already_reversed(): void
    {
        $tx = $this->make();
        $tx->markCompleted();
        $tx->markReversed();

        $this->expectException(\DomainException::class);
        $tx->markCompleted();
    }

    // ─── markFailed() ─────────────────────────────────────────────────────────

    public function test_mark_failed_sets_failed_status(): void
    {
        $tx = $this->make();
        $tx->markFailed('Network error');

        $this->assertSame(TransactionStatus::Failed, $tx->getStatus());
    }

    public function test_mark_failed_stores_reason(): void
    {
        $tx = $this->make();
        $tx->markFailed('Insufficient funds');

        $this->assertSame('Insufficient funds', $tx->getFailureReason());
    }

    public function test_mark_failed_stores_null_for_empty_reason(): void
    {
        $tx = $this->make();
        $tx->markFailed('');

        $this->assertNull($tx->getFailureReason());
    }

    public function test_mark_failed_stores_null_when_no_argument(): void
    {
        $tx = $this->make();
        $tx->markFailed();

        $this->assertNull($tx->getFailureReason());
    }

    public function test_mark_failed_sets_completed_at(): void
    {
        $before = new \DateTimeImmutable();
        $tx     = $this->make();
        $tx->markFailed('reason');

        $this->assertGreaterThanOrEqual($before, $tx->getCompletedAt());
    }

    public function test_mark_failed_throws_when_already_completed(): void
    {
        $tx = $this->make();
        $tx->markCompleted();

        $this->expectException(\DomainException::class);
        $tx->markFailed('late failure');
    }

    // ─── markReversed() ───────────────────────────────────────────────────────

    public function test_mark_reversed_sets_reversed_status(): void
    {
        $tx = $this->make();
        $tx->markCompleted();
        $tx->markReversed();

        $this->assertSame(TransactionStatus::Reversed, $tx->getStatus());
    }

    public function test_mark_reversed_throws_when_pending(): void
    {
        $tx = $this->make();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/pending/i');
        $tx->markReversed();
    }

    public function test_mark_reversed_throws_when_failed(): void
    {
        $tx = $this->make();
        $tx->markFailed('error');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/failed/i');
        $tx->markReversed();
    }

    public function test_mark_reversed_sets_completed_at(): void
    {
        $before = new \DateTimeImmutable();
        $tx     = $this->make();
        $tx->markCompleted();
        $tx->markReversed();

        $this->assertGreaterThanOrEqual($before, $tx->getCompletedAt());
    }

    // ─── originalTransaction reference ────────────────────────────────────────

    public function test_original_transaction_is_null_by_default(): void
    {
        $this->assertNull($this->make()->getOriginalTransaction());
    }

    public function test_set_original_transaction_stores_reference(): void
    {
        $original = $this->make('original-key');
        $reversal = $this->make('reversal-key');
        $reversal->setOriginalTransaction($original);

        $this->assertSame($original, $reversal->getOriginalTransaction());
    }
}
