# 4. Architectural Patterns

These conventions are load-bearing and enforced across the codebase (several by
observers or tests). They originated in the Django reference implementation and
were translated, not redesigned.

## Service layer is the API

Every domain exposes sanctioned entry points under `app/Services/<Domain>/`.
Domain operations never happen via `Model::create()` from a controller or
Livewire component — `EnrollmentService::enroll($user, $offering)` runs the
capacity/waitlist branch, applies the benefit waterfall, prices the cart line,
and fires events. Services own transactions, row locks, gates, and side
effects. DTOs (`spatie/laravel-data` or plain readonly classes) cross the
service boundary, not arrays.

## Ledger pattern

Balance-affecting counts are **immutable credit/debit tables; balance is
computed, never stored**:

- `FiringLedgerEntry` (firing credits)
- `StoreCreditEntry` (store credit)
- `PassRedemption` (pass credits — remaining = purchased − redemption rows)
- `FreeClassLedgerEntry` (board free-class credits)
- `GiftCardLedgerEntry` (gift-card balances: issue / redeem / refund / adjust)
- `GallerySale` (sales ledger)
- `PromoRedemption`, `DrawerOpening`, `ProcedureAcknowledgment`,
  `MembershipLifecycleEvent` (append-only records that counts are read from)
- Campaign/pledge/peer-fundraiser totals (`raisedCents()` etc. are queries)

Corrections and refunds append new entries; nothing is edited. Filament
resources for ledger tables deny create/update/delete.

## Snapshot-on-purchase

`CartLineItem`, `OrderLineItem`, `PassPurchase`, `FiringPackagePurchase`,
`RentalAssignment`, and the platform's `TenantPlan` (a studio's price is locked
when it joins a tier; repricing the tier never moves an existing studio) freeze
price and terms at purchase time — no FK dereference to the live catalog row
for pricing. Re-pricing paths (e.g. quantity-merge re-adds) read the **frozen**
snapshot unit, so a later catalog change never silently reprices a cart.

## Concurrency: row-lock the aggregate root

Any operation that reads a computed balance and writes a debit wraps in
`DB::transaction()` and starts with `lockForUpdate()` on the contended row —
usually the `User` (firing consume/transfer, membership activate) or the
`Order` (markPaid, refunds), plus claim-then-recheck for cron sweeps
(scheduled campaigns) and one-open-row-per-key partial unique indexes
(monitor shifts, substitute requests, suppressions). A real two-connection
contention test proves the locks serialize (see [11-testing.md](11-testing.md)).

## Universal gates

Two gates run at **every** activity entry point (kiosk check-in, enrollment,
lesson/party booking, event signup, membership signup, pass purchase, POS
sale), in order:

1. `User::profileComplete()` — name + email + phone (widened for managed
   minors via guardian fallback).
2. `WaiverService::hasCurrentWaiver()` — immutable signature, separate
   revocation row; **no active waiver document means everything rejects**
   (fail closed).

Tier gating (`TierGate`) and plan gating (`PlanGate`, platform side) follow the
same "single resolver, many call sites" shape — and both fail closed for gated
features. `Features::enabledFor($flag)` is the one check for a plan feature:
the platform-level dark switch (if the feature has one) AND the plan tier or
an add-on the studio holds. Add-ons can only widen access, never narrow it.

## State machines with named transitions

Status enums are cast on models, and transitions go through named service
methods that validate against a single transition map — illegal moves throw:

- `KilnLoad` — strictly forward; no transition charges (firing is paid at drop-off).
- `TenantStatus` / `TenantPlanStatus` — access truth vs. billing truth, two
  separate state machines (see [07](07-platform-operations.md)).
- `GrantStatus`, `SubstituteRequestStatus`, `PartyBooking` lifecycle,
  `BulkMessageCampaign` SCHEDULED→SENDING→SENT/PARTIAL/FAILED — each with
  row-locked transitions and (where audit-relevant) append-only history tables.

## Refund decisions are values, not actions

Class and party cancellations compute a `RefundDecision` DTO (tier, amount,
reasoning) from a pure policy (`ClassRefundPolicy`, `PartyRefundPolicy`). Money movement happens separately in
`RefundService::applyDecisionTo…()`, producing `OrderRefund` rows. This split
keeps refund *policy* pure and testable and money *movement* auditable — and
lets Stripe-less studios settle refunds out-of-band while rows sit PENDING.

## Money

Integer cents everywhere via brick/money; single currency per tenant.
Proportional math goes through `MoneyMath` (`percentOfCents()`,
`proportionOfCents()`; banker's rounding, `HALF_EVEN`), never `intdiv` and
never a float `round()`. Views render money through the `<x-money :cents>`
Blade component; PHP-side strings use `MoneyMath::format()`. Admin money
inputs are typed in dollars and converted with exact decimal math.

## Soft delete via `is_active`

Laravel's `SoftDeletes` global scope hides rows — wrong for a domain where
historical records must stay queryable in admin lists, reports, and ledgers.
Catalog/config rows carry `is_active` booleans with explicit
`->where('is_active', true)` scopes instead.

## Idempotency everywhere a cron or webhook touches

- Reminder crons dedup via dedicated `reminder_<window>_sent_at` columns
  (windowed reminders use **disjoint bands** so escalation never double-fires).
- Activations key on the source order line item before creating.
- Webhooks key on the provider event id (`stripe_event_id`) + a
  high-water-mark timestamp to drop out-of-order events.
- The email dispatcher takes a caller-supplied unique `dedup_key` per fire.
- `firstOrCreate` on natural keys backs fire-once milestones and ledgers.

No cron ships without a dedup story; re-running any of them is safe.

## Immutability + observers as invariant enforcement

Append-only rows (`WaiverSignature`, `DonorInteraction`,
`PlatformAdminActivityLog`, status-history tables) are never updated. Model
observers enforce what the type system can't: system email automations can't
be disabled, FMV ≤ line total, board terms mint their free-class credit,
volunteer assignments check required qualifications, plan-gated campaign
creation is blocked at the model layer (not just the UI).

## Optional integrations, in the strongest sense

External services are **config-gated and inert by default** — the home studio
and CI run every code path with nothing configured:

- Stripe: every call short-circuits on `StripeNotConfigured`; manual
  `settleOrder` is a first-class path.
- SMS (SNS) / Web Push: channel drivers return `ChannelResult::skipped(reason)`
  on any config or SDK gap — never throw; the delivery row records why.
- Google Calendar sync, AI copy/alt-text (Anthropic), GeoIP: config-gated,
  degrade silently, never overwrite human input, errors return null.
- Image optimization: no GD → original-only variants, never throws.
- Card processors: `CardProcessorManager` hands back an "unavailable"
  processor for an unknown or unconfigured choice rather than throwing.
- Accounting sync: a provider with no platform app credentials has no
  Connect button; a refused token refresh marks the connection "needs
  reconnect" and stops, it doesn't throw.
- Live updates (Reverb): off or down means polling, which every live screen
  keeps running as the safety net.

## Darkened features

Some built features are switched off platform-wide in `config/features.php`
(advanced fundraising, pageview analytics, site-authoring extras, SLO
monitoring, lifecycle engagement). Their models, services and migrations stay;
their screens deny access and their crons are left unscheduled with a comment
saying how to relight them. A guard test pins the default-off contract, so
"fixing" one back on has to be deliberate.

## Shared contracts from the code audit

A four-agent code audit consolidated duplicated logic into named contracts.
New code routes through them instead of re-inlining:

- `TierGate::resolvePricing()`: the override-vs-discount rule (five copies
  used to disagree).
- `PassPurchasePolicy`: the pass-sale guard ladder and its staff/member
  wording.
- `BaseExpiryReminderCommand`: every "X expiring soon" email, with the
  date-vs-timestamp boundary as an explicit hook.
- `IteratesTenants`: central commands that sweep tenants themselves.
- `ResolvesSafeUrls`: dashboard links that resolve only if the viewer can
  open the target, and degrade to plain text otherwise.

## Configuration & code hygiene

- `config()` over `env()` outside `config/*.php` (config caching breaks `env()`).
- `declare(strict_types=1);` in every file (enforced by Pint's
  `declare_strict_types` rule, so CI rejects a missing declare).
- Enums cast on models; no raw status strings.
- Constructor injection for domain services; facades only for framework
  concerns.
- No raw SQL outside migrations/tenancy internals.
- Server-composed HTML in emails/blocks is explicitly allowlisted
  (`_html`-suffixed tokens; fixed partial allowlists for marketing blocks) and
  the composer `e()`-escapes every user value — the default path escapes
  everything.
