# 7. Platform Operations Console

The SaaS-operator control plane — the central, cross-tenant surface the
business is run from, distinct from the tenant `/admin` and member `/portal`.
Feature-complete across six phases, then hardened by a dedicated adversarial
review pass (18 findings, all closed).

## Access model

- Lives on a dedicated subdomain (`config('platform.domain')`), listed as a
  central domain. **Platform routes never load tenancy middleware.**
- Own `platform_admin` guard over a central `platform_admins` table —
  cookie-isolated from tenant sessions. Admins are minted only via
  `php artisan platform:create-admin` (never web-creatable).
- **Mandatory TOTP MFA** via Fortify (routes suppressed globally so Fortify
  can't collide with tenant surfaces); un-enrolled admins are funneled to
  setup; 30-minute idle timeout; login + 2FA throttled by named limiters.
- **Destructive actions are super-admin-only** — `is_super_admin` is not
  mass-assignable; the `platform.super` middleware gates
  suspend/restore/offboard/finalize.
- **Everything is audited** to the append-only `PlatformAdminActivityLog`,
  including auth events and even *reading the audit log*.

## Phase map

| Phase | Surface | Key pieces |
|---|---|---|
| 1 | Foundation | admin auth + MFA, cross-tenant dashboard, daily usage snapshots (`tenants:capture-usage-snapshot`, idempotent on (tenant, date)) |
| 2 | Tenant lifecycle | suspend/restore, 30-day offboarding grace + async export + finalize (drops the schema, writes a `DeletedTenantTombstone`), `EnforceTenantStatus` 402 gate, tenant spin-up, cross-subdomain impersonation |
| 3 | Error capture | every uncaught exception → fingerprinted, PII-scrubbed central row; triage at `/platform/errors` |
| 4 | Billing | plan catalog, `TenantPlan`, `PlanGate`, platform Stripe webhook, MRR dashboard (see [05](05-payments-and-billing.md)) |
| 5 | Self-serve signup | signup-attempt audit trail, failed-signup recovery emails, plan picker, card-up-front trial checkout |
| 6 | Ticket portal | tenant staff file tickets at `/admin/support`; cross-tenant queue at `/platform/tickets` |
| — | Add-ons + plans | add-ons that grant features, studio self-serve add/cancel, annual billing, price lock, Enterprise + Gallery tiers, composable modules, services price list |
| — | Discounts | flagship / early-adopter / nonprofit records, nonprofit application + review, one-discount-wins resolver, Stripe coupons synced nightly |
| — | Growth metrics | studio lifecycle event spine, nightly platform snapshot, `/platform/growth` (funnel, MRR, billing vs. access churn, trial cohorts, at-risk studios), weekly digest |

## Tenant lifecycle

Status is **never** written directly. `TenantLifecycleService` /
`OffboardingService` own every transition — each row-locked, transactional,
re-checked under lock, audited, super-admin-gated. Suspension gates more than
HTTP: the cron runner skips `blocksTraffic()` tenants and both Stripe webhooks
short-circuit for them, so a suspended studio receives **no background
mutation** of any kind.

Impersonation ("log in as this studio's admin") issues **single-use** tokens:
redemption row-locks and stamps `redeemed_at`, so a captured URL can't be
replayed; ending an impersonation is owner-scoped; expiry is swept by a
central cron.

## Error capture

- `ErrorCapture::capture()` hooks the global exception reporter. It is
  **observational** — the original exception still bubbles; capture failures
  are swallowed + logged, never cascaded.
- `PiiScrubber` redacts sensitive headers and body keys recursively, plus
  contact-PII keys and free-text values (emails/PANs in any string) — shared
  with the fingerprinter's message scrubbing.
- `ErrorFingerprinter` groups by exception+frames **excluding line numbers**
  (a one-line shift doesn't split a group); UUIDs/long ids scrubbed from
  messages. Blade `ViewException`s also fingerprint on the scrubbed message:
  every view error shares the same Blade stack frames, so without it all
  view errors across all tenants collapsed into one group whose message
  froze at its first occurrence, and a real production 500 hid inside a
  months-old group.
- **Browser errors** arrive through an opt-in, throttled beacon and go into
  the same groups and console, fingerprinted on the JS signature rather than
  the PHP stack, with only the page's origin and path recorded (never the
  query string). The console filters server vs. client and has a client-error
  summary page.
- `ErrorSampler` caps runaway loops at 1,000 occurrences/hour per tenant per
  group: counters stay accurate, ~99% of occurrence rows dropped.
- Alerting: new groups email the operator; cross-tenant spikes (≥5 tenants in
  an hour) send higher-priority alerts, rate-limited per group.

## Growth metrics

Two churn truths are reported side by side: **billing churn** (plan moved to
cancelled) and **access churn** (offboarding finalized). Studio lifecycle
events are written only through one recorder, tapped from provisioning,
plan changes, the lifecycle services and the billing webhook (after its
ordering and identity guards). Trial conversion follows the same rule as
billing: a $0 trial-start invoice isn't a conversion, the first real charge
is. The studio setup checklist deliberately leaves out Stripe, because
Stripe is optional.

## Tickets

Central-schema tables (one cross-tenant queue) filed from tenant context, with
strict visibility: tenant staff see only their tenant's tickets; internal
notes are platform-only and never notify tenants. All mutations flow through
`TicketService` (cross-tenant guards, status history, resolved-at stamping).
Bug tickets auto-suggest related captured errors matched on tenant +
request path. Studio owners' add-on and service requests, and nonprofit
discount applications, arrive as tickets too (one open request per item).

## Design principle

The platform console mirrors the tenant-side conventions rather than inventing
new ones: services own transitions, history tables are append-only, webhooks
are idempotent + ordered, gates fail closed, everything Stripe-shaped is
optional. It is also deliberately **outside the tenant theme system** — it's
the operator's tool, not a studio's branded surface.
