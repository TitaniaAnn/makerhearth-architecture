<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Tests;

use DomainException;
use MakerHearth\Architecture\Firing\Firing;
use MakerHearth\Architecture\Firing\FiringDropOff;
use MakerHearth\Architecture\Firing\FiringType;
use MakerHearth\Architecture\Firing\InvalidKilnLoadTransition;
use MakerHearth\Architecture\Firing\KilnLoad;
use MakerHearth\Architecture\Firing\KilnLoadLifecycle;
use MakerHearth\Architecture\Firing\KilnLoadStatus;
use MakerHearth\Architecture\Ledger\FiringLedger;
use MakerHearth\Architecture\Ledger\LedgerEntryType;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ARCHITECTURE.md §4 — the kiln-load state machine is strictly
 * forward, a piece is charged once at drop-off and never by a kiln step, and
 * a failed firing is refired for free.
 */
final class KilnLoadLifecycleTest extends TestCase
{
    private FiringLedger $ledger;

    private KilnLoadLifecycle $lifecycle;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->ledger = new FiringLedger($db);
        $this->lifecycle = new KilnLoadLifecycle;
    }

    public function test_a_piece_is_charged_once_at_drop_off_and_never_by_the_kiln(): void
    {
        $this->ledger->credit('mara', 500, LedgerEntryType::PURCHASE);
        $piece = new Firing('f-1', 'mara', 120);

        $desk = new FiringDropOff($this->ledger);
        $desk->dropOff($piece);
        $desk->dropOff($piece); // double submit
        self::assertSame(380, $this->ledger->balanceFor('mara'));

        // Standard tracking: the load holds no pieces and charges nothing.
        $load = new KilnLoad('load-1');
        $this->lifecycle->markReadyToFire($load);
        $this->lifecycle->markFiring($load);
        $this->lifecycle->markCooling($load);
        $this->lifecycle->markReadyToUnload($load);
        $this->lifecycle->markUnloaded($load);

        self::assertSame(KilnLoadStatus::UNLOADED, $load->status);
        self::assertSame(380, $this->ledger->balanceFor('mara'));
    }

    public function test_transitions_cannot_skip_or_move_backward(): void
    {
        $load = new KilnLoad('load-1');

        // LOADING → FIRING skips READY_TO_FIRE.
        try {
            $this->lifecycle->markFiring($load);
            self::fail('Expected InvalidKilnLoadTransition');
        } catch (InvalidKilnLoadTransition) {
            self::assertSame(KilnLoadStatus::LOADING, $load->status);
        }

        // COOLING → FIRING moves backward.
        $this->lifecycle->markReadyToFire($load);
        $this->lifecycle->markFiring($load);
        $this->lifecycle->markCooling($load);

        $this->expectException(InvalidKilnLoadTransition::class);
        $this->lifecycle->markFiring($load);
    }

    public function test_an_aborted_load_is_refired_for_free(): void
    {
        $this->ledger->credit('mara', 500, LedgerEntryType::PURCHASE);
        $piece = new Firing('f-1', 'mara', 120);
        (new FiringDropOff($this->ledger))->dropOff($piece);

        // Detailed tracking: the piece goes into a load that then fails.
        $failed = new KilnLoad('load-1');
        $failed->addFiring($piece);
        $this->lifecycle->markReadyToFire($failed);
        $this->lifecycle->markFiring($failed);
        $this->lifecycle->abort($failed); // kiln fault mid-firing
        self::assertSame(380, $this->ledger->balanceFor('mara')); // charge stands

        $next = new KilnLoad('load-2');
        self::assertSame(1, $this->lifecycle->refireAbortedLoad($failed, $next));
        $this->lifecycle->markReadyToFire($next);
        $this->lifecycle->markFiring($next);
        $this->lifecycle->markCooling($next);
        $this->lifecycle->markReadyToUnload($next);
        $this->lifecycle->markUnloaded($next);

        self::assertSame(380, $this->ledger->balanceFor('mara')); // the refire charged nothing
        self::assertCount(2, $this->ledger->entriesFor('mara')); // one credit, one FIRING debit
    }

    public function test_a_refire_needs_an_aborted_source_and_a_loading_target(): void
    {
        $loading = new KilnLoad('load-1');

        $this->expectException(InvalidKilnLoadTransition::class);
        $this->lifecycle->refireAbortedLoad($loading, new KilnLoad('load-2'));
    }

    public function test_bisque_and_glaze_are_two_charges_unless_one_payment_covers_both(): void
    {
        $this->ledger->credit('mara', 1000, LedgerEntryType::PURCHASE);

        $perFiring = new FiringDropOff($this->ledger);
        $perFiring->dropOff(new Firing('b-1', 'mara', 100, FiringType::BISQUE));
        $perFiring->dropOff(new Firing('g-1', 'mara', 100, FiringType::GLAZE));
        self::assertSame(800, $this->ledger->balanceFor('mara'));

        $onePayment = new FiringDropOff($this->ledger, onePaymentCoversAll: true);
        $bisque = new Firing('b-2', 'mara', 100, FiringType::BISQUE);
        $onePayment->dropOff($bisque);
        $onePayment->dropOff(new Firing('g-2', 'mara', 100, FiringType::GLAZE), coveredBy: $bisque);
        self::assertSame(700, $this->ledger->balanceFor('mara')); // the covered glaze cost nothing

        // Bisqued elsewhere: nothing to cover it, so it charges.
        $onePayment->dropOff(new Firing('g-3', 'mara', 100, FiringType::GLAZE));
        self::assertSame(600, $this->ledger->balanceFor('mara'));

        // A bisque payment covers one glaze firing, not two.
        $this->expectException(DomainException::class);
        $onePayment->dropOff(new Firing('g-4', 'mara', 100, FiringType::GLAZE), coveredBy: $bisque);
    }

    public function test_pieces_cannot_be_added_after_loading(): void
    {
        $load = new KilnLoad('load-1');
        $this->lifecycle->markReadyToFire($load);

        $this->expectException(InvalidKilnLoadTransition::class);
        $load->addFiring(new Firing('f-1', 'mara', 60));
    }
}
