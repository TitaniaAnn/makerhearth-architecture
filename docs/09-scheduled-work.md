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

## Tenant-scoped catalog (48 commands via `tenants:run-all`)

Grouped by domain; representative schedules — see `routes/console.php` for
exact times.

**Classes & scheduling**
`classes:generate-recurrences` · `classes:remind-24h` ·
`class-offerings:open-registration` · `class-recurrences:notify-price-review` ·
`waitlists:expire-offers` · `lessons:remind-24h` ·
`lesson-packages:expire-soon-remind` · `events:remind-24h` ·
`event-waitlists:expire-offers` · `parties:send-reminders` ·
`parties:complete-past`

**Firing & studio floor**
`firings:remind-unloaded` (stuck kilns) · `firings:notify-ready-for-pickup` ·
`firings:remind-stale-pickups` · `firing:sweep-expired-credit` ·
`shifts:close-forgotten` (POS, every 30 min) · `bcp:export-bundle`

**Memberships, passes, people**
`memberships:expire-scheduled` · `memberships:churn-warn` ·
`memberships:auto-renew-reminder` · `passes:expire-soon-remind` ·
`certifications:expire-soon-remind` · `waivers:remind-unsigned` ·
`auth:remind-unverified` · `guardians:end-aged-out` · `board:roll-year-over` ·
`volunteers:monthly-digest`

**Commerce & back office**
`carts:abandon-stale` · `reports:nightly` · `reports:fire-scheduled` (hourly) ·
`payroll:generate-pay-periods` · `inventory:low-stock-digest` ·
`tasks:generate-recurring` · `tasks:mark-overdue` · `tasks:overdue-digest` ·
`gallery:expire-displays` · `gallery:monthly-statements`

**Fundraising & grants**
`donations:generate-year-end-receipts` (yearly, Jan 2 — generates then emails
PDF receipts) · `donations:send-stewardship` · `donations:recover-lapsed` ·
`grants:send-deadline-reminders` (30/14/7-day disjoint bands)

**Messaging & engagement**
`campaigns:dispatch-scheduled` (4-hourly, claim-under-lock) ·
`email:dispatch-due-automations` · `engagement:dispatch-followups` (behavioral,
4-hourly) · `engagement:dispatch-dormancy` (daily) ·
`engagement:dispatch-anniversaries` (hourly, acts only in each tenant's local
send hour)

**Marketing site**
`marketing:rollup-pageviews` (nightly rollup + prune + salt rotation) ·
`marketing:site-health-digest` (weekly)

## Central catalog (scheduled bare)

- `tenants:capture-usage-snapshot` — daily 03:30 UTC; iterates tenants itself;
  idempotent on (tenant, date).
- `platform:notify-failed-signups` — hourly signup-recovery emails.
- `platform:audit-plan-limits` — nightly **soft** plan-cap audit (flags, never
  blocks).
- `platform:end-expired-impersonations` — impersonation-token hygiene.
- `reports:nightly` (central rollup) · `monitoring:heartbeat` ·
  `queue:check-worker` · `queue:check-backlog` · `queue:dispatch-heartbeat` —
  observability: the heartbeat/queue trio detects a dead worker or a backed-up
  queue.

## Ops one-offs (not scheduled)

`studio:create` (idempotent provisioning via `TenantProvisioner`),
`studio:demo` (demo tenant), `stripe:onboard` / `stripe:check`,
`platform:create-admin [--super]`, `email:seed-automations`,
`tenants:migrate`, `tenants:run` / `tenants:run-all`.
