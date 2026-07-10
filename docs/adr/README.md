# Architecture Decision Records

Significant, settled decisions with their context and consequences. These are
**committed** — don't re-litigate them mid-implementation; supersede with a new
ADR if circumstances genuinely change.

| # | Decision | Status |
|---|---|---|
| [001](001-schema-per-tenant.md) | Schema-per-tenant on PostgreSQL via stancl/tenancy | Accepted |
| [002](002-drop-cashier.md) | Drop Laravel Cashier; custom Connect subscriptions | Accepted |
| [003](003-stripe-optional.md) | Stripe (and every external service) is optional | Accepted |
| [004](004-filament-not-statamic.md) | Filament/Blade public site, not Statamic | Accepted (supersedes original plan) |
| [005](005-is-active-not-softdeletes.md) | `is_active` flags instead of Laravel `SoftDeletes` | Accepted |
| [006](006-ledger-computed-balances.md) | Immutable ledgers; balances computed, never stored | Accepted |
| [007](007-service-layer-api.md) | The service layer is the domain API | Accepted |
| [008](008-trigger-driven-email.md) | Trigger-driven email engine replaces bespoke Mailables | Accepted |
| [009](009-refund-decision-vs-movement.md) | Refund decisions are values; movement is separate | Accepted |
| [010](010-dokku-deploy.md) | Dokku on a single VM; Forge later | Accepted |
| [011](011-admin-theme-render-hook.md) | Admin theming via render hook, not `viteTheme()` | Accepted |
| [012](012-cookie-free-analytics.md) | Cookie-free, first-party pageview analytics | Accepted |

Format: **Context** (the forces), **Decision**, **Consequences** (what we
accept, what we gain). Kept deliberately short — the detail lives in the
numbered architecture docs.
