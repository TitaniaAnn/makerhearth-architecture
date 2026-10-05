# 5. Payments & Billing

There are **two entirely separate billing surfaces**:

1. **Tenant → member billing** — each studio charges its members through its
   own **Stripe Connect** (Standard) account, or through its own **Square**
   account for card payments.
2. **Platform → tenant billing** — the platform charges studios a SaaS
   subscription on the **platform's own** Stripe account.

They share nothing: different API credentials, different webhooks, different
state machines. The platform takes **no transaction fee** on a studio's
sales.

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

### Card processors: Stripe or Square

Studios pick a processor in Studio settings (blank means Stripe). Both sit
behind one `CardProcessor` contract: hosted checkout, reader payments, voids,
refunds, saved cards, and `normalizeEvent()` → a neutral `ProcessorEvent`
(paid / failed / refunded). `CardProcessorManager` resolves the studio's
choice and never throws; an unconfigured processor comes back "unavailable"
and its money calls throw a typed `CardProcessorNotConfigured`.

- **Stripe** keeps everything it had: Connect-account checkout, server-driven
  Stripe Terminal readers (own-keys only, no application fee), saved cards
  via one Stripe Customer per member, membership and rental subscriptions.
- **Square** runs on the studio's own Square account: hosted payment links,
  Square Terminal pairing by device code, and listing/removing cards the
  member saved at the studio's Square register. Memberships and rentals
  still bill through Stripe.
- Orders record `payment_processor` + `processor_payment_ref`; a void or
  refund uses **the processor that took the payment**, not the studio's
  current choice.
- One Square webhook route serves two flows: a payment for one of our
  orders goes to `markPaidFromProcessorEvent()`; anything else falls through
  to the gallery register sync.

### The "order paid" contract

```
Stripe webhook ──▶ markPaidFromIntent() ──────────┐
Square webhook ──▶ markPaidFromProcessorEvent() ──┤
POS reader poll ─▶ (processor sync) ──────────────┤
                                                   ├─▶ OrderService::markPaid()
staff / Tinker ──▶ settleOrder() ─────────────────┘        │
                                                           ▼
                        activateDownstream() by product_type:
                        enrollment · lesson · party · event ticket · pass ·
                        firing package · membership · rental · gift card
                        then: redeem gift-card holds, record promo redemptions
```

Every activation is keyed on the source order line item, so replayed webhooks
never double-activate.

### Stripe is optional

Every Stripe call short-circuits on `StripeNotConfigured`. A studio with no
Stripe account runs the full product: staff settle orders manually
(`settleOrder`), and `OrderRefund` rows stay PENDING for out-of-band
settlement. The test suite exercises all of it Stripe-less.

### What the order total means

- **Tax is inside the line total.** Each line snapshots its rate and
  `tax_cents`; `total_cents` includes the tax, so Stripe Checkout, readers,
  POS tenders, invoices, store-credit caps and refund nets all carry tax
  without knowing about it. Rates are hand-entered (`decimal(7,4)`) and
  every active rate sums. Donations, gift cards and store credit are never
  taxed; nonprofits, products and individual customers can be exempt.
- **Gift cards are a tender.** `total_cents` stays the full price;
  `gift_card_cents` is what gift cards paid, and `nonGiftCardCents()` is
  what a card or cash must cover. Before payment, applied cards are held on
  the cart, and a card's available balance subtracts holds on other open
  carts so two carts can't spend the same dollars.
- **Promo codes** are applied after member pricing and folded into each
  line's discount, then rechecked under a lock on each code right before the
  order is created; an unpaid order from the last 24 hours holds a use.
- **Split tender** lives in an `order_payments` ledger; the remaining
  balance subtracts gift-card cents before anything else is charged.

### Refunds

Cancellation services return a `RefundDecision` **value** (full/partial/none
per the policy windows in `StudioSettings`); `RefundService` turns decisions
into `OrderRefund` rows and mirrors Stripe-side refunds onto them. Staff pick
where the money goes (card, store credit, or back onto a gift card). The
order's refunded total and status are derived from its live refund rows, and
every settlement locks the order, then the refund, and refuses to go past
the order total. Tax comes back in proportion (`MoneyMath::proportionOfCents()`). Monitor
(POS) refunds are additionally capped by
`StudioSettings.monitor_refund_cap_cents` (default 0 = staff only), row-locked
on the order, one pending refund per order.

## Platform-side: tenant plans

- **Two truths, two columns:** `TenantPlan.status` is the *billing* truth
  (TRIAL/ACTIVE/PAST_DUE/CANCELLED/…); the Phase-2 `TenantStatus` is the
  *access* truth (active/suspended/offboarding/deleted). A billing webhook
  moves billing status; it never directly touches access.
- **Plan catalog:** `PlanTier` / `Addon`, seeded FREE / STARTER / GROWTH /
  PRO / ENTERPRISE / GALLERY. New tenants start on a TRIAL of their chosen
  tier via `TenantProvisioner`. A tier's rank is its **monthly price**, never
  its sort order (GALLERY sorts last but is cheapest). ENTERPRISE is
  *sales-assisted* (a column, not a slug check): listed with its price, but
  signup redirects to a "talk to us" card and operators bind it.
- **Composable tiers:** only the spine (people, point of sale, transactions)
  is in every tier; every other module (gallery, firing, classes, rentals,
  fundraising, …) is selectable per tier, so a tier can match a business's
  shape. The default FREE tier must always carry every module flag, and a
  new module ships its own backfill migration, because the catalog seeder
  never touches existing tiers.
- **Price lock:** a studio's price is snapshotted when it joins a tier;
  repricing the tier in the console never moves an existing studio.
- **Annual billing:** 15% off every paid tier and add-on
  (`monthly × 12 × 85%`, HALF_EVEN), with its own Stripe price ids.
- **Add-ons grant features and are billed.** `PlanGate` reads add-ons as
  well as the tier, and add-ons can only widen access. Studio owners add and
  cancel their own add-ons: adding prorates onto the next invoice; cancelling
  runs to the end of the paid period. Per-unit add-ons (extra POS terminals)
  raise a numeric limit. An add-on can be complimentary (granted, never
  billed), which is how features a studio already used were kept when they
  became paid add-ons.
- **Discounts** (flagship, early adopter, nonprofit, plus annual prepay
  implied by the billing period) apply to the base tier only and **never
  stack**: the single largest wins. On Stripe that's an amount-off coupon
  restricted to the tier's product, so add-on items stay at list, applied
  only from the next billing period. Nonprofit status is an owner
  application with operator review and yearly re-verification.
- **Member cap is a grace period, never a lockout.** A nightly audit opens an
  "over the cap" episode, emails the owner once, and shows an admin banner;
  nothing reads the cap to block anyone.
- **Services price list:** optional paid services (data migration,
  training, website build) are recorded by operators against a studio with
  the price snapshotted; nothing in onboarding requires them.
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
- **Metrics:** `/platform/billing` — MRR over ACTIVE plans from each plan's
  locked, discounted price (ANNUAL normalized to monthly), plan distribution,
  churn. Platform billing calls always run in `CentralContext` so they can't
  pick up a studio's own Stripe key.

## Money handling (both sides)

Integer cents (brick/money), banker's rounding through `MoneyMath`,
`<x-money>` for display. Tier pricing overrides are "best price the user
qualifies for" and **never stack** with percentage discounts
(`TierGate::resolvePricing()` is the one copy of that rule); mid-cycle class
joins prorate the tier-effective price proportionally.

## Accounting sync

Paid orders and completed refunds become balanced journal entries through one
pure `JournalBuilder`: revenue per product type before tax, sales tax, tips
and gift-card liability on the credit side; what landed where (Stripe or
Square clearing, cash, undeposited funds) on the debit side. Studios export
CSV or QuickBooks Desktop IIF for free; live sync to QuickBooks Online, Xero
or Zoho Books is a paid add-on, sent as a daily summary or one entry per sale.
Each provider's quirks stay in its adapter (Xero manual journals address
accounts by code; Zoho's data-centre domains are allowlisted so a forged
callback can't redirect tokens).
