<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Shared integer-cent money math.
 *
 * Money is stored as integer cents in a single tenant currency. All
 * proportional cent math (discounts, revenue splits, proration) MUST route
 * through here so rounding is consistent and customer-fair — never
 * `intdiv($cents * $percent, 100)`, which short-changes the customer on
 * every odd-half case.
 *
 * Uses banker's rounding (HALF_EVEN). Computation stays in exact rational
 * space (no floats — brick/math deprecates float arguments) and rounds
 * exactly once.
 *
 * This is the production class verbatim (only the namespace differs).
 *
 * @see ARCHITECTURE.md §2 — "Money is integer cents with banker's rounding"
 */
final class MoneyMath
{
    /**
     * `percent` of `cents`, rounded HALF_EVEN to whole cents.
     *
     * Example: 15% of 24000 = 3600; 50% of 4001 = 2000 (2000.5 → even).
     */
    public static function percentOfCents(int $cents, float|int $percent): int
    {
        if ($cents === 0 || (float) $percent === 0.0) {
            return 0;
        }

        return BigRational::of((string) $percent)
            ->dividedBy(100)
            ->multipliedBy($cents)
            ->toScale(0, RoundingMode::HALF_EVEN)
            ->toInt();
    }

    /**
     * The amount remaining after applying a percentage discount.
     */
    public static function applyDiscount(int $cents, float|int $discountPercent): int
    {
        return $cents - self::percentOfCents($cents, $discountPercent);
    }

    /**
     * Pay for `hours` worked at `ratePerHourCents`, rounded HALF_EVEN to
     * whole cents. Hours may carry fractional values (1.5, 1.75); kept in
     * exact decimal space (no float) so payroll totals are reproducible.
     *
     * Example: 2.5h @ $20/hr (2000c) = 5000c.
     */
    public static function payForHours(int $ratePerHourCents, float|int|string $hours): int
    {
        if ($ratePerHourCents === 0) {
            return 0;
        }

        return BigDecimal::of((string) $hours)
            ->multipliedBy($ratePerHourCents)
            ->toScale(0, RoundingMode::HALF_EVEN)
            ->toInt();
    }

    /**
     * `quantity × unitPriceCents` to whole cents, HALF_EVEN — for any line
     * that carries a fractional quantity (studio-time hours, per-cubic-inch
     * firing). Kept in exact decimal space so it agrees with the rest of the
     * system's banker's rounding instead of float round-half-away-from-zero.
     */
    public static function multiplyCents(int $unitPriceCents, float|int|string $quantity): int
    {
        if ($unitPriceCents === 0) {
            return 0;
        }

        return BigDecimal::of((string) $quantity)
            ->multipliedBy($unitPriceCents)
            ->toScale(0, RoundingMode::HALF_EVEN)
            ->toInt();
    }
}
