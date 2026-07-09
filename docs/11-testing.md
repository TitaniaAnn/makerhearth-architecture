# 11. Testing Strategy

**Pest 3**, 600+ test files (~2,200+ tests, ~6,500+ assertions), run
single-process against Postgres 16 the way CI does. The green gate before any
push: `vendor/bin/pint --test app/ tests/ database/` + the full Pest suite.

## Suite shape

| Layer | Where | What |
|---|---|---|
| Pure logic | `tests/Unit/` | policy/math/decision classes with no DB: `ProrationCalculator`, `BenefitResolver`, `MembershipStatusResolver`, `RefundDecision`, `WcagContrast`, theme source-contracts |
| Tenant-scoped integration | `tests/Feature/` (the majority) | domain flows through the real services with `RefreshDatabase`/`LazilyRefreshDatabase` and an initialized test tenant |
| Tenancy boundary | `TenantHttpSmokeTest` | provisions a **real** per-tenant Postgres schema, runs tenant migrations into it, and drives portal + admin **over HTTP on the subdomain** |
| Structural guards | e.g. `TenantRuntimeSurfaceTest`, `ScheduleTest`, theme sweep tests | source-level assertions that conventions hold (no bare tenant crons, no legacy tokens, BCP stays self-contained) |
| True concurrency | `RowLockContentionTest` | a second real DB connection holds a row lock and proves `markPaid` / `MembershipService::activate` genuinely serialize (block → bounded `lock_timeout` → succeed on release); deliberately not `RefreshDatabase` |

## Conventions

- Tenant-scoped tests initialize a tenant in `beforeEach` and end tenancy in
  `afterEach`; unique-constraint fixture fields get random suffixes because
  tenant schemas can persist across runs.
- Optional integrations are tested **inert**: the suite runs the entire
  Stripe/SMS/push/AI/GD surface with nothing configured, asserting the
  skip/short-circuit paths — this is what keeps "optional in the strongest
  sense" true.
- External HTTP seams use `Http::fake` (e.g. Google Calendar sync); mail
  assertions run against the automation dispatcher's delivery rows, not
  Mailable classes.
- Regression philosophy: every closed review-pass finding and every "worked in
  tests, broke in the browser" bug got a guard test in the layer that would
  have caught it — several are *structural* (grep-shaped assertions over
  source) when runtime coverage can't see the failure mode.

## Known imbalance

The spec's target shape (pure-function unit majority) hasn't been reached —
feature tests dominate, and browser-level E2E remains a thin set of HTTP
probes. The load-bearing mitigations are the real-schema smoke test and the
structural guards; extending the two-connection concurrency template to
`consumeFiring`, event oversell, and firing transfer is the documented next
step.
