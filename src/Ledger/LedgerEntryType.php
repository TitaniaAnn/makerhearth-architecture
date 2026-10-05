<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Ledger;

/**
 * Every way firing credit can move. Credits are positive amounts, debits
 * negative — the sign lives on the row, so the balance is a single SUM.
 *
 * @see ARCHITECTURE.md §3 — "Immutable ledgers; balances computed, never stored"
 */
enum LedgerEntryType: string
{
    case PURCHASE = 'purchase';         // firing-package activation credits cubic inches
    case FIRING = 'firing';             // charge-at-drop-off debit (one per drop-off record)
    case ADJUSTMENT = 'adjustment';     // staff correction (either sign) — appends, never edits
    case TRANSFER_OUT = 'transfer_out'; // peer transfer, debit half
    case TRANSFER_IN = 'transfer_in';   // peer transfer, credit half
    case EXPIRATION = 'expiration';     // scheduled sweep forfeits an expired lot's unused remainder
}
