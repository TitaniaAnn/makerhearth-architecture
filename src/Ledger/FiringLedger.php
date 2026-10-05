<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Ledger;

use MakerHearth\Architecture\Firing\Firing;
use PDO;

/**
 * The firing-credit ledger — the platform's flagship balance pattern.
 *
 * Three properties carry all the weight:
 *
 *   1. ENTRIES ARE IMMUTABLE. There is no update or delete path — corrections,
 *      refunds and expirations append new rows. The audit trail IS the data.
 *   2. BALANCE IS COMPUTED, NEVER STORED. `balanceFor()` is a SUM over the
 *      user's entries. There is no balance column to drift.
 *   3. CONSUMPTION IS SERIALIZED AND IDEMPOTENT. Production wraps consume in
 *      a transaction and row-locks the User (`lockForUpdate()`) so two
 *      simultaneous charges can't both read the same balance and each write
 *      a debit past the negative-balance guard; the firing's `billed_at`
 *      stamp is re-checked under the lock so a double-submitted drop-off
 *      bills exactly once.
 *      SQLite has no row locks, so this reference uses an IMMEDIATE
 *      transaction (a write lock on the database) — same serialization
 *      guarantee, database-wide instead of per-row.
 *
 * Expiry note (production subtlety worth keeping): expired credit is NOT
 * filtered out of the balance query. A scheduled sweep posts an explicit
 * EXPIRATION debit for an expired lot's unused remainder. Filtering credits
 * at read time while keeping the debits taken against them left members with
 * phantom negative balances — the sweep-posts-forfeiture design is both
 * correct and member-fair.
 *
 * @see ARCHITECTURE.md §3 — "Immutable ledgers; balances computed, never stored"
 * @see ARCHITECTURE.md §4 — consume runs at front-desk drop-off; no kiln step charges
 */
final class FiringLedger
{
    public function __construct(
        private PDO $db,
        private bool $allowNegativeBalance = false,
    ) {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS firing_ledger_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                amount_cubic_inches INTEGER NOT NULL,
                entry_type TEXT NOT NULL,
                source_id TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
    }

    /** Available balance in cubic inches: the sum of every entry. */
    public function balanceFor(string $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(amount_cubic_inches), 0) FROM firing_ledger_entries WHERE user_id = ?'
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /** Firing-package activation: credit the purchased cubic inches. */
    public function credit(string $userId, int $cubicInches, LedgerEntryType $type, ?string $sourceId = null): void
    {
        $this->append($userId, abs($cubicInches), $type, $sourceId);
    }

    /**
     * Staff correction — either sign, but always a NEW row. The absence of
     * any update path is the immutability contract.
     */
    public function adjust(string $userId, int $signedCubicInches, ?string $sourceId = null): void
    {
        $this->append($userId, $signedCubicInches, LedgerEntryType::ADJUSTMENT, $sourceId);
    }

    /**
     * Consume credit for a piece dropped off to be fired. Called by
     * FiringDropOff at the front desk — never by a kiln transition, so billing
     * never depends on what a load contains.
     *
     * Idempotent: a firing already stamped `billedAt` is left untouched, and
     * the stamp is re-checked inside the serialized section, so a
     * double-submit writes exactly one debit — and a refired piece (which
     * keeps its stamp) is never charged again.
     */
    public function consumeFiring(Firing $firing): void
    {
        if ($firing->billedAt !== null) {
            return;
        }

        $this->db->exec('BEGIN IMMEDIATE'); // production: DB::transaction + User::lockForUpdate()

        try {
            if ($firing->billedAt !== null) { // re-check under the lock
                $this->db->exec('ROLLBACK');

                return;
            }

            $balance = $this->balanceFor($firing->userId);

            if ($balance < $firing->cubicInches && ! $this->allowNegativeBalance) {
                // Production bills the overage to the member's cart here
                // (a FIRING_USAGE line priced via StudioSettings + the benefit
                // resolver's firing discount). This cut keeps the guard visible
                // and leaves cart integration to the production repo.
                throw new InsufficientBalance(
                    "Balance {$balance} cu in cannot cover {$firing->cubicInches} cu in."
                );
            }

            $this->append($firing->userId, -$firing->cubicInches, LedgerEntryType::FIRING, $firing->id);
            $firing->billedAt = new \DateTimeImmutable();

            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->exec('ROLLBACK');
            }

            throw $e;
        }
    }

    /**
     * Peer transfer: one debit + one credit, appended atomically. Production
     * locks BOTH users in PK-sorted order to avoid deadlock between two
     * opposite-direction transfers.
     */
    public function transfer(string $fromUserId, string $toUserId, int $cubicInches, ?string $sourceId = null): void
    {
        $this->db->exec('BEGIN IMMEDIATE');

        try {
            if ($this->balanceFor($fromUserId) < $cubicInches && ! $this->allowNegativeBalance) {
                throw new InsufficientBalance('Transfer exceeds available balance.');
            }

            $this->append($fromUserId, -$cubicInches, LedgerEntryType::TRANSFER_OUT, $sourceId);
            $this->append($toUserId, $cubicInches, LedgerEntryType::TRANSFER_IN, $sourceId);

            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->exec('ROLLBACK');
            }

            throw $e;
        }
    }

    /** @return list<array{amount_cubic_inches: int, entry_type: string}> */
    public function entriesFor(string $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT amount_cubic_inches, entry_type FROM firing_ledger_entries WHERE user_id = ? ORDER BY id'
        );
        $stmt->execute([$userId]);

        return array_map(
            fn (array $row) => ['amount_cubic_inches' => (int) $row['amount_cubic_inches'], 'entry_type' => $row['entry_type']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function append(string $userId, int $signedAmount, LedgerEntryType $type, ?string $sourceId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO firing_ledger_entries (user_id, amount_cubic_inches, entry_type, source_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $signedAmount, $type->value, $sourceId]);
    }
}
