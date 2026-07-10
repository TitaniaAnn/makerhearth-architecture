# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

A **reference architecture**, not a runnable product. It publishes a curated subset of the multi-tenant studio-management architecture behind MakerHearth (the production repo is [makerhearth-laravel](https://github.com/TitaniaAnn/makerhearth-laravel)), extracted into a small framework-free PHP 8.4 package. README.md and ARCHITECTURE.md are the primary deliverables; the code under `src/` exists to make their claims verifiable, and every test names the ARCHITECTURE.md section it verifies.

There is no web surface, no Laravel, no database server — the ledger tests run against `:memory:` SQLite via `pdo_sqlite`. The `docs/` directory documents the whole production platform (multi-tenancy, messaging, platform console, deployment, security) and is prose-only by design.

## Commands

```bash
composer install                                  # deps: brick/math + phpunit (dev)
vendor/bin/phpunit                                # all tests
vendor/bin/phpunit --filter FiringLedgerTest      # one suite
vendor/bin/phpunit --filter test_consume_is_idempotent_per_firing   # one test
```

Requires PHP 8.4 with `pdo_sqlite` (asymmetric `private(set)` visibility and `array_any` are used). Composer plugins are not needed — the test runner is plain PHPUnit, deliberately, so the repo runs in restricted sandboxes.

## Architecture

Seven runnable decisions in [ARCHITECTURE.md](ARCHITECTURE.md) (§8–§9 describe production-only architecture with no code in this cut). The cross-cutting bits worth knowing before editing:

- **No public status setters.** `KilnLoad.status` and `Order.status` are `private(set)`; only `KilnLoadLifecycle` / `OrderService` move them (via the `@internal forceStatus()`). Don't add setters — the whole point of §1 is that domain state moves only through service entry points.
- **The ledger has no update/delete path.** `FiringLedger` appends. A "fix" that edits or removes a row breaks the §3 contract and its tests; corrections are `ADJUSTMENT` rows.
- **The transition map is the single source of truth.** `KilnLoadStatus::canTransitionTo()` — services consult it, nothing bypasses it. Billing hangs ONLY on the UNLOADED transition; don't move the consume call.
- **All proportional money math goes through `MoneyMath`** (HALF_EVEN). Never `intdiv($cents * $percent, 100)`, never float intermediate values.
- **Production-verbatim files.** `src/Support/MoneyMath.php` and `src/Refunds/RefundDecision.php` are the production classes with only the namespace changed. If you change them here, note that they've diverged from production (or change production too).
- **SQLite stand-ins for Postgres idioms.** Production serializes ledger mutations with `DB::transaction` + `User::lockForUpdate()` (and PK-sorted two-user locking for transfers); this cut uses `BEGIN IMMEDIATE` because SQLite has no row locks. The comments in `FiringLedger` say so — keep that mapping accurate if you touch the transaction code.

## Conventions specific to this repo

- `declare(strict_types=1);` in every PHP file.
- Test docstrings name the ARCHITECTURE.md section they verify (`§2`, `§3`, …). When adding a test for a documented claim, follow the same convention so the contract→test mapping stays traceable.
- New extracted patterns get: a `src/<Domain>/` namespace, a numbered ARCHITECTURE.md section (problem / decision / why-not-the-alternatives / seams) linking to the files, a test suite verifying the contract, and a line in the README tree. Keep extractions small — this repo's value is being readable in fifteen minutes.
- Enums are backed (`string`), values snake_case, mirroring the production convention of enum-cast status columns.
- Keep the toy surface honest: where this cut simplifies production (no cart-overage billing in the ledger, no row locks, in-memory orders), say so in a comment pointing at the production behavior rather than silently pretending.

## Relationship to the production repo

The production platform is the source of truth for behavior. When its load-bearing conventions change shape, update three places here: the extracted `src/` code if the pattern itself changed, ARCHITECTURE.md's matching section, and the platform-level `docs/` file. The snapshot-dated numbers in `docs/` (model/service/test counts) are expected to drift — refresh them when touching those files, don't chase them continuously.
