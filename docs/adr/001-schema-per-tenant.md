# ADR 001 — Schema-per-tenant on PostgreSQL via stancl/tenancy

**Status:** Accepted

## Context

Every studio's data must be strongly isolated (members, payments, PII), tenant
count is modest (tens–hundreds of studios, not millions), and the reference
Django implementation used `django-tenants` (schema-per-tenant) successfully.
The alternatives were row-level tenancy (a `tenant_id` column + global scopes)
or database-per-tenant.

## Decision

Schema-per-tenant on Postgres 16 via **stancl/tenancy v3**: subdomain
identification, `search_path` per request, split central/tenant migration
directories, tenant-aware queues and cache.

## Consequences

- Isolation is physical: a bug in a query scope cannot leak another studio's
  rows, and dropping a schema is a complete offboarding primitive.
- Migrations run per tenant (`tenants:migrate` in the deploy release step);
  cost scales with tenant count — acceptable at this scale.
- Everything crossing the boundary must be explicit: webhooks, crons, the
  platform console, and queued jobs all resolve a tenant and `$tenant->run()`.
  Forgetting tenancy on a job/cron silently targets the central schema — this
  class of bug is guarded by structural tests and the real-schema HTTP smoke
  test.
- Central tables needing in-tenant readability (error capture, platform email
  automations) live in the public schema and rely on the search-path fallback.
