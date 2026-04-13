<?php

declare(strict_types=1);

namespace App\Exception;

final class CurrencyMismatchException extends \RuntimeException
{
    public function __construct(string $message = 'Source and destination account currencies must match the requested transfer currency.')
    {
        parent::__construct($message);
    }
}