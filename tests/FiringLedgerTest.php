<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Tests;

use MakerHearth\Architecture\Firing\Firing;
use MakerHearth\Architecture\Ledger\FiringLedger;
use MakerHearth\Architecture\Ledger\InsufficientBalance;
use MakerHearth\Architecture\Ledger\LedgerEntryType;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ARCHITECTURE.md §3 — the ledger is immutable and append-only,
 * the balance is computed (never stored), corrections append new entries,
 * consumption is idempotent, and the negative-balance guard holds.
 */
final class FiringLedgerTest extends TestCase
{
    private FiringLedger $ledger;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->ledger = new FiringLedger($db);
    }

    public function test_balance_is_the_sum_of_entries(): void
    {
        self::assertSame(0, $this->ledger->balanceFor('mara'));

        $this->ledger->credit('mara', 500, LedgerEntryType::PURCHASE, 'pkg-1');
        self::assertSame(500, $this->ledger->balanceFor('mara'));

        $this->ledger->consumeFiring(new Firing('f-1', 'mara', 120));
        self::assertSame(380, $this->ledger->balanceFor('mara'));
    }

    public function test_corrections_append_rather_than_edit(): void
    {
        $this->ledger->credit('mara', 500, LedgerEntryType::PURCHASE);
        $this->ledger->adjust('mara', -50, 'staff mis-measure fix');

        $entries = $this->ledger->entriesFor('mara');

        // Two rows — the original credit untouched, the correction appended.
        self::assertCount(2, $entries);
        self::assertSame(500, $entries[0]['amount_cubic_inches']);
        self::assertSame(-50, $entries[1]['amount_cubic_inches']);
        self::assertSame(LedgerEntryType::ADJUSTMENT->value, $entries[1]['entry_type']);
        self::assertSame(450, $this->ledger->balanceFor('mara'));
    }

    public function test_consume_is_idempotent_per_firing(): void
    {
        $this->ledger->credit('mara', 500, LedgerEntryType::PURCHASE);
        $firing = new Firing('f-1', 'mara', 120);

        $this->ledger->consumeFiring($firing);
        $this->ledger->consumeFiring($firing); // double-submitted drop-off / replay

        self::assertCount(2, $this->ledger->entriesFor('mara')); // 1 credit + exactly 1 debit
        self::assertSame(380, $this->ledger->balanceFor('mara'));
    }

    public function test_negative_balance_guard_fails_closed(): void
    {
        $this->ledger->credit('mara', 100, LedgerEntryType::PURCHASE);

        $this->expectException(InsufficientBalance::class);
        $this->ledger->consumeFiring(new Firing('f-1', 'mara', 120));
    }

    public function test_allow_negative_lets_members_run_a_deficit(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ledger = new FiringLedger($db, allowNegativeBalance: true);

        $ledger->credit('mara', 100, LedgerEntryType::PURCHASE);
        $ledger->consumeFiring(new Firing('f-1', 'mara', 120));

        self::assertSame(-20, $ledger->balanceFor('mara'));
    }

    public function test_transfer_writes_a_debit_credit_pair(): void
    {
        $this->ledger->credit('mara', 300, LedgerEntryType::PURCHASE);
        $this->ledger->transfer('mara', 'theo', 100);

        self::assertSame(200, $this->ledger->balanceFor('mara'));
        self::assertSame(100, $this->ledger->balanceFor('theo'));

        $this->expectException(InsufficientBalance::class);
        $this->ledger->transfer('theo', 'mara', 500);
    }
}
