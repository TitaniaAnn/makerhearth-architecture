# 11. Testing Strategy

**Pest 3**, ~800 test files (~5,000 tests), run
single-process against Postgres 16 the way CI does. The green gate before any
push: `vendor/bin/pint --test app/ tests/ database/` + the full Pest suite.

## Suite shape

| Layer | Where | What |
|---|---|---|
| Pure logic | `tests/Unit/` (63 files) | policy/math/decision classes with no DB: `ProrationCalculator`, `BenefitResolver`, `MembershipStatusResolver`, `RefundDecision`, `MoneyMath`, `TaxCalculator`, `WcagContrast`, theme source-contracts |
| Tenant-scoped integration | `tests/Feature/` (the majority) | domain flows through the real services with `RefreshDatabase`/`LazilyRefreshDatabase` and an initialized test tenant |
| Tenancy boundary | `TenantHttpSmokeTest` | provisions a **real** per-tenant Postgres schema, runs tenant migrations into it, and drives portal + admin **over HTTP on the subdomain** (including decoding the server-emitted sidebar state) |
| Real panel render | e.g. `GalleryTierAdminHttpTest` | drives the full admin layout over HTTP for a real plan tier; in-process `Livewire::test()` never renders the sidebar, so it can't catch a sidebar crash |
| Structural guards | e.g. `TenantRuntimeSurfaceTest`, `ScheduleTest`, theme sweep tests | source-level assertions that conventions hold (no bare tenant crons, no legacy tokens, BCP stays self-contained) |
| True concurrency | `tests/Feature/Concurrency/` | a second real DB connection holds a row lock and proves `markPaid`, `MembershipService::activate` and firing transfers genuinely serialize (block → bounded `lock_timeout` → succeed on release); deliberately not `RefreshDatabase` |

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

## Lessons the suite paid for

- **Tenancy left behind.** `Tenant::run()` has no `finally`; a throw inside
  it once left a central connection idle-in-transaction holding a lock, and
  every later `migrate:fresh` hung until CI timed out. A suite-wide
  `afterEach` now ends any tenancy a test leaves initialized.
- **Committed rows outlive `RefreshDatabase`.** Real-schema tests and writes
  made inside `$tenant->run()` commit on a connection the test transaction
  doesn't cover. Tests that count rows in central event tables clear them in
  `beforeEach` (inside the rolled-back transaction, so the clear is undone
  too). Where a test only needs a tenant bound, it binds the tenant instance
  instead of initializing tenancy.
- **The wrong clock.** A test comparing a studio-local date against UTC
  `now()` passes all afternoon and fails every nightly run between UTC
  midnight and the studio's midnight. Studio-local dates are asserted
  against `TenantTime::today()`.
- **Rename slices break string assertions.** Renaming a sidebar label or tab
  ("Setup" → "Settings") broke tests that asserted the old string; Pint
  can't see that, only a broad enough test run can.
- **Sidebar membership tests pin presence and absence.** Moving a resource
  between sections needs both "it's here now" and "it's gone from there."
- **CSS in an inline `<style>` block is in the haystack.** An
  `assertDontSee('starts in')` fails forever if that phrase appears in a CSS
  comment that always renders; such assertions target the rendered tag.

## Known imbalance

The spec's target shape (pure-function unit majority) hasn't been reached —
feature tests dominate (743 feature files to 63 unit), and browser-level E2E
remains a thin set of HTTP probes. The load-bearing mitigations are the
real-schema smoke test, the real-panel HTTP tests and the structural guards.
The two-connection concurrency template now covers firing transfers;
`consumeFiring` and event oversell are still covered only by sequential
double-calls.
