# ADR 010 — Dokku on a single VM; Forge later

**Status:** Accepted

## Context

Pre-revenue, capital is tight; the operators are CLI-comfortable; the workload
(a fleet of small studios) fits one Hetzner VM. PaaS subscriptions add
recurring cost for convenience not yet needed.

## Decision

**Dokku** on a single Hetzner box: git-push deploys via the Heroku PHP
buildpack, Procfile process types (web/worker/scheduler/release), Dokku-managed
Postgres/Redis/Let's Encrypt, nightly DB backups to object storage.
Migrations (central + all tenants) run in the `release` step, so a failed
migration aborts the deploy atomically. **Laravel Forge is the designated
migration target** once revenue justifies it (~half-day cutover).

## Consequences

- Zero PaaS cost; one box to reason about; atomic deploys with
  migrations-as-gate.
- Single-VM blast radius accepted for now; mitigations are verified backups
  (restore tested, not just scheduled) and the heartbeat/queue observability
  crons.
- Wildcard tenant TLS needs DNS-01 (Cloudflare plugin) — manual SANs until
  tenant #2.
