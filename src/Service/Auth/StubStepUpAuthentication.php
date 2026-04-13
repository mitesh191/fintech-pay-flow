<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Psr\Log\LoggerInterface;

/**
 * Stub step-up authentication that always passes.
 *
 * FOR DEVELOPMENT AND TESTING ONLY.
 *
 * Replace with a real implementation (TOTP, push challenge, SMS OTP) before
 * production launch.  Wire a real implementation in services.yaml:
 *
 *   App\Service\Auth\StepUpAuthenticationInterface:
 *       class: App\Service\Auth\TotpStepUpAuthentication
 */
final class StubStepUpAuthentication implements StepUpAuthenticationInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function verify(string $callerApiKeyId, string $token): bool
    {
        $this->logger->info('Step-up authentication (stub — always passes)', [
            'caller_api_key_id' => $callerApiKeyId,
        ]);

        return true;
    }
}
