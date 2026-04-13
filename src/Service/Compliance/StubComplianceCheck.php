<?php

declare(strict_types=1);

namespace App\Service\Compliance;

use App\Service\Transfer\TransferContext;
use Psr\Log\LoggerInterface;

/**
 * Stub compliance checker that logs screening attempts but does not block.
 *
 * Replace with a real implementation that calls an external sanctions/AML
 * screening provider (Refinitiv, Dow Jones, ComplyAdvantage, etc.)
 * before going to production.
 *
 * Wire a real implementation in services.yaml:
 *   App\Service\Compliance\ComplianceCheckInterface:
 *       class: App\Service\Compliance\RefinitivComplianceCheck
 */
final class StubComplianceCheck implements ComplianceCheckInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function screen(TransferContext $context): void
    {
        $this->logger->info('Compliance screening (stub — no-op)', [
            'source_account'      => $context->getRequest()->sourceAccountId,
            'destination_account' => $context->getRequest()->destinationAccountId,
            'amount'              => $context->getRequest()->amount,
            'currency'            => $context->getRequest()->currency,
        ]);

        // TODO: Replace with real sanctions/PEP/AML screening:
        // 1. Check source account holder against OFAC/UN/EU sanctions lists
        // 2. Check destination account holder against sanctions lists
        // 3. Run transaction pattern through AML detection rules
        // 4. Check PEP (Politically Exposed Person) status
        // 5. Throw ComplianceViolationException if any check fails
    }
}
