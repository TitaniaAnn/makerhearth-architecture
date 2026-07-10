<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Benefits;

/**
 * A named bundle of benefits. In production this is an Eloquent model
 * referenced by the four benefit *sources* — MembershipTier, PassType,
 * VolunteerRole, BoardPosition — so one package definition can back many
 * grant mechanisms.
 *
 * @see ARCHITECTURE.md §5 — "Benefit resolution across sources"
 */
final readonly class BenefitPackage
{
    public function __construct(
        public string $name,
        public int $clayDiscountPercent = 0,
        public int $studioTimeDiscountPercent = 0,
        public int $firingDiscountPercent = 0,
        public int $classDiscountPercent = 0,
        public int $lessonDiscountPercent = 0,
        public int $eventDiscountPercent = 0,
        public bool $unlimitedStudioTime = false,
    ) {}
}
