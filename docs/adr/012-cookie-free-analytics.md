# ADR 012 — Cookie-free, first-party pageview analytics

**Status:** Accepted

## Context

Studios want marketing-site analytics; embedding Google Analytics would add
third-party trackers, cookie-consent obligations, and data leaving the
platform — a poor fit for small community studios and their visitors.

## Decision

A first-party beacon with **no cookies and no cross-day identity**:
`visitor_hash = sha256(IP + UA + daily-rotating salt)`, DNT honored
client-side, no third-party requests, optional country-only GeoIP (off by
default). Raw events roll up nightly into a daily table (the only read path)
and are pruned per a tenant retention setting.

## Consequences

- No consent banner needed for analytics; visitor privacy is structural (the
  salt rotation makes re-identification across days impossible), not
  policy-based.
- Metrics are deliberately coarser than commercial analytics: unique visitors
  are per-day uniques; no funnels, sessions across days, or demographics.
- The rollup-then-prune pattern keeps the events table bounded and the
  dashboard fast at studio scale.
