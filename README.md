# MakerHearth Architecture

A reference implementation of the multi-tenant studio-management
architecture behind **MakerHearth** — a Laravel 12 / PHP 8.4 SaaS platform
for pottery & ceramics studios (studio-time check-in, kiln firing ledgers,
memberships, classes, lessons, events, a front-desk POS, fundraising, and a
platform-operations console).

This repository is **not** the full platform. It is a curated subset of the
architectural pieces that make MakerHearth interesting, extracted from the
production codebase into a small, framework-free PHP package so the
patterns can be read — and run — without 220 models of studio business
logic in the way. Where a class body could be lifted verbatim
(`MoneyMath`, `RefundDecision`), it was.

## What's here

```
src/
├── Support/
│   └── MoneyMath.php              ← integer cents + banker's rounding (production verbatim)
│
├── Ledger/                        # The flagship pattern: immutable ledger, computed balance
│   ├── FiringLedger.php           ← append-only entries, SUM balance, idempotent consume,
│   │                                serialized mutations, atomic transfers
│   ├── LedgerEntryType.php        ← every way credit moves (incl. explicit EXPIRATION)
│   └── InsufficientBalance.php
│
├── Firing/                        # Strictly-forward state machine
│   ├── KilnLoadStatus.php         ← the transition map — the single source of truth
│   ├── KilnLoadLifecycle.php      ← named transitions; none charges; free refire
│   ├── FiringDropOff.php          ← the billing point: charge at front-desk drop-off
│   ├── FiringType.php             ← bisque / glaze / other, per drop-off record
│   ├── KilnLoad.php               ← status readable everywhere, writable only by the lifecycle
│   ├── Firing.php                 ← a drop-off record; billedAt = idempotency stamp
│   └── InvalidKilnLoadTransition.php
│
├── Benefits/                      # Single-contract resolution across sources
│   ├── BenefitResolver.php        ← max-for-discounts (never stack), OR-for-booleans
│   ├── BenefitSource.php          ← new sources plug in HERE, never at call sites
│   ├── BenefitPackage.php
│   └── EffectiveBenefits.php      ← immutable resolved value
│
├── Refunds/                       # Decisions are values, not actions
│   ├── RefundPolicy.php           ← pure window math — no order, no Stripe, no DB
│   ├── RefundDecision.php         ← the value that flows to the movement half (production verbatim)
│   └── RefundTier.php
│
└── Commerce/                      # Snapshot-on-purchase + the single paid contract
    ├── CartLine.php               ← price FROZEN at add-to-cart; the only place price is computed
    ├── OrderService.php           ← markPaid(): idempotent, activation dispatch by product type
    ├── Order.php                  ← no public status setter
    ├── OrderStatus.php
    └── ProductType.php

tests/                             # Verifies the contract claims in ARCHITECTURE.md
├── MoneyMathTest.php              ← §2 odd-half cases round HALF_EVEN, complements sum exactly,
│                                     decimal-string tax rates, refund tax share
├── FiringLedgerTest.php           ← §3 computed balance, append-only corrections, idempotent
│                                     consume, fail-closed negative guard, atomic transfer
├── KilnLoadLifecycleTest.php      ← §4 forward-only, charged once at drop-off, free refire,
│                                     one payment vs. per firing
├── BenefitResolverTest.php        ← §5 max/OR aggregation semantics
├── RefundPolicyTest.php           ← §6 window math as pure arithmetic
└── OrderServiceTest.php           ← §7 snapshot survives reprice, markPaid idempotent + strict
```

29 PHP files — 23 under `src/` and 6 under `tests/` — about 1,700 lines
in total. Substantial enough to demonstrate real architecture; small enough
to read in fifteen minutes.

## What this demonstrates

The architectural decisions documented in detail in
[ARCHITECTURE.md](ARCHITECTURE.md):

1. **The service layer is the domain API** — status has no public setter;
   five production surfaces share one invariant set.
2. **Money is integer cents with banker's rounding** — one rounding
   authority, no float, no `intdiv` bias.
3. **Immutable ledgers; balances computed, never stored** — corrections
   append, consumption is idempotent and serialized, expiry is an explicit
   posted debit.
4. **Strictly-forward state machines** — the transition map is the source
   of truth, and "aborted loads never bill" is enforced by the graph's
   shape, not an `if`.
5. **Benefit resolution across sources** — one resolver, max-not-stack
   semantics, new sources plug into the contract.
6. **Refund decisions are values, not actions** — pure policy, separate
   movement, testable as arithmetic.
7. **Snapshot-on-purchase and the single paid contract** — frozen cart
   pricing plus one idempotent `markPaid()` every payment path converges on.

Sections 8–9 of ARCHITECTURE.md describe two production decisions that are
documented but deliberately not republished as code here: schema-per-tenant
multi-tenancy, and the "every integration is optional" contract.

## Running it

```bash
composer install
vendor/bin/phpunit                                   # all 35 tests (SQLite :memory:)
vendor/bin/phpunit --filter FiringLedgerTest         # one suite
```

Requires PHP 8.4 (`readonly` classes, asymmetric `private(set)` visibility)
with `pdo_sqlite`. The only runtime dependency is `brick/math`.

## Going deeper

The [docs/](docs/) directory documents the **whole production platform** —
the layer above these extracted patterns:

- [System overview](docs/01-system-overview.md) · [Multi-tenancy](docs/02-multi-tenancy.md) · [Domain map](docs/03-domain-map.md) · [Patterns](docs/04-architectural-patterns.md)
- [Payments & billing](docs/05-payments-and-billing.md) · [Messaging & email](docs/06-messaging-and-email.md) · [Platform operations](docs/07-platform-operations.md) · [Public site & theming](docs/08-public-site-and-theming.md)
- [Scheduled work](docs/09-scheduled-work.md) · [Deployment](docs/10-deployment.md) · [Testing](docs/11-testing.md) · [Data model](docs/12-data-model.md) · [Key flows](docs/13-key-flows.md) · [Security](docs/14-security.md)

The production code lives in a private repository (220 Eloquent models,
271 service classes, ~5,000 tests as of October 2026). Its `.design-docs/`
holds the authoritative product spec. This repo and its `docs/` describe
that architecture without requiring access to either.

## License

[MIT](LICENSE)
