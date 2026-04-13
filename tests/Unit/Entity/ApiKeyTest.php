<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ApiKey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ApiKey entity.
 *
 * Security: Only the SHA-256 hash of the raw token is persisted — the raw
 * value is never stored and cannot be recovered from a DB breach.
 *
 * DDD: ApiKey is an aggregate root for API authentication.
 */
final class ApiKeyTest extends TestCase
{
    public function test_constructor_stores_name(): void
    {
        $key = new ApiKey('My Client', 'raw-token-abc');

        $this->assertSame('My Client', $key->getName());
    }

    public function test_constructor_hashes_raw_token(): void
    {
        $raw = 'test-raw-token-12345';
        $key = new ApiKey('Client', $raw);

        $this->assertSame(hash('sha256', $raw), $key->getKeyHash());
    }

    public function test_raw_token_is_not_stored_in_key_hash(): void
    {
        $raw = 'secret-bearer-value';
        $key = new ApiKey('Client', $raw);

        $this->assertNotSame($raw, $key->getKeyHash());
    }

    public function test_is_active_by_default(): void
    {
        $key = new ApiKey('Client', 'raw');

        $this->assertTrue($key->isActive());
    }

    public function test_revoke_sets_active_false(): void
    {
        $key = new ApiKey('Client', 'raw');
        $key->revoke();

        $this->assertFalse($key->isActive());
    }

    public function test_id_is_uuid_v7(): void
    {
        $key = new ApiKey('Client', 'raw');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $key->getId(),
        );
    }

    public function test_two_keys_have_distinct_ids(): void
    {
        $a = new ApiKey('A', 'raw-a');
        $b = new ApiKey('B', 'raw-b');

        $this->assertNotSame((string) $a->getId(), (string) $b->getId());
    }

    public function test_created_at_is_datetime_immutable(): void
    {
        $key = new ApiKey('Client', 'raw');

        $this->assertInstanceOf(\DateTimeImmutable::class, $key->getCreatedAt());
    }

    public function test_hash_token_static_method_is_sha256(): void
    {
        $raw = 'test-token';

        $this->assertSame(hash('sha256', $raw), ApiKey::hashToken($raw));
    }

    public function test_different_raw_tokens_produce_different_hashes(): void
    {
        $a = ApiKey::hashToken('token-one');
        $b = ApiKey::hashToken('token-two');

        $this->assertNotSame($a, $b);
    }

    public function test_same_raw_token_always_produces_same_hash(): void
    {
        $raw = 'deterministic-token';

        $this->assertSame(ApiKey::hashToken($raw), ApiKey::hashToken($raw));
    }
}
