<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle of an outbox event row.
 *
 * State machine:
 *   PENDING → PROCESSING → PUBLISHED
 *                        ↘ FAILED (after max_retries exhausted)
 *
 * The ProcessOutboxCommand transitions rows through this machine.
 * Only PENDING and FAILED rows (below max_retries) are selected for relay.
 */
enum OutboxStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Published  = 'published';
    case Failed     = 'failed';
}
