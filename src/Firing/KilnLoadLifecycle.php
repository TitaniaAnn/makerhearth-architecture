<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Firing;

use MakerHearth\Architecture\Ledger\FiringLedger;

/**
 * Drives the KilnLoad lifecycle. All transitions go through the named
 * methods here; illegal moves throw InvalidKilnLoadTransition. The single
 * billing trigger is markUnloaded() — the LAST transition — which consumes
 * firing credit for every piece in the load.
 *
 * Why consume-on-unload and not consume-on-add: a load can be aborted (kiln
 * fault, glaze disaster) at any point before it's unloaded. Members must
 * never pay for pieces that didn't come out of a successful firing, and the
 * cleanest way to guarantee that is to bill at the one moment success is
 * known. The state graph enforces it structurally: ABORTED can never reach
 * UNLOADED, and only the UNLOADED transition calls the ledger.
 *
 * @see ARCHITECTURE.md §4 — "Strictly-forward state machines; consume on unload"
 */
final class KilnLoadLifecycle
{
    public function __construct(
        private FiringLedger $ledger,
    ) {}

    public function markReadyToFire(KilnLoad $load): void
    {
        $this->transition($load, KilnLoadStatus::READY_TO_FIRE);
    }

    public function markFiring(KilnLoad $load): void
    {
        $this->transition($load, KilnLoadStatus::FIRING);
    }

    public function markCooling(KilnLoad $load): void
    {
        $this->transition($load, KilnLoadStatus::COOLING);
    }

    public function markReadyToUnload(KilnLoad $load): void
    {
        $this->transition($load, KilnLoadStatus::READY_TO_UNLOAD);
    }

    /** The final transition — and the ONLY place firing credit is consumed. */
    public function markUnloaded(KilnLoad $load): void
    {
        $this->transition($load, KilnLoadStatus::UNLOADED);

        foreach ($load->firings() as $firing) {
            $this->ledger->consumeFiring($firing);
        }
    }

    /**
     * Abort from any pre-terminal state. Pieces in the load never bill —
     * consumption only happens on UNLOADED, which ABORTED cannot follow.
     */
    public function abort(KilnLoad $load): void
    {
        $this->transition($load, KilnLoadStatus::ABORTED);
    }

    private function transition(KilnLoad $load, KilnLoadStatus $to): void
    {
        if (! $load->status->canTransitionTo($to)) {
            throw InvalidKilnLoadTransition::between($load->status, $to);
        }

        $load->forceStatus($to);
    }
}
