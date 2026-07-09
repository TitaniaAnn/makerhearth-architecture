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
- `GallerySale` (sales ledger)
- Campaign/pledge/peer-fundraiser totals (`raisedCents()` etc. are queries)

Corrections and refunds append new entries; nothing is edited. Filament
resources for ledger tables deny create/update/delete.

## Snapshot-on-purchase

`CartLineItem`, `OrderLineItem`, `PassPurchase`, `FiringPackagePurchase` freeze
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
features.

## State machines with named transitions

Status enums are cast on models, and transitions go through named service
methods that validate against a single transition map — illegal moves throw:

- `KilnLoad` — strictly forward; `consumeFiring` fires **only** on unload.
- `TenantStatus` / `TenantPlanStatus` — access truth vs. billing truth, two
  separate state machines (see [07](07-platform-operations.md)).
- `GrantStatus`, `SubstituteRequestStatus`, `PartyBooking` lifecycle,
  `BulkMessageCampaign` SCHEDULED→SENDING→SENT/PARTIAL/FAILED — each with
  row-locked transitions and (where audit-relevant) append-only history tables.

## Refund decisions are values, not actions

`cancelEnrollment` / `cancelBooking` return a `RefundDecision` DTO (tier,
amount, reasoning). Money movement happens separately in
`RefundService::applyDecisionTo…()`, producing `OrderRefund` rows. This split
keeps refund *policy* pure and testable and money *movement* auditable — and
lets Stripe-less studios settle refunds out-of-band while rows sit PENDING.

## Money

Integer cents everywhere via brick/money; single currency per tenant.
Proportional math goes through `MoneyMath::percentOfCents()` (banker's
rounding — `HALF_EVEN`), never `intdiv`. Views render money through the
`<x-money :cents>` Blade component only.

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

## Configuration & code hygiene

- `config()` over `env()` outside `config/*.php` (config caching breaks `env()`).
- `declare(strict_types=1);` in every file (Pint-enforced).
- Enums cast on models; no raw status strings.
- Constructor injection for domain services; facades only for framework
  concerns.
- No raw SQL outside migrations/tenancy internals.
- Server-composed HTML in emails/blocks is explicitly allowlisted
  (`_html`-suffixed tokens; fixed partial allowlists for marketing blocks) and
  the composer `e()`-escapes every user value — the default path escapes
  everything.
