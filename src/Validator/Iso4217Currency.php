<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Validates that a currency code is a real ISO 4217 currency.
 *
 * Structural regex (/^[A-Z]{3}$/) catches malformed codes but allows
 * fantasy currencies like 'ZZZ' or 'AAA'. This constraint ensures only
 * real ISO 4217 codes are accepted — a compliance requirement for any
 * payment system that interfaces with banking rails.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class Iso4217Currency extends Constraint
{
    public string $message = 'The currency "{{ value }}" is not a valid ISO 4217 currency code.';
}
