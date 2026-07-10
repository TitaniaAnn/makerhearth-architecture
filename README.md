# MakerHearth Architecture

Architecture documentation for **MakerHearth**, a multi-tenant SaaS platform for
managing pottery & ceramics studios — studio-time check-in, kiln firing ledgers,
memberships, classes, private lessons, events, parties, a front-desk POS, a
fundraising CRM, a public-site builder, and a full platform-operations console.

This repo is derived from the as-built
[makerhearth-laravel](https://github.com/TitaniaAnn/makerhearth-laravel) codebase.
It describes **what is actually implemented**, not aspirations: every number,
model name, and convention below was taken from the code at the snapshot date.

## Snapshot (2026-07)

| Dimension | As built |
|---|---|
| Framework | Laravel 12 / PHP 8.4 (`declare(strict_types=1)` everywhere) |
| Database | PostgreSQL 16, schema-per-tenant via stancl/tenancy v3 |
| Eloquent models | 174 |
| Domain service classes | 182 across 31 domain namespaces |
| Backed enums | 100 |
| Migrations | 269 tenant + 46 central |
| Filament resources (staff admin) | 87 |
| Livewire components | ~46 portal + kiosk/POS/staff surfaces |
| Artisan commands | 67 (48 tenant-scoped crons + 9 central crons + ops one-offs) |
| Tests | 600+ Pest files (~2,200+ tests), green on Postgres 16 |

## Documents

Read in order for a full tour, or jump to the topic you need.

1. **[System overview](docs/01-system-overview.md)** — what the platform does, the
   five HTTP surfaces, the tech stack and why each piece was chosen.
2. **[Multi-tenancy](docs/02-multi-tenancy.md)** — schema-per-tenant, central vs.
   tenant routes, tenant-aware queues/cache/scheduler, webhook tenant resolution.
3. **[Domain map](docs/03-domain-map.md)** — every domain with its models,
   services, and the seams between them.
4. **[Architectural patterns](docs/04-architectural-patterns.md)** — the
   load-bearing conventions: ledgers, snapshots, the service layer, benefit
   resolution, universal gates, state machines, money handling.
5. **[Payments & billing](docs/05-payments-and-billing.md)** — Stripe Connect per
   tenant, the optional-Stripe stance, refund decision-vs-movement, and the
   platform's own tenant billing.
6. **[Messaging & email automation](docs/06-messaging-and-email.md)** — the
   trigger-driven email engine, multi-channel (SMS/push) drivers, A/B variants,
   suppression, re-engagement, and deliverability (SES/SNS).
7. **[Platform operations console](docs/07-platform-operations.md)** — the
   SaaS-operator control plane: platform admin auth, tenant lifecycle, error
   capture, billing, self-serve signup, tickets.
8. **[Public site & theming](docs/08-public-site-and-theming.md)** — the block
   builder, SEO, pageview analytics, the theme system, and website embeds.
9. **[Scheduled work](docs/09-scheduled-work.md)** — the cron catalog and the
   idempotency rules every scheduled command follows.
10. **[Deployment](docs/10-deployment.md)** — Dokku on Hetzner, the Procfile
    process model, migrations-on-release, backups.
11. **[Testing strategy](docs/11-testing.md)** — Pest suite shape, tenant-scoped
    tests, the real-schema HTTP smoke test, concurrency tests.
12. **[Data model](docs/12-data-model.md)** — the schema's shape: the three
    hubs, the `source_*` join convention, structural patterns, and the
    benefit/classes/firing spines (Mermaid ER diagrams).
13. **[Key flows](docs/13-key-flows.md)** — the dynamic view: enrollment →
    checkout → activation, waitlist promotion, firing consume, refunds,
    webhooks, email dispatch, provisioning, BCP reconcile (sequence diagrams).
14. **[Security architecture](docs/14-security.md)** — auth surfaces,
    authorization layers, webhook integrity, signed/single-use URLs, injection
    defenses, PII/privacy, rate limiting, audit trails.

**[Architecture Decision Records](docs/adr/README.md)** — the twelve settled
trade-offs (schema-per-tenant, dropping Cashier, optional Stripe, skipping
Statamic, `is_active` over SoftDeletes, ledgers, the service layer,
trigger-driven email, refund decision-vs-movement, Dokku, render-hook admin
theming, cookie-free analytics) with context and consequences.

## Relationship to the code repo

The code repo remains the source of truth. Its `.design-docs/` directory holds
the authoritative **product spec** (`MODELS.md`, `DESIGN.md`, `OVERVIEW.md` — 70+
models of stack-agnostic pseudocode), and its `CLAUDE.md` holds the build
conventions. This repo is the **architecture layer between the two**: the
as-built system description a new engineer or technical evaluator reads first.

When the code changes shape (new domain, new surface, changed convention),
update the matching document here.
