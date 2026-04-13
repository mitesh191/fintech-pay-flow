<?php

declare(strict_types=1);

namespace App\Exception;

final class ReversalNotAllowedException extends \DomainException
{
    public function __construct(string $reason)
    {
        parent::__construct(sprintf('Reversal not allowed: %s', $reason));
    }
}
