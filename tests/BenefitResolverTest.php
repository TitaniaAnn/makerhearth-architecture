<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Tests;

use MakerHearth\Architecture\Benefits\BenefitPackage;
use MakerHearth\Architecture\Benefits\BenefitResolver;
use MakerHearth\Architecture\Benefits\BenefitSource;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ARCHITECTURE.md §5 — benefits aggregate across sources with
 * max-for-discounts (never stacking) and OR-for-booleans, and a user with
 * no sources resolves to none() rather than null.
 */
final class BenefitResolverTest extends TestCase
{
    public function test_discounts_take_the_max_across_sources_never_stack(): void
    {
        $membership = self::sourceGranting('mara', new BenefitPackage('Full member', classDiscountPercent: 15, firingDiscountPercent: 10));
        $board = self::sourceGranting('mara', new BenefitPackage('Board', classDiscountPercent: 25, firingDiscountPercent: 5));

        $benefits = new BenefitResolver([$membership, $board])->for('mara');

        self::assertSame(25, $benefits->classDiscountPercent);  // max(15, 25), not 40
        self::assertSame(10, $benefits->firingDiscountPercent); // max(10, 5) — per-field, not per-package
    }

    public function test_booleans_or_across_sources(): void
    {
        $membership = self::sourceGranting('mara', new BenefitPackage('Basic', unlimitedStudioTime: false));
        $pass = self::sourceGranting('mara', new BenefitPackage('Open-studio pass', unlimitedStudioTime: true));

        self::assertTrue(new BenefitResolver([$membership, $pass])->for('mara')->unlimitedStudioTime);
    }

    public function test_no_sources_resolves_to_none(): void
    {
        $benefits = new BenefitResolver([])->for('mara');

        self::assertSame(0, $benefits->classDiscountPercent);
        self::assertFalse($benefits->unlimitedStudioTime);
    }

    public function test_sources_only_grant_to_their_own_user(): void
    {
        $membership = self::sourceGranting('mara', new BenefitPackage('Full member', classDiscountPercent: 15));

        self::assertSame(0, new BenefitResolver([$membership])->for('theo')->classDiscountPercent);
    }

    private static function sourceGranting(string $userId, BenefitPackage $package): BenefitSource
    {
        return new readonly class($userId, $package) implements BenefitSource
        {
            public function __construct(
                private string $userId,
                private BenefitPackage $package,
            ) {}

            public function activePackagesFor(string $userId): array
            {
                return $userId === $this->userId ? [$this->package] : [];
            }
        };
    }
}
