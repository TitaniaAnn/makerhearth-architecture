# ADR 003 — Stripe (and every external service) is optional

**Status:** Accepted

## Context

The founding studio operates without a Stripe account; CI has no credentials
for anything; and studios adopt integrations piecemeal. A hard dependency on
any external service would block both.

## Decision

Every external integration is **config-gated and inert by default**. Stripe
calls short-circuit on `StripeNotConfigured` (manual `settleOrder` is a
first-class path; refunds stay PENDING for out-of-band settlement). Channel
drivers (SMS/push), Google Calendar sync, AI assists, GeoIP, and image
optimization degrade to typed skips or pass-throughs — never throw on a config
or SDK gap.

## Consequences

- The full product runs with zero external accounts; the test suite exercises
  every integration's *disabled* path as a real code path.
- Each integration needs an explicit "not configured" behavior designed up
  front (skip reason recorded on delivery rows, etc.) — slightly more code,
  vastly less operational coupling.
- Platform billing follows the same rule: a studio fleet can run with no
  platform Stripe account at all.
