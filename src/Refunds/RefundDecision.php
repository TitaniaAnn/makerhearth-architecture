<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Refunds;

/**
 * The output of a RefundPolicy. Decisions are *values*, not actions — in
 * production they flow through RefundService::applyDecision* to become
 * OrderRefund rows (money movement, Stripe, order-total rollups).
 *
 * Don't issue refunds inside cancellation services; mixing the business
 * decision with money movement is what made an earlier iteration of the
 * refund flow brittle. The split keeps "what refund is owed" pure and
 * unit-testable, and lets Stripe-less studios settle PENDING refunds
 * out-of-band without touching policy.
 *
 * This is the production class verbatim (only the namespace differs).
 *
 * @see ARCHITECTURE.md §6 — "Refund decisions are values, not actions"
 */
final readonly class RefundDecision
{
    public function __construct(
        public RefundTier $tier,
        public int $amountCents,
        public string $reason,
    ) {}

    public static function none(string $reason): self
    {
        return new self(RefundTier::NONE, 0, $reason);
    }

    public function isRefundable(): bool
    {
        return $this->amountCents > 0 && $this->tier !== RefundTier::NONE;
    }
}
