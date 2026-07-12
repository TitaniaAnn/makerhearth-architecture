<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Benefits;

/**
 * The resolved answer to "what does this user get right now."
 * An immutable value object — the DataObject pattern used throughout
 * the platform (RefundDecision, ConsumeDecision, …).
 *
 * @see ARCHITECTURE.md §5 — "Benefit resolution across sources"
 */
final readonly class EffectiveBenefits
{
    public function __construct(
        public int $clayDiscountPercent,
        public int $studioTimeDiscountPercent,
        public int $firingDiscountPercent,
        public int $classDiscountPercent,
        public int $lessonDiscountPercent,
        public int $eventDiscountPercent,
        public bool $unlimitedStudioTime,
    ) {}

    public static function none(): self
    {
        return new self(0, 0, 0, 0, 0, 0, false);
    }
}
