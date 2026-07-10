# ADR 008 — Trigger-driven email engine replaces bespoke Mailables

**Status:** Accepted

## Context

The platform accumulated ~24 per-scenario Mailables with copy hardcoded in
Blade. Studios need to edit copy, disable non-essential sends, honor opt-outs
per category, add drip delays, test variants, and use SMS/push — none of which
a class-per-email design supports.

## Decision

One dispatch contract — `EmailAutomationDispatcher::fire(trigger, context,
recipient, dedupKey)` — over a **fixed enum catalog** of triggers. Copy lives
in editable rows with seeded defaults (behavior never regresses pre-setup);
conditional content is composed tokens, not Blade logic; every fire writes an
immutable delivery row keyed by a dedup key. `app/Mail/` holds exactly two
generic Mailables. A central twin serves the platform→tenant surface.

## Consequences

- Adding a communication = adding a trigger case + default copy; the seeder
  and admin pick it up automatically. **Never reintroduce a bespoke
  transactional Mailable.**
- Opt-out, suppression, channels, A/B, theming, and attachments are solved
  once, at the dispatcher, for every communication.
- The trigger catalog is code-owned (studios configure, never invent) — a
  deliberate ceiling on per-tenant complexity.
