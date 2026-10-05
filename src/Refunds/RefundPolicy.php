<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Refunds;

use MakerHearth\Architecture\Support\MoneyMath;

/**
 * The class-cancellation refund policy: pure window math over
 * hours-before-start, returning a RefundDecision value. Production has two
 * policies shaped exactly like this (ClassRefundPolicy, PartyRefundPolicy):
 * same tiers, different windows, one movement path.
 *
 * Note what is absent: no order, no Stripe, no database. That absence is
 * the design — the policy can be tested exhaustively as arithmetic
 * (see tests/RefundPolicyTest.php), and the movement half never needs to
 * re-derive amounts.
 *
 * @see ARCHITECTURE.md §6 — "Refund decisions are values, not actions"
 */
final readonly class RefundPolicy
{
    public function __construct(
        public int $fullRefundUntilHoursBefore = 48,
        public int $partialRefundUntilHoursBefore = 24,
        public int $partialRefundPercent = 50,
    ) {}

    public function decide(int $amountPaidCents, float $hoursBeforeStart): RefundDecision
    {
        if ($amountPaidCents <= 0) {
            return RefundDecision::none('Nothing was paid.');
        }

        if ($hoursBeforeStart >= $this->fullRefundUntilHoursBefore) {
            return new RefundDecision(
                RefundTier::FULL,
                $amountPaidCents,
                "Cancelled {$this->fullRefundUntilHoursBefore}+ hours before start.",
            );
        }

        if ($hoursBeforeStart >= $this->partialRefundUntilHoursBefore) {
            return new RefundDecision(
                RefundTier::PARTIAL,
                MoneyMath::percentOfCents($amountPaidCents, $this->partialRefundPercent),
                "Cancelled inside the partial window ({$this->partialRefundPercent}%).",
            );
        }

        return RefundDecision::none('Cancelled inside the no-refund window.');
    }
}
