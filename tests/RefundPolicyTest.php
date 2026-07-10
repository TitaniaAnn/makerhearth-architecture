<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Tests;

use MakerHearth\Architecture\Refunds\RefundPolicy;
use MakerHearth\Architecture\Refunds\RefundTier;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ARCHITECTURE.md §6 — refund decisions are pure values computed
 * from window math, with no order, Stripe, or database anywhere in sight.
 */
final class RefundPolicyTest extends TestCase
{
    private RefundPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new RefundPolicy(
            fullRefundUntilHoursBefore: 48,
            partialRefundUntilHoursBefore: 24,
            partialRefundPercent: 50,
        );
    }

    public function test_full_refund_outside_the_partial_window(): void
    {
        $decision = $this->policy->decide(amountPaidCents: 24000, hoursBeforeStart: 72);

        self::assertSame(RefundTier::FULL, $decision->tier);
        self::assertSame(24000, $decision->amountCents);
        self::assertTrue($decision->isRefundable());
    }

    public function test_partial_refund_inside_the_partial_window(): void
    {
        $decision = $this->policy->decide(amountPaidCents: 24000, hoursBeforeStart: 30);

        self::assertSame(RefundTier::PARTIAL, $decision->tier);
        self::assertSame(12000, $decision->amountCents);
    }

    public function test_partial_amount_uses_bankers_rounding(): void
    {
        // 50% of 4001 = 2000.5 → 2000 (HALF_EVEN via MoneyMath).
        self::assertSame(2000, $this->policy->decide(4001, 30)->amountCents);
    }

    public function test_no_refund_inside_the_no_refund_window(): void
    {
        $decision = $this->policy->decide(amountPaidCents: 24000, hoursBeforeStart: 2);

        self::assertSame(RefundTier::NONE, $decision->tier);
        self::assertSame(0, $decision->amountCents);
        self::assertFalse($decision->isRefundable());
    }

    public function test_window_boundaries_are_inclusive(): void
    {
        self::assertSame(RefundTier::FULL, $this->policy->decide(24000, 48)->tier);
        self::assertSame(RefundTier::PARTIAL, $this->policy->decide(24000, 24)->tier);
    }

    public function test_nothing_paid_means_nothing_owed(): void
    {
        self::assertFalse($this->policy->decide(0, 100)->isRefundable());
    }
}
