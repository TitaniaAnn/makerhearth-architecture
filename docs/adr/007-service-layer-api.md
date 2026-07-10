# ADR 007 — The service layer is the domain API

**Status:** Accepted

## Context

Domain operations (enroll, book, consume, settle, refund, transition) each
bundle gates, capacity/locking decisions, pricing, side effects, and events.
Scattering that across controllers, Livewire components, Filament actions, and
crons would duplicate invariants across five surfaces.

## Decision

Every domain exposes sanctioned entry points in `app/Services/<Domain>/`.
UI layers and crons call services; **nothing calls `Model::create()` for a
domain operation**. Services own transactions and row locks; DTOs cross the
boundary; cross-cutting concerns are single-contract resolvers
(`BenefitResolver`, `TierGate`, `EmailAutomationDispatcher`) that call sites
plug into.

## Consequences

- Five surfaces (portal, admin, kiosk, POS, BCP replay) share one invariant
  set — BCP offline reconcile literally replays events through the same
  service entry points.
- Models stay thin; observers handle only invariants the type system can't.
- The convention is discipline-enforced (plus review passes and a few
  structural tests), not compiler-enforced — new code must follow it.
