<?php

declare(strict_types=1);

namespace App\Service\Compliance;

use App\Service\Transfer\TransferContext;

/**
 * Pre-transfer compliance check hook (KYC/AML/Sanctions).
 *
 * Implementations should call external providers (Refinitiv, Dow Jones,
 * ComplyAdvantage, etc.) to screen accounts and transactions against
 * sanctions lists (OFAC, UN, EU), PEP databases, and AML patterns.
 *
 * Throws SanctionsViolationException or ComplianceCheckFailedException
 * to halt the transfer pipeline.
 */
interface ComplianceCheckInterface
{
    /**
     * Screen the transfer for compliance violations.
     *
     * @throws \App\Exception\ComplianceViolationException
     */
    public function screen(TransferContext $context): void;
}
