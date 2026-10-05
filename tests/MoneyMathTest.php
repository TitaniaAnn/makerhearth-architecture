<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Tests;

use MakerHearth\Architecture\Support\MoneyMath;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ARCHITECTURE.md §2 — money is integer cents with banker's
 * rounding (HALF_EVEN), and proportional math never short-changes the
 * customer the way `intdiv($cents * $percent, 100)` does on odd halves.
 */
final class MoneyMathTest extends TestCase
{
    public function test_percent_of_cents_exact_case(): void
    {
        self::assertSame(3600, MoneyMath::percentOfCents(24000, 15));
    }

    public function test_odd_half_cases_round_half_even(): void
    {
        // 50% of 4001 = 2000.5 → rounds to the EVEN neighbour, 2000.
        self::assertSame(2000, MoneyMath::percentOfCents(4001, 50));

        // 50% of 4003 = 2001.5 → rounds to the EVEN neighbour, 2002.
        // intdiv would give 2001 both times — biased against one party.
        self::assertSame(2002, MoneyMath::percentOfCents(4003, 50));
    }

    public function test_fractional_percent_stays_exact(): void
    {
        // 2.5% of 10000 = 250 exactly — no float drift.
        self::assertSame(250, MoneyMath::percentOfCents(10000, 2.5));
    }

    public function test_decimal_string_percent_stays_exact(): void
    {
        // Production sales-tax rates are decimal(7,4) columns that arrive as
        // strings ("8.475"). 8.475% of 2000 = 169.5 → even neighbour 170;
        // 8.475% of 6000 = 508.5 → even neighbour 508. No float on the way.
        self::assertSame(170, MoneyMath::percentOfCents(2000, '8.475'));
        self::assertSame(508, MoneyMath::percentOfCents(6000, '8.475'));
    }

    public function test_proportion_of_cents_splits_a_refund_half_even(): void
    {
        // The tax share of a partial refund: 1000 refunded on a 1085 order
        // that carried 85 tax → 78.34 → 78.
        self::assertSame(78, MoneyMath::proportionOfCents(1000, 85, 1085));

        // Odd halves land on the even neighbour, same as percentOfCents.
        self::assertSame(2, MoneyMath::proportionOfCents(5, 1, 2));
        self::assertSame(4, MoneyMath::proportionOfCents(7, 1, 2));

        // A zero denominator means "nothing to split", not a division error.
        self::assertSame(0, MoneyMath::proportionOfCents(1000, 85, 0));
    }

    public function test_apply_discount_is_complement_of_percent(): void
    {
        $cents = 4001;
        self::assertSame(
            $cents,
            MoneyMath::applyDiscount($cents, 50) + MoneyMath::percentOfCents($cents, 50),
        );
    }

    public function test_pay_for_fractional_hours(): void
    {
        self::assertSame(5000, MoneyMath::payForHours(2000, 2.5));
        self::assertSame(3500, MoneyMath::payForHours(2000, 1.75));
    }

    public function test_multiply_cents_fractional_quantity_half_even(): void
    {
        // 1.5 hours × 333c = 499.5 → 500 (even neighbour is 500).
        self::assertSame(500, MoneyMath::multiplyCents(333, 1.5));
    }

    public function test_zero_short_circuits(): void
    {
        self::assertSame(0, MoneyMath::percentOfCents(0, 50));
        self::assertSame(0, MoneyMath::percentOfCents(1000, 0));
        self::assertSame(0, MoneyMath::payForHours(0, 10));
    }
}
