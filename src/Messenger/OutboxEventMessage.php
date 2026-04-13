<?php

declare(strict_types=1);

namespace App\Messenger;

/**
 * Typed Messenger message for outbox event relay.
 *
 * Replaces the anonymous stdClass that was previously dispatched,
 * enabling proper Messenger handler type-hinting, serialization, and routing.
 */
final class OutboxEventMessage
{
    public function __construct(
        public readonly string $eventType,
        public readonly string $aggregateId,
        /** @var array<string, mixed> */
        public readonly array  $payload,
    ) {}
}
