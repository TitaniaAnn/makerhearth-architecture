<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Firing;

use DateTimeImmutable;

/**
 * One member's piece dropped off at the front desk for one firing, measured
 * in cubic inches. It's charged at drop-off and usually belongs to no kiln
 * load: standard tracking records drop-off and pick-up only. `billedAt` is the
 * idempotency stamp the ledger re-checks before writing the debit, and it's
 * what makes a refire free (a moved piece keeps it, so nothing charges again).
 * `coveredBy` links a glaze drop-off to the paid bisque drop-off that covers
 * it when one payment covers both firings.
 */
final class Firing
{
    public ?DateTimeImmutable $billedAt = null;

    public ?Firing $coveredBy = null;

    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly int $cubicInches,
        public readonly FiringType $type = FiringType::BISQUE,
    ) {}
}
