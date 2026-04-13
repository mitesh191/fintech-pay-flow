<?php

declare(strict_types=1);

namespace App\Exception;

final class InsufficientFundsException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Insufficient funds to complete this transfer.');
    }
}