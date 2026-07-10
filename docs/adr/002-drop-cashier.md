# ADR 002 — Drop Laravel Cashier; custom Connect subscriptions

**Status:** Accepted (deviation from the original plan)

## Context

The original plan was "Cashier for membership subscriptions, stripe-php for
Connect." In practice the seam didn't hold: Cashier assumes a single platform
Stripe account, while every tenant bills members through its **own Connect
account**, resolved per request.

## Decision

Use **stripe-php directly** for everything, behind a thin `StripeClient`
wrapper instantiated **per call** with the tenant's connected-account id.
Membership subscriptions are custom (`Billing/SubscriptionService`). No Cashier
dependency exists in `composer.json`.

## Consequences

- One consistent Stripe path for payments, refunds, subscriptions, and
  onboarding; per-tenant request safety (no long-lived container-bound client).
- We own subscription lifecycle sync (webhook handlers) that Cashier would have
  provided — implemented and tested.
- Do not reintroduce Cashier; the wrapper is the sanctioned seam.
