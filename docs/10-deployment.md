# 10. Deployment

## Topology

**Dokku on a single Hetzner VM** (~$25/mo, zero PaaS subscription). Postgres 16
and Redis run as Dokku-managed containers on the same box; Dokku's multi-app
support shares the host with sibling projects. Laravel Forge is the documented
migration target once revenue justifies it (half-day cutover:
provision → `pg_dump`/restore → DNS).

## Deploy mechanism

`git push dokku main` → Heroku PHP buildpack (or shipped `Dockerfile`) builds
in a container → the Procfile `release` process runs → atomic symlink swap
into Traefik routing. **If release fails, the deploy aborts and the live
container is untouched** — migrations gate the deploy.

```
web:       vendor/bin/heroku-php-nginx -C nginx.conf public/
worker:    php artisan queue:work --tries=3 --max-time=3600
scheduler: loop: php artisan schedule:run every 60s
release:   php artisan migrate --force
           && php artisan tenants:migrate --force
           && php artisan optimize
```

- `tenants:migrate --force` walks all tenant schemas idempotently in the
  release step — new tenant migrations apply to every studio on deploy.
- Process scaling: `dokku ps:scale studio web=1 worker=1 scheduler=1`. Workers
  restart automatically on the container swap.
- Config is env-var-canonical via `dokku config:set` (and `config()` over
  `env()` in code means config caching is safe).

## TLS & subdomains

Let's Encrypt via `dokku-letsencrypt` with auto-renew. Per-tenant subdomains
need either manual SANs per tenant (the short-term path) or the DNS-01
wildcard challenge via a Cloudflare-token plugin (the plan when tenant #2
onboards).

## Backups

Nightly `dokku postgres:backup-schedule` to object storage. The standing rule:
**verify a restore before trusting it** — import into a scratch database,
inspect, destroy.

## Observability

- Laravel Pulse for in-app performance/queue visibility.
- `monitoring:heartbeat` + `queue:check-worker` / `queue:check-backlog` /
  `queue:dispatch-heartbeat` crons detect a dead scheduler, a dead worker, or
  a backed-up queue.
- Platform error capture (see [07](07-platform-operations.md)) turns every
  uncaught exception across every tenant into a triageable central row with
  operator alerting — the production error signal does not depend on log
  tailing.
- Logs land in `storage/logs/laravel.log`, tailable via `dokku logs -t`.

## Local development

Docker Compose (the host PHP lacks required extensions):

```bash
docker compose up -d
docker compose exec laravel.test php artisan migrate
docker compose exec laravel.test php artisan tenants:migrate
docker compose exec laravel.test php artisan studio:demo
# → http://clay-commons.localhost:8000
```

CI (GitHub Actions) is the source of truth for the environment contract; a
`SessionStart` hook reproduces it for cloud dev sessions (Postgres 16,
composer deps, `.env` pointed at the local test DB).
