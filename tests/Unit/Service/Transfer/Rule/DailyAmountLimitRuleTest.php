<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Transfer\Rule;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\Exception\DailyLimitExceededException;
use App\Repository\TransactionRepositoryInterface;
use App\Service\Transfer\Rule\DailyAmountLimitRule;
use App\Service\Transfer\TransferContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DailyAmountLimitRule (priority=40).
 *
 * Fintech velocity controls: source cannot send more than the configured
 * daily limit in a rolling calendar day.  Fee is included in the velocity
 * check to prevent the fee-bypass loophole.
 */
final class DailyAmountLimitRuleTest extends TestCase
{
    private TransactionRepositoryInterface&MockObject $txRepo;

    protected function setUp(): void
    {
        $this->txRepo = $this->createMock(TransactionRepositoryInterface::class);
    }

    public function test_priority_is_40(): void
    {
        $rule = new DailyAmountLimitRule($this->txRepo);
        $this->assertSame(40, $rule->getPriority());
    }

    public function test_passes_when_under_daily_limit(): void
    {
        $this->txRepo->method('sumSentTodayByAccount')->willReturn('1000.0000');

        $rule = new DailyAmountLimitRule($this->txRepo, defaultDailyLimit: '50000.0000');
        $ctx  = $this->context('100.00', 'USD');

        $rule->apply($ctx);
        $this->addToAssertionCount(1);
    }

    public function test_passes_on_exact_limit(): void
    {
        $this->txRepo->method('sumSentTodayByAccount')->willReturn('49900.0000');

        $rule = new DailyAmountLimitRule($this->txRepo, defaultDailyLimit: '50000.0000');
        $ctx  = $this->context('100.00', 'USD');

        $rule->apply($ctx);
        $this->addToAssertionCount(1);
    }

    public function test_throws_when_over_daily_limit(): void
    {
        $this->txRepo->method('sumSentTodayByAccount')->willReturn('49950.0000');

        $rule = new DailyAmountLimitRule($this->txRepo, defaultDailyLimit: '50000.0000');
        $ctx  = $this->context('100.00', 'USD');

        $this->expectException(DailyLimitExceededException::class);
        $rule->apply($ctx);
    }

    public function test_throws_when_single_transfer_alone_exceeds_limit(): void
    {
        $this->txRepo->method('sumSentTodayByAccount')->willReturn('0.0000');

        $rule = new DailyAmountLimitRule($this->txRepo, defaultDailyLimit: '50000.0000');
        $ctx  = $this->context('60000.00', 'USD');

        $this->expectException(DailyLimitExceededException::class);
        $rule->apply($ctx);
    }

    public function test_uses_currency_specific_limit_when_configured(): void
    {
        $this->txRepo->method('sumSentTodayByAccount')->willReturn('9900.0000');

        $rule = new DailyAmountLimitRule(
            $this->txRepo,
            defaultDailyLimit: '50000.0000',
            dailyLimitByCurrency: ['EUR' => '10000.0000'],
        );
        $ctx = $this->context('200.00', 'EUR');

        $this->expectException(DailyLimitExceededException::class);
        $rule->apply($ctx);
    }

    public function test_falls_back_to_default_limit_for_unconfigured_currency(): void
    {
        $this->txRepo->method('sumSentTodayByAccount')->willReturn('1000.0000');

        $rule = new DailyAmountLimitRule(
            $this->txRepo,
            defaultDailyLimit: '50000.0000',
            dailyLimitByCurrency: ['EUR' => '10000.0000'],
        );
        $ctx = $this->context('100.00', 'USD'); // USD not in map → use default

        $rule->apply($ctx);
        $this->addToAssertionCount(1);
    }

    public function test_fee_included_in_velocity_check(): void
    {
        // Sent 49900 today. Transfer 50 + fee 60 = 110 projected total = 50010 > 50000
        $this->txRepo->method('sumSentTodayByAccount')->willReturn('49900.0000');

        $rule = new DailyAmountLimitRule($this->txRepo, defaultDailyLimit: '50000.0000');
        $ctx  = $this->context('50.00', 'USD', feeAmount: '60.0000');

        $this->expectException(DailyLimitExceededException::class);
        $rule->apply($ctx);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function context(string $amount, string $currency, string $feeAmount = '0.0000'): TransferContext
    {
        $request = new TransferRequest('src', 'dst', $amount, $currency, 'k1');

        return TransferContext::create($request, 'caller', hash('sha256', 'caller:k1'))
            ->withAccounts(new Account('Alice', $currency), new Account('Bob', $currency))
            ->withFeeAmount($feeAmount);
    }
}
