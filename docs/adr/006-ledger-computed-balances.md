# ADR 006 — Immutable ledgers; balances computed, never stored

**Status:** Accepted

## Context

Firing credits, store credit, pass credits, board free-class credits, and
campaign totals are balance-affecting counts. A stored balance column drifts
under concurrency and corrections; an audit question ("why is my balance X?")
needs the history anyway.

## Decision

Balance-affecting counts are **immutable credit/debit tables**; the balance is
a query (`balanceFor()`, `raisedCents()`, remaining-credits-from-redemptions).
Corrections and refunds **append** entries. Admin UIs deny create/update/delete
on ledger tables. Concurrency is handled at the write path: balance-mutating
operations row-lock the aggregate root inside a transaction.

## Consequences

- The audit trail is the data; no reconciliation job, no drift.
- Reads cost a SUM — fine at studio scale, and hot paths (public campaign
  thermometers) cache for 5 minutes.
- Any new countable benefit must ship as a ledger + locked write path, not a
  counter column.
