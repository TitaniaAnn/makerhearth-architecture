<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Firing;

use DomainException;
use MakerHearth\Architecture\Ledger\FiringLedger;

/**
 * The single billing point for firing: a piece is charged when it's dropped
 * off at the front desk, before it goes anywhere near a kiln. Members pay for
 * kiln space, so nothing in the kiln process charges — which is also why a
 * failed firing is refired for free without any special code.
 *
 * Whether bisque and glaze are one payment or two is a per-studio choice.
 * With one payment, a glaze drop-off names the member's paid bisque drop-off
 * that covers it: stamped paid, no debit, and that bisque payment can't cover
 * another glaze. A glaze drop-off with nothing to cover it (bisqued elsewhere)
 * is charged like any first drop-off.
 *
 * @see ARCHITECTURE.md §4 — "Strictly-forward state machines; charge at drop-off"
 */
final class FiringDropOff
{
    /** @var array<string, true> bisque firing ids whose payment already covered a glaze */
    private array $usedCovers = [];

    public function __construct(
        private FiringLedger $ledger,
        private bool $onePaymentCoversAll = false,
    ) {}

    public function dropOff(Firing $firing, ?Firing $coveredBy = null): void
    {
        if ($coveredBy === null) {
            $this->ledger->consumeFiring($firing);

            return;
        }

        if (! $this->onePaymentCoversAll) {
            throw new DomainException('This studio charges each firing separately.');
        }
        if ($firing->type !== FiringType::GLAZE
            || $coveredBy->type !== FiringType::BISQUE
            || $coveredBy->userId !== $firing->userId
            || $coveredBy->billedAt === null) {
            throw new DomainException('Only a glaze drop-off can be covered, by the same member\'s paid bisque drop-off.');
        }
        if (isset($this->usedCovers[$coveredBy->id])) {
            throw new DomainException('That bisque payment has already covered a glaze firing.');
        }

        $this->usedCovers[$coveredBy->id] = true;
        $firing->coveredBy = $coveredBy;
        $firing->billedAt = new \DateTimeImmutable(); // paid at bisque; nothing will charge it
    }
}
