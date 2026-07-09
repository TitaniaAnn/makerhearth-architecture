# 5. Payments & Billing

There are **two entirely separate Stripe surfaces**:

1. **Tenant → member billing** — each studio charges its members through its
   own **Stripe Connect** (Standard) account.
2. **Platform → tenant billing** — the platform charges studios a SaaS
   subscription on the **platform's own** Stripe account.

They share nothing: different API credentials, different webhooks, different
state machines.

## Tenant-side: Stripe Connect

- All Stripe calls go through `Billing/StripeClient` — a thin wrapper around
  `stripe-php` v17, **instantiated per call** with the tenant's connected
  account id. Never container-bound (per-tenant request safety).
- **Cashier was evaluated and dropped.** Its single-account model fights
  Connect-per-tenant, so membership subscriptions are custom
  (`Billing/SubscriptionService`) over the same per-call wrapper.
- The Connect webhook is a **single central endpoint** (`POST /stripe/webhook`).
  It resolves the tenant by the denormalized `stripe_connect_account_id`
  column on `tenants`, then `$tenant->run()`s the handling. Idempotent on the
  event id; short-circuits (still 200) for suspended/offboarding tenants.
- Onboarding: `stripe:onboard {tenant}` + hosted Connect onboarding with
  central return/refresh routes.

### The "order paid" contract

```
Stripe webhook ──▶ markPaidFromIntent() ─┐
                                         ├─▶ OrderService::markPaid()
staff / Tinker ──▶ settleOrder() ────────┘        │
                                                  ▼
                              activateDownstream() by product_type:
                              enrollment · lesson · party · event ticket ·
                              pass · firing package · membership
```

Every activation is keyed on the source order line item, so replayed webhooks
never double-activate.

### Stripe is optional

Every Stripe call short-circuits on `StripeNotConfigured`. A studio with no
Stripe account runs the full product: staff settle orders manually
(`settleOrder`), and `OrderRefund` rows stay PENDING for out-of-band
settlement. The test suite exercises all of it Stripe-less.

### Refunds

Cancellation services return a `RefundDecision` **value** (full/partial/none
per the policy windows in `StudioSettings`); `RefundService` turns decisions
into `OrderRefund` rows and mirrors Stripe-side refunds onto them. Monitor
(POS) refunds are additionally capped by
`StudioSettings.monitor_refund_cap_cents` (default 0 = staff only), row-locked
on the order, one pending refund per order.

## Platform-side: tenant plans

- **Two truths, two columns:** `TenantPlan.status` is the *billing* truth
  (TRIAL/ACTIVE/PAST_DUE/CANCELLED/…); the Phase-2 `TenantStatus` is the
  *access* truth (active/suspended/offboarding/deleted). A billing webhook
  moves billing status; it never directly touches access.
- **Plan catalog:** `PlanTier` / `Addon` seeded FREE/STARTER/GROWTH/PRO. New
  tenants start on a TRIAL of their chosen tier via `TenantProvisioner`.
- **Feature gating:** `PlanGate::allows($feature)` / `withinLimit()` — a
  cancelled/suspended plan drops to FREE flags; unresolvable default tier
  **fails closed** for gated features; only a truly empty catalog fails open
  (billing is optional too). Gated at creation *and* send for bulk email;
  numeric caps get a soft nightly audit that flags, never blocks.
- **Webhook:** `/platform/stripe/webhook` (own signing secret), distinct from
  the Connect endpoint. Hardened: idempotent on `stripe_event_id`, a
  `last_billing_event_at` high-water mark drops out-of-order events,
  `customer.subscription.*` only moves the plan whose **current**
  `stripe_subscription_id` matches, and a $0 trial-start invoice does **not**
  graduate TRIAL → ACTIVE — only the first real `amount_paid > 0` invoice does.
- **Signup funnel:** `/plans` → `/signup?plan=` → (if the tier is priced, wired
  to a `stripe_price_id`, and the platform is configured) hosted Stripe
  Checkout in subscription mode with a trial — card up front, no charge until
  trial end; the validated payload is stashed server-side and only an attempt
  id rides the redirect. Otherwise: immediate Stripe-less trial provisioning.
- **Metrics:** `/platform/billing` — MRR over ACTIVE plans (ANNUAL normalized
  to monthly), plan distribution, churn.

## Money handling (both sides)

Integer cents (brick/money), banker's rounding through
`MoneyMath::percentOfCents()`, `<x-money>` for display. Tier pricing overrides
are "best price the user qualifies for" and **never stack** with percentage
discounts; mid-cycle class joins prorate the tier-effective price
proportionally.
