<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Tests;

use MakerHearth\Architecture\Firing\Firing;
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
 * forward, billing happens only on the UNLOADED transition, and an aborted
 * load structurally cannot bill.
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
        $this->lifecycle = new KilnLoadLifecycle($this->ledger);
    }

    public function test_happy_path_bills_each_piece_exactly_once_on_unload(): void
    {
        $this->ledger->credit('mara', 500, LedgerEntryType::PURCHASE);

        $load = new KilnLoad('load-1');
        $load->addFiring(new Firing('f-1', 'mara', 120));

        $this->lifecycle->markReadyToFire($load);
        $this->lifecycle->markFiring($load);
        self::assertSame(500, $this->ledger->balanceFor('mara')); // nothing billed mid-firing

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

    public function test_aborted_load_never_bills(): void
    {
        $this->ledger->credit('mara', 500, LedgerEntryType::PURCHASE);

        $load = new KilnLoad('load-1');
        $load->addFiring(new Firing('f-1', 'mara', 120));

        $this->lifecycle->markReadyToFire($load);
        $this->lifecycle->markFiring($load);
        $this->lifecycle->abort($load); // kiln fault mid-firing

        self::assertSame(KilnLoadStatus::ABORTED, $load->status);
        self::assertSame(500, $this->ledger->balanceFor('mara')); // untouched

        // And ABORTED is terminal — the load can never reach UNLOADED,
        // which is the only transition that consumes credit.
        $this->expectException(InvalidKilnLoadTransition::class);
        $this->lifecycle->markUnloaded($load);
    }

    public function test_pieces_cannot_be_added_after_loading(): void
    {
        $load = new KilnLoad('load-1');
        $this->lifecycle->markReadyToFire($load);

        $this->expectException(InvalidKilnLoadTransition::class);
        $load->addFiring(new Firing('f-1', 'mara', 60));
    }
}
