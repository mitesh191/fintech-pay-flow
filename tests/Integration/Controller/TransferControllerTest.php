<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\Integration\SecureWebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for the Transfer API.
 *
 * Exercises the full pipeline: HTTP authentication → validation → TransferService
 * → double-entry ledger → outbox → HTTP response, against the real test database.
 *
 * Fintech methodology: every test uses a unique idempotency key so fixture
 * state is not shared between tests — tests must be order-independent.
 */
final class TransferControllerTest extends SecureWebTestCase
{
    // ─── Authentication guard ─────────────────────────────────────────────────

    public function test_transfer_requires_auth(): void
    {
        static::$client->request('POST', '/api/transfers', [], [], [], '{}');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, static::$client->getResponse()->getStatusCode());
    }

    // ─── Validation ───────────────────────────────────────────────────────────

    public function test_missing_body_returns_422(): void
    {
        static::$client->request(
            'POST', '/api/transfers', [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . static::API_KEY, 'CONTENT_TYPE' => 'application/json'],
            'not json',
        );

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, static::$client->getResponse()->getStatusCode());
    }

    public function test_missing_required_fields_returns_400(): void
    {
        $response = $this->api('POST', '/api/transfers', [
            'amount'   => '100.00',
            'currency' => 'USD',
        ]);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function test_invalid_currency_returns_400(): void
    {
        [$srcId, $dstId] = $this->getTwoUsdAccountIds();

        $response = $this->api('POST', '/api/transfers', [
            'source_account_id'      => $srcId,
            'destination_account_id' => $dstId,
            'amount'                 => '10.00',
            'currency'               => 'us',
            'idempotency_key'        => $this->uniqueKey(),
        ]);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function test_zero_amount_returns_400(): void
    {
        [$srcId, $dstId] = $this->getTwoUsdAccountIds();

        $response = $this->api('POST', '/api/transfers', [
            'source_account_id'      => $srcId,
            'destination_account_id' => $dstId,
            'amount'                 => '0',
            'currency'               => 'USD',
            'idempotency_key'        => $this->uniqueKey(),
        ]);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // ─── Same-account guard ────────────────────────────────────────────────────

    public function test_same_source_and_destination_returns_422(): void
    {
        [$srcId] = $this->getTwoUsdAccountIds();

        $response = $this->api('POST', '/api/transfers', [
            'source_account_id'      => $srcId,
            'destination_account_id' => $srcId,
            'amount'                 => '10.00',
            'currency'               => 'USD',
            'idempotency_key'        => $this->uniqueKey(),
        ]);

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    // ─── Currency mismatch ────────────────────────────────────────────────────

    public function test_cross_currency_transfer_returns_422(): void
    {
        $usdAccountId = $this->createAccountAndGetId('USD Sender',   'USD', '1000.00');
        $eurAccountId = $this->createAccountAndGetId('EUR Receiver', 'EUR', '1000.00');

        $response = $this->api('POST', '/api/transfers', [
            'source_account_id'      => $usdAccountId,
            'destination_account_id' => $eurAccountId,
            'amount'                 => '10.00',
            'currency'               => 'USD',
            'idempotency_key'        => $this->uniqueKey(),
        ]);

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    // ─── Insufficient funds ───────────────────────────────────────────────────

    public function test_overdraft_returns_422(): void
    {
        $srcId = $this->createAccountAndGetId('Broke', 'USD', '5.00');
        $dstId = $this->createAccountAndGetId('Rich',  'USD', '0.00');

        $response = $this->api('POST', '/api/transfers', [
            'source_account_id'      => $srcId,
            'destination_account_id' => $dstId,
            'amount'                 => '10.00',
            'currency'               => 'USD',
            'idempotency_key'        => $this->uniqueKey(),
        ]);

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    // ─── Happy path ────────────────────────────────────────────────────────────

    public function test_successful_transfer_returns_201(): void
    {
        $srcId = $this->createAccountAndGetId('Happy Src A', 'USD', '500.00');
        $dstId = $this->createAccountAndGetId('Happy Dst A', 'USD', '0.00');

        $response = $this->api('POST', '/api/transfers', [
            'source_account_id'      => $srcId,
            'destination_account_id' => $dstId,
            'amount'                 => '1.00',
            'currency'               => 'USD',
            'idempotency_key'        => $this->uniqueKey(),
        ]);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    }

    public function test_successful_transfer_response_contains_id(): void
    {
        $srcId = $this->createAccountAndGetId('Happy Src B', 'USD', '500.00');
        $dstId = $this->createAccountAndGetId('Happy Dst B', 'USD', '0.00');

        $this->api('POST', '/api/transfers', [
            'source_account_id'      => $srcId,
            'destination_account_id' => $dstId,
            'amount'                 => '1.00',
            'currency'               => 'USD',
            'idempotency_key'        => $this->uniqueKey(),
        ]);

        $body = $this->json();
        $this->assertArrayHasKey('id', $body);
        $this->assertNotEmpty($body['id']);
    }

    public function test_successful_transfer_response_status_is_completed(): void
    {
        $srcId = $this->createAccountAndGetId('Happy Src C', 'USD', '500.00');
        $dstId = $this->createAccountAndGetId('Happy Dst C', 'USD', '0.00');

        $this->api('POST', '/api/transfers', [
            'source_account_id'      => $srcId,
            'destination_account_id' => $dstId,
            'amount'                 => '1.00',
            'currency'               => 'USD',
            'idempotency_key'        => $this->uniqueKey(),
        ]);

        $this->assertSame('completed', $this->json()['status'] ?? null);
    }

    public function test_transfer_debits_source_balance(): void
    {
        $srcId = $this->createAccountAndGetId('Sender', 'USD', '500.00');
        $dstId = $this->createAccountAndGetId('Receiver', 'USD', '0.00');

        $this->api('POST', '/api/transfers', [
            'source_account_id'      => $srcId,
            'destination_account_id' => $dstId,
            'amount'                 => '100.00',
            'currency'               => 'USD',
            'idempotency_key'        => $this->uniqueKey(),
        ]);

        $this->api('GET', '/api/accounts/' . $srcId);
        $this->assertSame('400.0000', $this->json()['balance'] ?? null);
    }

    public function test_transfer_credits_destination_balance(): void
    {
        $srcId = $this->createAccountAndGetId('Sender', 'USD', '500.00');
        $dstId = $this->createAccountAndGetId('Receiver', 'USD', '0.00');

        $this->api('POST', '/api/transfers', [
            'source_account_id'      => $srcId,
            'destination_account_id' => $dstId,
            'amount'                 => '200.00',
            'currency'               => 'USD',
            'idempotency_key'        => $this->uniqueKey(),
        ]);

        $this->api('GET', '/api/accounts/' . $dstId);
        $this->assertSame('200.0000', $this->json()['balance'] ?? null);
    }

    // ─── Idempotency ──────────────────────────────────────────────────────────

    public function test_duplicate_idempotency_key_returns_201_with_same_transaction(): void
    {
        [$srcId, $dstId] = $this->getTwoUsdAccountIds();
        $idempotencyKey  = $this->uniqueKey();

        $payload = [
            'source_account_id'      => $srcId,
            'destination_account_id' => $dstId,
            'amount'                 => '1.00',
            'currency'               => 'USD',
            'idempotency_key'        => $idempotencyKey,
        ];

        $this->api('POST', '/api/transfers', $payload);
        $firstId = $this->json()['id'];

        $this->api('POST', '/api/transfers', $payload);
        $secondId = $this->json()['id'];

        $this->assertSame($firstId, $secondId, 'Duplicate request must return the same transaction.');
    }

    public function test_same_idempotency_key_different_payload_returns_409(): void
    {
        [$srcId, $dstId] = $this->getTwoUsdAccountIds();
        $idempotencyKey  = $this->uniqueKey();

        // First transfer
        $this->api('POST', '/api/transfers', [
            'source_account_id'      => $srcId,
            'destination_account_id' => $dstId,
            'amount'                 => '1.00',
            'currency'               => 'USD',
            'idempotency_key'        => $idempotencyKey,
        ]);

        // Same key, different amount
        $response = $this->api('POST', '/api/transfers', [
            'source_account_id'      => $srcId,
            'destination_account_id' => $dstId,
            'amount'                 => '2.00',
            'currency'               => 'USD',
            'idempotency_key'        => $idempotencyKey,
        ]);

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    // ─── GET /api/transfers ───────────────────────────────────────────────────

    public function test_list_transfers_returns_200(): void
    {
        $response = $this->api('GET', '/api/transfers');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function test_list_transfers_returns_pagination(): void
    {
        $this->api('GET', '/api/transfers');

        $body = $this->json();
        $this->assertArrayHasKey('data',       $body);
        $this->assertArrayHasKey('pagination', $body);
    }

    // ─── GET /api/transfers/{id} ──────────────────────────────────────────────

    public function test_show_existing_transfer_returns_200(): void
    {
        [$srcId, $dstId] = $this->getTwoUsdAccountIds();

        $this->api('POST', '/api/transfers', [
            'source_account_id'      => $srcId,
            'destination_account_id' => $dstId,
            'amount'                 => '1.00',
            'currency'               => 'USD',
            'idempotency_key'        => $this->uniqueKey(),
        ]);
        $txId = $this->json()['id'];

        $response = $this->api('GET', '/api/transfers/' . $txId);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function test_show_nonexistent_transfer_returns_404(): void
    {
        $response = $this->api('GET', '/api/transfers/00000000-0000-7000-8000-000000000001');

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function test_show_with_invalid_id_returns_400(): void
    {
        $response = $this->api('GET', '/api/transfers/not-a-uuid');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createAccountAndGetId(string $owner, string $currency, string $balance = '0.00'): string
    {
        $this->api('POST', '/api/accounts', [
            'owner_name'      => $owner,
            'currency'        => $currency,
            'initial_balance' => $balance,
        ]);

        return $this->json()['id'];
    }

    /** Returns [aliceId, bobId] — two existing USD accounts from fixtures. */
    private function getTwoUsdAccountIds(): array
    {
        $this->api('GET', '/api/accounts?limit=100');
        $accounts = array_filter(
            $this->json()['data'],
            static fn(array $a) => $a['currency'] === 'USD',
        );
        $accounts = array_values($accounts);

        return [$accounts[0]['id'], $accounts[1]['id']];
    }

    private function uniqueKey(): string
    {
        return (string) Uuid::v7();
    }
}
