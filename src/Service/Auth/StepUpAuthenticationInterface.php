<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Verifies step-up (second-factor) authentication tokens for high-value transfers.
 *
 * PSD2 Strong Customer Authentication (SCA) requires a second factor for
 * payment initiation above certain thresholds. This interface abstracts the
 * mechanism (TOTP, SMS OTP, push challenge, hardware token) so the transfer
 * pipeline is decoupled from the specific SCA implementation.
 *
 * Production implementations should:
 *   - Validate the token against a time-based OTP (TOTP RFC 6238)
 *   - Or verify a signed challenge-response from a mobile SDK
 *   - Enforce single-use: a token must not be replayable
 *   - Rate-limit verification attempts per caller
 */
interface StepUpAuthenticationInterface
{
    /**
     * Verify a step-up token for the given caller.
     *
     * @param string $callerApiKeyId The API key ID of the authenticated caller
     * @param string $token          The step-up token (OTP, signed challenge, etc.)
     * @return bool true if the token is valid
     */
    public function verify(string $callerApiKeyId, string $token): bool;
}
