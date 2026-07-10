<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Benefits;

/**
 * The single contract for "what does this user get." Cart pricing, kiosk
 * eligibility and firing consume all call this — they never iterate sources
 * themselves.
 *
 * Aggregation semantics are the whole point of this class and they are
 * deliberate:
 *
 *   - discounts   → MAX  (the best single discount wins; discounts from two
 *                         sources never stack)
 *   - booleans    → OR   (any source granting unlimited studio time grants it)
 *   - allowances  → SUM  (in production, e.g. monthly guest passes add up —
 *                         added to EffectiveBenefits as consuming code needs
 *                         them)
 *
 * In production the sources are Eloquent relations with "active" scopes;
 * here they are anything implementing BenefitSource, which is exactly why
 * the aggregation logic can be tested without a database.
 *
 * @see ARCHITECTURE.md §5 — "Benefit resolution across sources"
 */
final class BenefitResolver
{
    /** @param list<BenefitSource> $sources */
    public function __construct(
        private array $sources,
    ) {}

    public function for(string $userId): EffectiveBenefits
    {
        $packages = [];
        foreach ($this->sources as $source) {
            foreach ($source->activePackagesFor($userId) as $package) {
                $packages[] = $package;
            }
        }

        if ($packages === []) {
            return EffectiveBenefits::none();
        }

        return new EffectiveBenefits(
            clayDiscountPercent: max(array_map(fn (BenefitPackage $p) => $p->clayDiscountPercent, $packages)),
            studioTimeDiscountPercent: max(array_map(fn (BenefitPackage $p) => $p->studioTimeDiscountPercent, $packages)),
            firingDiscountPercent: max(array_map(fn (BenefitPackage $p) => $p->firingDiscountPercent, $packages)),
            classDiscountPercent: max(array_map(fn (BenefitPackage $p) => $p->classDiscountPercent, $packages)),
            lessonDiscountPercent: max(array_map(fn (BenefitPackage $p) => $p->lessonDiscountPercent, $packages)),
            eventDiscountPercent: max(array_map(fn (BenefitPackage $p) => $p->eventDiscountPercent, $packages)),
            unlimitedStudioTime: array_any($packages, fn (BenefitPackage $p) => $p->unlimitedStudioTime),
        );
    }
}
