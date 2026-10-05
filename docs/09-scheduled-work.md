# 9. Scheduled Work

All scheduling lives in `routes/console.php`. Every entry is
`withoutOverlapping()->onOneServer()`. In production the scheduler runs every
minute via the Procfile `scheduler` process.

## The one rule

**Tenant-scoped commands are never scheduled bare.** Each is wrapped in
`tenants:run-all "<command>"`, which iterates every tenant, switches into its
schema, isolates per-tenant failures with try/catch, and **skips tenants whose
lifecycle status blocks traffic**. A guard test fails CI if a tenant cron is
ever scheduled bare. Central commands (platform crons, usage snapshot, queue
heartbeats) are scheduled bare on purpose — they iterate tenants themselves or
touch only central data.

Every cron is **idempotent**: dedup via `*_sent_at` stamp columns (disjoint
windows for escalating reminders), existence checks keyed on source rows,
claim-under-lock for sweeps, `firstOrCreate` on natural keys. Re-running any
command is safe.

## Tenant-scoped catalog (53 commands via `tenants:run-all`)

Grouped by domain; representative schedules — see `routes/console.php` for
exact times.

**Classes, lessons & events**
`classes:generate-recurrences` · `classes:remind-24h` ·
`class-offerings:open-registration` · `class-recurrences:notify-price-review` ·
`waitlists:expire-offers` · `lessons:remind-24h` ·
`lesson-packages:expire-soon-remind` · `bookings:materialize-recurring` ·
`events:publish-scheduled` (every 5 min) · `events:remind-24h` ·
`event-waitlists:expire-offers` · `parties:send-reminders` ·
`parties:complete-past`

**Firing & studio floor**
`firings:remind-unloaded` (stuck kilns) · `firings:notify-ready-for-pickup` ·
`firings:remind-stale-pickups` · `firing:sweep-expired-credit` ·
`studio:auto-close-orphaned` (hourly) · `shifts:close-forgotten` (POS, every
30 min) · `terminal:reconcile-offline` (hourly; card-reader charges that were
never forwarded) · `bcp:export-bundle`

**Memberships, rentals, passes, people**
`memberships:expire-scheduled` · `memberships:churn-warn` ·
`memberships:auto-renew-reminder` · `rentals:end-scheduled` ·
`rentals:ending-soon-remind` · `rentals:expire-waitlist-offers` (every 5 min) ·
`passes:expire-soon-remind` · `certifications:expire-soon-remind` ·
`waivers:remind-unsigned` · `auth:remind-unverified` ·
`guardians:end-aged-out` · `board:roll-year-over` ·
`volunteers:monthly-digest` · `procedures:review-due`

**Commerce, money & back office**
`carts:abandon-stale` · `accounting:sync` (daily; studios on a live
accounting add-on) · `reports:nightly` · `reports:fire-scheduled` (hourly) ·
`payroll:generate-pay-periods` · `inventory:low-stock-digest` ·
`tasks:generate-recurring` · `tasks:mark-overdue` · `tasks:overdue-digest` ·
`gallery:expire-displays` · `gallery:monthly-statements` ·
`gallery:reconcile-square`

**Fundraising**
`donations:generate-year-end-receipts` (yearly, Jan 2 — generates then emails
PDF receipts)

**Messaging, marketing & metrics**
`campaigns:dispatch-scheduled` (4-hourly, claim-under-lock) ·
`email:dispatch-due-automations` · `messaging:prune-delivery-logs` ·
`marketing:site-health-digest` (weekly) · `metrics:capture-growth` (nightly
member-growth snapshot)

### Darkened (built, deliberately unscheduled)

Eight commands sit in `routes/console.php` as commented-out lines with a
note on how to relight them, because their features are switched off
platform-wide: `engagement:dispatch-followups`, `engagement:dispatch-dormancy`,
`engagement:dispatch-anniversaries`, `marketing:rollup-pageviews`,
`grants:send-deadline-reminders`, `donations:send-stewardship`,
`donations:recover-lapsed`, `slo:check-burn-rate`. Relighting one means
restoring the line *and* its entry in the schedule guard test. The
add-on-backed ones also skip any studio whose plan doesn't include the
feature.

## Central catalog (scheduled bare)

- `tenants:capture-usage-snapshot` — daily 03:30 UTC; iterates tenants itself;
  idempotent on (tenant, date).
- `platform:capture-growth` (nightly) · `platform:send-growth-digest`
  (Mondays; ISO-week dedup key) — platform growth snapshot and digest.
- `platform:notify-failed-signups` — hourly signup-recovery emails.
- `platform:audit-plan-limits` — nightly **soft** plan-cap audit (flags, never
  blocks); also opens and closes member-cap grace episodes.
- `platform:reconcile-addon-items` · `platform:sync-plan-discounts` — keep
  Stripe subscription items and discount coupons matching the records.
- `platform:end-expired-impersonations` — impersonation-token hygiene.
- `platform:prune-logs` — retention on central log tables (off by default).
- `reports:nightly` (central rollup) · `monitoring:heartbeat` ·
  `queue:check-worker` · `queue:check-backlog` · `queue:dispatch-heartbeat` ·
  `schedule:check-stale` · `live:check` — observability: a dead worker, a
  backed-up queue, a wedged schedule mutex, an unreachable websocket server.

A central command scheduled bare has to be on the schedule guard test's
allowlist, or the test treats it as a tenant cron someone forgot to wrap.

## Ops one-offs (not scheduled)

`studio:create` (idempotent provisioning via `TenantProvisioner`),
`studio:demo` / `studio:gallery-demo` (demo tenants), `studio:import`
(CSV data import, with `--dry-run`), `stripe:onboard` / `stripe:check`,
`platform:create-admin [--super]`, `email:seed-automations`,
`tenants:migrate`, `tenants:run` / `tenants:run-all`.

`tenants:run` forwards options to the inner command only as
`--option=key=value` (`tenants:run metrics:capture-growth --option=days=7`);
putting the option inside the quoted command is rejected by the outer one.
