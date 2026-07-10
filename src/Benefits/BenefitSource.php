<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Benefits;

/**
 * Anything that can grant BenefitPackages to a user.
 *
 * Production has four implementations — active Memberships (primary +
 * family roster), active PassPurchases, active VolunteerAssignments (via
 * VolunteerRole), and active BoardTerms (via BoardPosition) — each an
 * Eloquent relation with an "active" scope. The load-bearing rule is that
 * NEW SOURCES PLUG IN HERE, never at call sites: cart pricing, kiosk
 * eligibility, and firing consume all ask the resolver, so a fifth source
 * (say, a scholarship) is one class + one registration, not a sweep of
 * every pricing branch in the codebase.
 *
 * @see ARCHITECTURE.md §5 — "Benefit resolution across sources"
 */
interface BenefitSource
{
    /**
     * Every package this source currently grants to the user.
     *
     * @return list<BenefitPackage>
     */
    public function activePackagesFor(string $userId): array;
}
