# ADR 009 — Refund decisions are values; movement is separate

**Status:** Accepted

## Context

Refund policy (full/partial/none by time windows and product kind) is pure
business math that must be unit-testable and consistent across enrollments,
lessons, parties, and event tickets. Money movement involves Stripe, order
totals, and audit rows — and must also work with no Stripe at all.

## Decision

Cancellation services and refund policies return a **`RefundDecision` DTO**;
they never move money. `RefundService` turns decisions into `OrderRefund` rows,
rolls up order status, and mirrors Stripe-side refunds (Stripe stays the source
of truth for amounts). Stripe-less studios settle PENDING rows out-of-band.

## Consequences

- Policy is pure-function tested; movement is integration tested; neither
  contaminates the other.
- Every refund surface (portal request, staff action, POS capped monitor
  refund, event/table policies) converges on one movement path with one set of
  guards (net-refundable, one-pending-per-order, order row-lock).
- Cancelling frees the seat immediately even when the money resolves later.
