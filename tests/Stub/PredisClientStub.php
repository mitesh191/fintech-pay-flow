<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use Predis\Client;

/**
 * Typed test double base for Predis\Client.
 *
 * Predis\ClientInterface exposes Redis commands as @method PHPDoc annotations
 * dispatched through __call(), not as real PHP interface methods.  PHPUnit
 * cannot configure or spy on __call targets via createMock() alone.
 *
 * solution: declare the commands we need as real abstract methods here so that
 * PHPUnit's createMock(PredisClientStub::class) can generate a full mock with:
 *   - proper method stubs (willReturn / willThrowException)
 *   - call expectations (expects()->method()->with())
 *   - zero use of the deprecated MockBuilder::addMethods() API
 *
 * This follows the PHPUnit 11 guidance: replace addMethods() with a concrete
 * test-double class that declares the methods explicitly.
 */
abstract class PredisClientStub extends Client
{
    // Declare every Redis command used across Infrastructure tests as abstract.
    // Method signatures match the @method annotations on ClientInterface.

    abstract public function get(string $key): ?string;

    /** @param mixed $value */
    abstract public function set(string $key, mixed $value, mixed $options = null): mixed;

    /** @param mixed $value */
    abstract public function setex(string $key, int $seconds, mixed $value): mixed;

    /** @param string|string[] $keyOrKeys */
    abstract public function del(string|array $keyOrKeys, string ...$keys): int;

    abstract public function incr(string $key): int;

    abstract public function expire(string $key, int $seconds, string $expireOption = ''): int;
}
