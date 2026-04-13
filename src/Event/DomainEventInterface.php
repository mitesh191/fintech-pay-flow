<?php

declare(strict_types=1);

namespace App\Event;

/**
 * Marker interface for all domain events emitted by this bounded context.
 *
 * Domain events are immutable value objects representing something that
 * happened — they are named in the past tense.  They are serialised into
 * OutboxEvent.payload and relayed to downstream consumers (analytics,
 * notifications, fraud detection, reconciliation) without coupling the
 * transaction pipeline to any specific broker technology.
 */
interface DomainEventInterface
{
    /** Canonical event name. Snake_case, namespaced: 'transfer.completed'. */
    public function getEventType(): string;

    /** UUID of the root aggregate this event belongs to. */
    public function getAggregateId(): string;

    /** Occurred-at timestamp for event ordering. */
    public function getOccurredAt(): \DateTimeImmutable;

    /**
     * JSON-serialisable payload.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array;
}
