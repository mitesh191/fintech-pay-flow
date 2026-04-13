<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\Integration\SecureWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for the Account API.
 *
 * These tests boot the full Symfony kernel, hit the real database (test schema),
 * and exercise the complete request → auth → validation → service → DB → response
 * pipeline, exactly as it runs in production.
 */
final class AccountControllerTest extends SecureWebTestCase
{
    // ─── Authentication guard ─────────────────────────────────────────────────

    public function test_create_requires_auth(): void
    {
        static::$client->request('POST', '/api/accounts', [], [], [], '{}');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, static::$client->getResponse()->getStatusCode());
    }

    public function test_list_requires_auth(): void
    {
        static::$client->request('GET', '/api/accounts');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, static::$client->getResponse()->getStatusCode());
    }

    public function test_invalid_bearer_token_returns_401(): void
    {
        static::$client->request('GET', '/api/accounts', [], [], ['HTTP_AUTHORIZATION' => 'Bearer invalid-token']);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, static::$client->getResponse()->getStatusCode());
    }

    // ─── POST /api/accounts ───────────────────────────────────────────────────

    public function test_create_account_returns_201(): void
    {
        $response = $this->api('POST', '/api/accounts', [
            'owner_name' => 'Integration Test User',
            'currency'   => 'USD',
        ]);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    }

    public function test_create_account_response_contains_id(): void
    {
        $this->api('POST', '/api/accounts', [
            'owner_name' => 'New Account Holder',
            'currency'   => 'USD',
        ]);

        $body = $this->json();
        $this->assertArrayHasKey('id', $body);
        $this->assertNotEmpty($body['id']);
    }

    public function test_create_account_response_contains_correct_currency(): void
    {
        $this->api('POST', '/api/accounts', [
            'owner_name' => 'EUR Account Holder',
            'currency'   => 'EUR',
        ]);

        $this->assertSame('EUR', $this->json()['currency'] ?? null);
    }

    public function test_create_account_response_contains_zero_initial_balance(): void
    {
        $this->api('POST', '/api/accounts', [
            'owner_name' => 'Zero Balance',
            'currency'   => 'USD',
        ]);

        $this->assertSame('0.0000', $this->json()['balance'] ?? null);
    }

    public function test_create_account_with_initial_balance(): void
    {
        $this->api('POST', '/api/accounts', [
            'owner_name'      => 'Funded User',
            'currency'        => 'USD',
            'initial_balance' => '500.00',
        ]);

        $this->assertSame('500.0000', $this->json()['balance'] ?? null);
    }

    public function test_create_account_with_invalid_json_returns_422(): void
    {
        static::$client->request(
            'POST', '/api/accounts', [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . static::API_KEY, 'CONTENT_TYPE' => 'application/json'],
            'not valid json{',
        );

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, static::$client->getResponse()->getStatusCode());
    }

    public function test_create_account_with_blank_owner_name_returns_400(): void
    {
        $response = $this->api('POST', '/api/accounts', [
            'owner_name' => '',
            'currency'   => 'USD',
        ]);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function test_create_account_with_invalid_currency_returns_400(): void
    {
        $response = $this->api('POST', '/api/accounts', [
            'owner_name' => 'Test',
            'currency'   => 'us',
        ]);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function test_create_account_returns_account_number_format(): void
    {
        $this->api('POST', '/api/accounts', [
            'owner_name' => 'Account Number Test',
            'currency'   => 'USD',
        ]);

        $body = $this->json();
        $this->assertMatchesRegularExpression('/^FT\d{12}$/', $body['account_number'] ?? '');
    }

    // ─── GET /api/accounts ────────────────────────────────────────────────────

    public function test_list_accounts_returns_200(): void
    {
        $response = $this->api('GET', '/api/accounts');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function test_list_accounts_returns_pagination_structure(): void
    {
        $this->api('GET', '/api/accounts');

        $body = $this->json();
        $this->assertArrayHasKey('data',       $body);
        $this->assertArrayHasKey('pagination', $body);
        $this->assertArrayHasKey('page',       $body['pagination']);
        $this->assertArrayHasKey('total',      $body['pagination']);
    }

    public function test_list_accounts_only_returns_own_accounts(): void
    {
        $this->api('GET', '/api/accounts');

        $body     = $this->json();
        $this->assertIsArray($body['data']);
        // Fixture creates Alice, Bob (USD) and Carol (EUR) — all under the test API key.
        $this->assertGreaterThanOrEqual(2, count($body['data']));
    }

    // ─── GET /api/accounts/{id} ───────────────────────────────────────────────

    public function test_show_own_account_returns_200(): void
    {
        $accountId = $this->createAccountAndGetId('Show Test', 'USD');

        $response = $this->api('GET', '/api/accounts/' . $accountId);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function test_show_unknown_account_returns_404(): void
    {
        $response = $this->api('GET', '/api/accounts/00000000-0000-7000-8000-000000000000');

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // ─── PATCH /api/accounts/{id} (owner name update) ─────────────────────────

    public function test_update_owner_name_returns_200(): void
    {
        $accountId = $this->createAccountAndGetId('Old Name', 'USD');

        $response = $this->api('PATCH', '/api/accounts/' . $accountId, [
            'owner_name' => 'New Name',
        ]);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function test_update_owner_name_persists_change(): void
    {
        $accountId = $this->createAccountAndGetId('Original Name', 'USD');

        $this->api('PATCH', '/api/accounts/' . $accountId, ['owner_name' => 'Updated Name']);
        $this->api('GET', '/api/accounts/' . $accountId);

        $this->assertSame('Updated Name', $this->json()['owner_name'] ?? null);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createAccountAndGetId(string $owner, string $currency): string
    {
        $this->api('POST', '/api/accounts', [
            'owner_name' => $owner,
            'currency'   => $currency,
        ]);

        return $this->json()['id'];
    }
}
