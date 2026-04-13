<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Transfer\Rule;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\Exception\StepUpRequiredException;
use App\Service\Auth\StepUpAuthenticationInterface;
use App\Service\Transfer\Rule\StepUpRequiredRule;
use App\Service\Transfer\TransferContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for StepUpRequiredRule (priority=45).
 *
 * PSD2 SCA compliance: high-value transfers must present a valid
 * X-Step-Up-Token header.  Below threshold: rule is skipped entirely.
 */
final class StepUpRequiredRuleTest extends TestCase
{
    private StepUpAuthenticationInterface&MockObject $stepUpAuth;
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->stepUpAuth = $this->createMock(StepUpAuthenticationInterface::class);
        $this->requestStack = new RequestStack();
    }

    public function test_priority_is_45(): void
    {
        $rule = new StepUpRequiredRule($this->stepUpAuth, $this->requestStack);
        $this->assertSame(45, $rule->getPriority());
    }

    public function test_passes_under_threshold_without_token(): void
    {
        $this->pushRequest([]); // no step-up token header

        $rule = new StepUpRequiredRule(
            $this->stepUpAuth,
            $this->requestStack,
            defaultThreshold: '500.0000',
        );

        $ctx = $this->context('400.00', 'USD'); // below 500

        $rule->apply($ctx);
        $this->addToAssertionCount(1);
    }

    public function test_passes_exactly_at_threshold(): void
    {
        $this->pushRequest([]);

        $rule = new StepUpRequiredRule(
            $this->stepUpAuth,
            $this->requestStack,
            defaultThreshold: '500.0000',
        );

        $ctx = $this->context('500.00', 'USD'); // == threshold: no SCA needed

        $rule->apply($ctx);
        $this->addToAssertionCount(1);
    }

    public function test_throws_above_threshold_without_token(): void
    {
        $this->pushRequest([]); // Token header absent

        $rule = new StepUpRequiredRule(
            $this->stepUpAuth,
            $this->requestStack,
            defaultThreshold: '500.0000',
        );
        $ctx = $this->context('600.00', 'USD');

        $this->expectException(StepUpRequiredException::class);
        $rule->apply($ctx);
    }

    public function test_throws_above_threshold_with_invalid_token(): void
    {
        $this->pushRequest(['X-Step-Up-Token' => 'invalid-token']);
        $this->stepUpAuth->method('verify')->willReturn(false);

        $rule = new StepUpRequiredRule(
            $this->stepUpAuth,
            $this->requestStack,
            defaultThreshold: '500.0000',
        );
        $ctx = $this->context('600.00', 'USD');

        $this->expectException(StepUpRequiredException::class);
        $rule->apply($ctx);
    }

    public function test_passes_above_threshold_with_valid_token(): void
    {
        $this->pushRequest(['X-Step-Up-Token' => 'valid-otp']);
        $this->stepUpAuth->method('verify')->willReturn(true);

        $rule = new StepUpRequiredRule(
            $this->stepUpAuth,
            $this->requestStack,
            defaultThreshold: '500.0000',
        );
        $ctx = $this->context('600.00', 'USD');

        $rule->apply($ctx);
        $this->addToAssertionCount(1);
    }

    public function test_uses_currency_specific_threshold(): void
    {
        $this->pushRequest([]); // no token

        $rule = new StepUpRequiredRule(
            $this->stepUpAuth,
            $this->requestStack,
            defaultThreshold:    '500.0000',
            thresholdByCurrency: ['EUR' => '300.0000'],
        );
        $ctx = $this->context('350.00', 'EUR'); // above EUR threshold of 300

        $this->expectException(StepUpRequiredException::class);
        $rule->apply($ctx);
    }

    public function test_verify_called_with_caller_id_and_token(): void
    {
        $this->pushRequest(['X-Step-Up-Token' => 'otp-123']);

        $this->stepUpAuth
            ->expects($this->once())
            ->method('verify')
            ->with('my-caller-id', 'otp-123')
            ->willReturn(true);

        $rule = new StepUpRequiredRule(
            $this->stepUpAuth,
            $this->requestStack,
            defaultThreshold: '10.0000',
        );
        $ctx = $this->context('100.00', 'USD', callerId: 'my-caller-id');

        $rule->apply($ctx);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function pushRequest(array $headers): void
    {
        $request = new Request();
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $this->requestStack->push($request);
    }

    private function context(string $amount, string $currency, string $callerId = 'caller-id'): TransferContext
    {
        $request = new TransferRequest('src', 'dst', $amount, $currency, 'k1');

        return TransferContext::create($request, $callerId, hash('sha256', $callerId . ':k1'))
            ->withAccounts(
                new Account('Alice', $currency),
                new Account('Bob',   $currency),
            );
    }
}
