# 2. Multi-tenancy

MakerHearth is **schema-per-tenant** on PostgreSQL via **stancl/tenancy v3**.
Every studio gets its own Postgres schema; the package sets `search_path` per
request after identifying the tenant by subdomain.

## Identification & routing

- `{studio}.domain` → tenant context (`routes/tenant.php` under
  `InitializeTenancyByDomain` + `PreventAccessFromCentralDomains`).
- The apex/central domains (allowlisted in `tenancy.central_domains`) → central
  app (`routes/web.php`): tenant directory, `/signup`, `/plans`, the public
  marketing sites at `/studio/{tenant}`, and all webhooks.
- The platform console lives on its own subdomain (`config('platform.domain')`),
  also listed as a central domain. **Platform routes never load tenancy
  middleware.**

Three integration points that were hard-won (each originally broken, now
regression-guarded by `TenantHttpSmokeTest` — a test that provisions a real
per-tenant schema and drives portal + admin over HTTP on the subdomain):

1. The **Filament panel** carries the two tenancy middlewares itself, so
   `/admin/*` resolves against the tenant schema.
2. **Livewire's update route** is re-registered tenant-aware via
   `Livewire::setUpdateRoute(...)` wrapped in `web` + tenancy middleware.
3. **Vite assets** on tenant domains go through stancl's `ViteBundler` feature
   (disabled under `testing`).

## Central vs. tenant data

| Central schema | Tenant schema |
|---|---|
| `tenants`, `domains` (+ denormalized `stripe_connect_account_id` for webhook lookup) | every domain table: users, orders, firing ledger, classes, … (269 migrations) |
| `platform_admins` + platform activity log | `studio_settings` (per-tenant config singleton) |
| `plan_tiers`, `addons`, `tenant_plans`, billing events | tenant-side Stripe Connect data |
| `system_error_groups` / `_occurrences` (error capture) | activity log (spatie) |
| `tenant_tickets` (+ comments/history) | … |
| `platform_email_automations` (+ steps/deliveries) | `email_automations` (+ steps/variants/deliveries) |
| `tenant_signup_attempts`, usage snapshots, tombstones | … |

Migrations are physically split (`database/migrations/` vs.
`database/migrations/tenant/`) and run with `php artisan migrate` vs.
`php artisan tenants:migrate`. Deploys run both in the release step.

Central tables that must be readable **from inside a tenant request** (error
capture, platform email automations) are plain models in the public schema
reached via the Postgres search-path fallback — no special connection needed.

## Context switching

Anything that arrives without a tenant subdomain but must act on tenant data
resolves the tenant explicitly and runs inside it:

```php
$tenant = Tenant::where('stripe_connect_account_id', $event->account)->first();
$tenant->run(fn () => /* tenant-schema work */);
```

Used by: the Connect Stripe webhook, the SES/SNS webhook (tenant from the
signed token / message tag), public marketing pages (tenant from the URL),
lead capture, the platform console's cross-tenant queries (per-tenant
`$tenant->run()` with errors caught + logged + skipped, never aborting the
loop), and the ticket-notification email resolution.

## Tenant-aware infrastructure

- **Queues** — every job touching tenant data uses stancl's tenant-aware job
  serialization; a job without it silently runs against the central schema.
- **Cache** — `CacheTenancyBootstrapper` prefixes keys with the tenant id.
  Live-data marketing blocks and embed calendars rely on this for their
  5-minute `Cache::remember` windows.
- **Scheduler** — no tenant cron is ever scheduled bare. `routes/console.php`
  wraps each one in `tenants:run-all "<command>"`, which iterates
  `Tenant::all()`, switches into each schema, and isolates per-tenant failures
  with try/catch. The runner also **skips tenants whose lifecycle status blocks
  traffic** (suspended/offboarding/deleted), so background mutation stops with
  HTTP. A guard test fails CI if a tenant-scoped command is ever scheduled bare.
  Genuinely central commands (usage snapshot, platform crons, queue heartbeats)
  are scheduled bare on purpose.

## Provisioning & lifecycle

`App\Services\Tenancy\TenantProvisioner` is the **single sanctioned
provisioning path** (backing both the `studio:create` command and self-serve
`/signup`): creates the tenant + domain, runs tenant migrations into the new
schema, creates the founding staff owner, and starts the tenant on a TRIAL of
its chosen plan tier.

Lifecycle transitions (suspend/restore, the 30-day offboarding grace window,
finalize-and-drop-schema with a `DeletedTenantTombstone`) are **never** direct
status writes — they go through `TenantLifecycleService` / `OffboardingService`,
each transition row-locked, transactional, audited, and gated to super-admins.
The `EnforceTenantStatus` middleware returns 402 for blocked tenants, and both
Stripe webhooks + the cron runner short-circuit for them. Details in
[07-platform-operations.md](07-platform-operations.md).

## Testing implications

Most of the suite runs single-schema with `RefreshDatabase` and an initialized
test tenant. The tenancy *boundary* itself is covered by `TenantHttpSmokeTest`
(real schema + real HTTP on the subdomain) and structural guards
(`TenantRuntimeSurfaceTest`), so the "works in tests, broken in a browser"
class of bug can't regress silently. See [11-testing.md](11-testing.md).
