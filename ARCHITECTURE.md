# Architecture

This document is the long-form companion to the [README](README.md). The
README tells you *what* this repo contains and where each piece lives. This
document tells you *why* — what problem each decision solves, what
alternatives were considered, and where the seams are.

The seven runnable decisions below are ordered from most foundational to
most product-facing; each links to the extracted code and to the test that
verifies its contract. Two further decisions (§8, §9) describe production
architecture that is deliberately not republished in this cut. The
platform-level deep dives (multi-tenancy, messaging, the operator console,
deployment, security) live in [docs/](docs/).

---

## 1. The service layer is the domain API

### The problem

A domain operation — enroll, consume firing credit, mark an order paid —
bundles gates, capacity decisions, locking, pricing, and side effects. The
production platform has five UI surfaces (member portal, staff admin, kiosk,
POS, and an offline-replay path) that all perform the same operations. If
each surface wrote to models directly, every invariant would exist five
times and drift five ways.

### The decision

Domain state moves **only** through sanctioned service entry points. Models
are thin and deliberately give you no way to cheat: in this cut,
[`KilnLoad`](src/Firing/KilnLoad.php) and
[`Order`](src/Commerce/Order.php) use PHP 8.4 asymmetric visibility
(`private(set)`) so status is readable everywhere but writable only through
[`KilnLoadLifecycle`](src/Firing/KilnLoadLifecycle.php) and
[`OrderService`](src/Commerce/OrderService.php). The production platform has
271 service classes across 47 domain namespaces following this rule; the
BCP offline-reconcile path replays queued device events through the *same*
service entry points, which is the pattern's strongest payoff — an entire
extra surface for free, with no new invariant code.

### Why not the alternatives

Fat models (invariants in Eloquent events) hide multi-model workflows —
enrollment touches the offering, the enrollment, the cart, and the benefit
resolver, and no one of those models owns that transaction. A command-bus /
CQRS layer would add ceremony the team would fight on every feature; plain
service classes with typed methods are the cheapest thing that
centralizes the invariants.

### Where the seams are

The rule is discipline-enforced (plus review passes and structural tests),
not compiler-enforced — PHP has no way to make `Enrollment::create()`
private to a namespace. The `private(set)` trick used here closes the most
dangerous hole (status writes) at the language level, and is worth
retrofitting to more production models as 8.4 idioms settle in.

---

## 2. Money is integer cents with banker's rounding

### The problem

Money as floats loses cents; money as naive integer math loses them more
subtly. `intdiv($cents * $percent, 100)` truncates — on every odd-half case
(50% of $40.01) it short-changes the same party every time. Multiply that
across thousands of discount applications, revenue splits, and payroll
lines and the bias is real and always points the same direction.

### The decision

All money is integer cents in a single tenant currency, and **all
proportional math routes through one class** —
[`MoneyMath`](src/Support/MoneyMath.php) (included verbatim from
production) — which computes in exact rational/decimal space via brick/math
and rounds exactly once with HALF_EVEN (banker's rounding), the statistically
unbiased mode. Views render through a single money component, never inline
`cents / 100`.

Verified by [`tests/MoneyMathTest.php`](tests/MoneyMathTest.php): the
odd-half cases land on the even neighbour, complements sum exactly, and
fractional quantities (payroll hours, per-cubic-inch firing) stay in
decimal space with no float drift.

### Why not the alternatives

A Money object everywhere (brick/money's `Money` type) is what production
uses *at the arithmetic layer*, but threading value objects through every
Eloquent column, cart snapshot, and Blade view costs more than it returns —
the load-bearing decision is *integer cents + one rounding authority*, not
the wrapper type. Rounding HALF_UP would be simpler to explain but is
biased; "fair" beats "familiar" for money shared between a studio and its
members.

### Where the seams are

The class grows by addition, never by a second rounding rule. Since this cut
was first extracted, production added sales tax and partial refunds of taxed
orders, and both landed as new `MoneyMath` methods rather than local
arithmetic: `percentOfCents()` now takes a decimal string (tax rates are
`decimal(7,4)` columns, and 8.475% must not pass through a float), and
`proportionOfCents()` computes the tax share of a partial refund. Both are
tested here. `format()` / `formatDelta()` handle PHP-side display strings;
Blade still goes through the money component.

Single currency per tenant is assumed throughout. Multi-currency would
change `MoneyMath`'s signature (amounts would need their currency) and
every snapshot table — a deliberate non-goal until a real tenant needs it.

---

## 3. Immutable ledgers; balances computed, never stored

### The problem

Firing credit is money-like: members buy packages of kiln cubic inches and
consume them over months, staff issue corrections, credit expires, peers
transfer. A stored `balance` column drifts — under concurrency, under
corrections, under any bug — and when a member asks "why is my balance
380?", a number in a column has no answer.

### The decision

The ledger is an **append-only table of signed entries**; the balance is a
`SUM`. [`FiringLedger`](src/Ledger/FiringLedger.php) has credit, adjust,
consume, and transfer methods — and **no update or delete path at all**.
Corrections append [`ADJUSTMENT`](src/Ledger/LedgerEntryType.php) rows;
expiry is an explicit `EXPIRATION` debit posted by a scheduled sweep (never
a read-time filter — filtering expired credits while keeping the debits
taken against them produced phantom negative balances in an earlier
design). Consumption is idempotent per firing (the `billedAt` stamp is
re-checked inside the serialized section) and guarded against driving the
balance negative.

Production wraps every balance mutation in a transaction that row-locks the
User (`lockForUpdate()`), and transfers lock both parties in PK-sorted
order to avoid deadlock; this SQLite cut serializes with an `IMMEDIATE`
transaction — the same guarantee, database-wide instead of per-row.

Verified by [`tests/FiringLedgerTest.php`](tests/FiringLedgerTest.php):
balance is the sum, corrections append rather than edit, double-consume
writes exactly one debit, the negative-balance guard fails closed (with an
opt-in member-deficit mode), and transfers are an atomic debit/credit pair.

The same pattern backs store credit, pass redemptions, board free-class
credits, and gallery sales in production, and campaign/pledge totals follow
the read-side half (computed, never stored).

### Why not the alternatives

A balance column with careful `UPDATE ... SET balance = balance - ?` is
fast and *usually* right, but "usually" is the problem: it can't answer
audit questions, and every new mutation path is a new chance to forget the
careful form. Event sourcing proper (rebuild all state from events) is the
same idea at framework scale — far more machinery than five ledgers need.

### Where the seams are

Reads are O(entries-per-user) — fine at studio scale, and the hot public
reads are cached. If a ledger ever grows past that, the escape hatch is
periodic snapshot rows (a `SNAPSHOT` entry summing everything before it),
which preserves append-only semantics; nothing about the API would change.

---

## 4. Strictly-forward state machines; consume on unload

### The problem

A kiln load moves through a physical process: loading → ready → firing →
cooling → ready-to-unload → unloaded, with abort possible any time before
the end (kiln fault, glaze disaster). Members must never pay for pieces
that didn't survive a successful firing — and "never" has to survive
concurrent staff actions, retried jobs, and future code changes.

### The decision

[`KilnLoadStatus`](src/Firing/KilnLoadStatus.php) is an enum whose
`canTransitionTo()` map is the single source of truth; transitions happen
only through [`KilnLoadLifecycle`](src/Firing/KilnLoadLifecycle.php)'s named
methods, and illegal moves throw. Billing lives **only** on the transition
into UNLOADED. Because ABORTED is terminal and can never reach UNLOADED,
"aborted loads never bill" is enforced by the shape of the state graph
rather than by an `if` someone could delete.

Verified by
[`tests/KilnLoadLifecycleTest.php`](tests/KilnLoadLifecycleTest.php): no
skipping, no moving backward, the happy path bills each piece exactly once,
and an aborted load's members keep their full balance.

Production uses the same shape for tenant lifecycle, plan billing status,
grant pipelines, substitute requests, party bookings, and campaign sends —
each a status enum + a transition map + named, row-locked service methods
+ (where audit matters) an append-only history table.

### Why not the alternatives

A workflow engine (Temporal, Symfony Workflow) is built for graphs that
change at runtime or span services; these graphs are small, fixed, and
in-process — a custom enum costs less than the abstraction. Status
booleans (`is_fired`, `is_aborted`) are the classic alternative and the
classic source of impossible states.

### Where the seams are

The transition map is code, so a studio cannot customize the kiln process —
deliberate: the physics doesn't vary. The map's one soft spot is
transitions with side effects (unload → consume): the lifecycle must call
the ledger *after* the transition commits, and the ledger's own idempotency
(§3) is what makes a crash between the two safe to retry.

---

## 5. Benefit resolution across sources

### The problem

"What does this member get?" has four answers in production — an active
membership tier, an active pass, a volunteer role, a board term — each
granting a bundle of discounts and allowances, and a member can hold
several at once. If cart pricing, kiosk eligibility, and firing billing
each aggregated sources themselves, adding a fifth source would mean
finding every pricing branch in the codebase.

### The decision

[`BenefitResolver`](src/Benefits/BenefitResolver.php) is **the single
contract**: call sites ask it, never the sources. Sources implement
[`BenefitSource`](src/Benefits/BenefitSource.php) (in production, Eloquent
relations with "active" scopes) and the resolver aggregates with fixed,
deliberate semantics — **max for discounts** (the best single discount
wins; two 15% sources do not make 30%), **OR for booleans**, **sum for
allowances**. The result is an immutable
[`EffectiveBenefits`](src/Benefits/EffectiveBenefits.php) value.

Verified by
[`tests/BenefitResolverTest.php`](tests/BenefitResolverTest.php) — max is
taken per-field across packages, booleans OR, no sources resolves to
`none()` rather than null.

Production has a sibling resolver with the same shape:
`TierGate`, the single authority for "may this member buy this catalog row,
and at what tier price". Every catalog surface (passes, classes, events,
products, firing packages, rentals) gates through it, and
`TierGate::resolvePricing()` is the one rule for pricing: the member pays
the lower of the tier override or the surface's discounted base, never
both. That method exists because the rule was once copied into five
surfaces and the copies disagreed. Classes compared the override against
the *undiscounted* base, so a member whose percentage discount beat the
override was overcharged. A code audit found it and folded every copy into
the resolver. Promo codes follow the same max-not-sum rule per cart line:
a line covered by two codes takes the single best discount.

### Why not the alternatives

Aggregating in SQL (one big UNION view) would make the semantics implicit
in a query nobody reads. Stacking discounts is what members would prefer
and what studios would go broke on — max-not-sum is a *business* rule, and
centralizing it is what makes it auditable.

### Where the seams are

Allowance fields are added to `EffectiveBenefits` as consuming code needs
them — the value object grows field by field. A new source is one class +
one registration; a new *aggregation rule* (say, "highest tier wins
entirely") would be a change to the resolver itself, which is exactly where
you'd want to review it.

---

## 6. Refund decisions are values, not actions

### The problem

Refunds mix two things that change for different reasons: the *policy*
("cancelled 30 hours before start → 50%") and the *movement* (Stripe
calls, order-total rollups, audit rows, staff settlement when there's no
Stripe). An earlier iteration issued refunds inside the cancellation
services; testing policy meant mocking Stripe, and adding a surface meant
re-implementing windows.

### The decision

Policies are pure: [`RefundPolicy`](src/Refunds/RefundPolicy.php) computes
window math over hours-before-start and returns a
[`RefundDecision`](src/Refunds/RefundDecision.php) — tier, amount, reason —
whose class body is production verbatim. Movement lives elsewhere
(`RefundService` in production) and consumes decisions; it never re-derives
amounts. Class enrollments and party bookings feed decisions into **one**
movement path with one set of guards (net-refundable, one-pending-per-order,
order row-lock, POS monitor caps). The order's refunded total is derived
from its live refund rows (`RefundService::rollUpOrder()`), and every
settlement goes through one method that locks the order, then the refund,
and refuses to settle past the order total.

Verified by [`tests/RefundPolicyTest.php`](tests/RefundPolicyTest.php):
window boundaries inclusive, partial amounts through banker's rounding,
zero-paid → nothing owed — all as arithmetic, no mocks.

### Why not the alternatives

"Just do the refund in `cancel()`" is the obvious design and the brittle
one — it welds a pure decision to an effectful action, and the no-Stripe
studio (a hard requirement, see §9) breaks it immediately. An approval
workflow engine is more than a studio needs; PENDING refund rows + staff
settlement covers the human loop.

### Where the seams are

Decisions carry a single amount. When production added refunds to gift
cards and store credit, the destination became a movement-side choice
(staff pick "Refund to" when settling) and the DTO didn't change, which is
the split working as designed: the policy decides *how much*, the movement
decides *where it goes*. Production also deleted a fully built and tested
refund-decision pipeline for events and lessons that no cancellation
surface ever called; an unwired policy is dead code, however correct. The DTO is also where a "why" audit lives — the
`reason` string is shown to members verbatim, which keeps policy authors
honest.

---

## 7. Snapshot-on-purchase and the single paid contract

### The problem

Two failure modes plague commerce code. First, **price drift**: staff
reprice the catalog while a member has a cart open, and the member pays a
price they never saw. Second, **activation drift**: multiple payment paths
(webhook, return URL, manual settlement) each doing their own "now grant
the thing" logic, so a replayed webhook double-mints a pass or a manual
settle forgets to confirm the enrollment.

### The decision

Two halves. [`CartLine`](src/Commerce/CartLine.php) is a **readonly
snapshot** — unit price, discount, and discount source frozen at
add-to-cart, no reference to the live catalog row; the cart line is the
only place a price is computed, and its `sourceId` is the pointer
activation follows back to the pending domain row. And
[`OrderService::markPaid()`](src/Commerce/OrderService.php) is **the single
contract for "this order has been paid"**: the Stripe webhook, the checkout
return URL, and manual settlement all converge on it; it is idempotent
(already-PAID returns unchanged), and it dispatches downstream activation
by product type — strictly, so a vanished source row throws rather than
silently minting a fresh one.

Production has since added a second card processor (Square, beside
Stripe), gift cards as a tender, sales tax and promo codes. None of them
added a second "paid" path. Stripe's webhook still arrives through
`markPaidFromIntent()`; Square's arrives through a processor-neutral
`markPaidFromProcessorEvent()`; both end in the same `markPaid()`. A gift card pays
part of an order without changing `total_cents` (the order records
`gift_card_cents` beside it, so refund caps and revenue reads stay right).
Tax is computed once, at the cart line, and included in that line's total,
so checkout, card readers, refunds and store-credit caps carry it with no
changes of their own.

Verified by [`tests/OrderServiceTest.php`](tests/OrderServiceTest.php):
the snapshot survives a catalog reprice, replayed `markPaid` activates
exactly once, per-type dispatch skips past-consumption lines, and
activation strictness propagates.

### Why not the alternatives

Re-pricing at checkout ("the price is whatever the catalog says now") is
simpler and wrong — it turns every admin catalog edit into a silent change
to open carts. Activating on webhook receipt directly (skipping a common
contract) is how double-activation bugs are born; in production each
activation is *additionally* keyed on its source order line, so even a bug
in the idempotency check can't double-mint.

### Where the seams are

The `sourceId` fan (production: a set of nullable `source_*_id` columns,
deliberately FK-free on the cart to avoid circular references) trades
referential integrity for decoupling — the join is by convention, guarded
by the strictness rule. New product types plug in as a new enum case + one
registered activation; space rentals and gift cards were both added to
production that way.

The cost of "tax lives inside the line total" is that anything meaning
*the price of the goods* (the gallery commission base, the tax-deductible
part of a donation receipt, revenue on the P&L) has to read
`total − tax`. Production names those sites; a new one has to remember.

---

## 8. Schema-per-tenant multi-tenancy *(production — not in this cut)*

Every studio is a Postgres **schema**, identified by subdomain
(stancl/tenancy v3). Isolation is physical — a query-scope bug cannot leak
another studio's rows, and offboarding is "drop the schema, write a
tombstone." Everything crossing the boundary is explicit: webhooks resolve
the tenant from event metadata and `$tenant->run()` the handling; every
tenant cron is wrapped in a runner that iterates tenants with per-tenant
error isolation and skips suspended studios; queued jobs carry their tenant.
The trade — per-tenant migration cost and the "forgot the tenant context"
failure mode — is bounded by studio-scale tenant counts and guarded by a
real-schema HTTP smoke test plus structural tests that fail CI if a tenant
cron is ever scheduled bare.

This cut's ledger and services are single-tenant on purpose; republishing
the tenancy wiring would mean republishing half the platform. The full
treatment: [docs/02-multi-tenancy.md](docs/02-multi-tenancy.md).

---

## 9. Optional integrations, in the strongest sense *(production — not in this cut)*

The founding studio runs with **no Stripe account**, and CI runs with no
credentials for anything. So "optional" is a hard contract, not a fallback:
every Stripe call short-circuits on a typed `StripeNotConfigured` (manual
settlement — §7's `settleOrder` — is a first-class path, and refunds wait
as PENDING rows for out-of-band settlement); SMS/push channel drivers
return typed skip results (`no_phone`, `sms_sdk_missing`) rather than
throwing; calendar sync, AI assists, and image optimization degrade
silently. Later integrations follow the same rule: card processors sit
behind a `CardProcessor` contract whose manager never throws (an
unconfigured choice gets an "unavailable" processor), accounting sync to
QuickBooks/Xero/Zoho has no Connect button until the platform app is
configured, and live websocket updates fall back to polling when the
socket server is off or down. The test suite exercises every integration's *disabled* path as
a real code path — which is the only reason the contract stays true.

The full treatment: [docs/05-payments-and-billing.md](docs/05-payments-and-billing.md)
and [docs/04-architectural-patterns.md](docs/04-architectural-patterns.md).
