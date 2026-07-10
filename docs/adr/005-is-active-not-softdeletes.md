# ADR 005 — `is_active` flags instead of Laravel `SoftDeletes`

**Status:** Accepted

## Context

Laravel's `SoftDeletes` trait adds `deleted_at` plus a **global scope that
hides trashed rows**. In this domain, "retired" catalog rows and historical
records must remain visible in the normal flow — admin lists, reports, ledgers,
and snapshots all reference them.

## Decision

Soft-delete via **`is_active` booleans** with explicit
`->where('is_active', true)` scopes. No `SoftDeletes` anywhere.

## Consequences

- Historical rows are first-class: a retired `PassType` still renders on old
  orders and reports without `withTrashed()` sprinkled everywhere.
- Filtering to active rows is opt-in per query — the explicit scope is the
  convention, and forgetting it shows too much rather than silently hiding
  data (the safer failure for this domain).
- True deletion doesn't exist in tenant data; offboarding deletes by dropping
  the whole schema (ADR 001).
