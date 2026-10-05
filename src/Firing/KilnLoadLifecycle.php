<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Firing;

/**
 * Drives the KilnLoad lifecycle. All transitions go through the named
 * methods here; illegal moves throw InvalidKilnLoadTransition. No transition
 * charges anything: a piece is paid for when it's dropped off at the front
 * desk (FiringDropOff), so billing never depends on what a load contains.
 *
 * Why charge at drop-off and not at unload: members pay for space in the
 * kiln, not for how a piece comes out, and the front desk takes the money
 * when the piece is handed in. A piece that cracks in a firing that
 * completes stays charged (a refund is a staff adjustment, case by case).
 * A firing that fails (ABORTED) is refired for free — and that needs no
 * special code, because nothing in the kiln process charges: in detailed
 * tracking refireAbortedLoad() moves the pieces to a new load, and each keeps
 * its billedAt stamp.
 *
 * @see ARCHITECTURE.md §4 — "Strictly-forward state machines; charge at drop-off"
 */
final class KilnLoadLifecycle
{
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

    public function markUnloaded(KilnLoad $load): void
    {
        $this->transition($load, KilnLoadStatus::UNLOADED);
    }

    /** The firing failed. Touches no pieces and no ledger. */
    public function abort(KilnLoad $load): void
    {
        $this->transition($load, KilnLoadStatus::ABORTED);
    }

    /**
     * Detailed tracking: move an aborted load's pieces into a load that's
     * still LOADING. They keep billedAt, so nothing charges them again.
     * Returns how many pieces moved.
     */
    public function refireAbortedLoad(KilnLoad $aborted, KilnLoad $target): int
    {
        if ($aborted->status !== KilnLoadStatus::ABORTED) {
            throw new InvalidKilnLoadTransition('Only pieces from an aborted load can be refired.');
        }
        if ($target->status !== KilnLoadStatus::LOADING) {
            throw new InvalidKilnLoadTransition('Pieces can only be refired into a load that is still LOADING.');
        }

        $moved = $aborted->takeAllFirings();
        foreach ($moved as $firing) {
            $target->addFiring($firing);
        }

        return count($moved);
    }

    private function transition(KilnLoad $load, KilnLoadStatus $to): void
    {
        if (! $load->status->canTransitionTo($to)) {
            throw InvalidKilnLoadTransition::between($load->status, $to);
        }

        $load->forceStatus($to);
    }
}
