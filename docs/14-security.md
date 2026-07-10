# 14. Security Architecture

## Authentication surfaces

| Surface | Mechanism | Notes |
|---|---|---|
| Member portal | tenant `users` session (email verify, password reset) | schema-per-tenant isolates credentials per studio |
| Staff admin (Filament) | same tenant session + role flags (`is_studio_staff`, …) via `canAccess()` gates | dev-staff-only resources (donor CRM, grants) additionally gate on `is_development_staff`/owner |
| Kiosk | **no user session** — paired-device token (bcrypt-hashed, issue/rotate/revoke) + physical presence; PIN/QR for identification | device pairing via one-time `?token=`, persisted as a cookie |
| POS | paired-device token + monitor PIN per action (rate-limited per terminal); inactivity lock | staff ⊇ monitor; every transaction shift-attributed |
| Platform console | dedicated `platform_admin` guard, central table, **mandatory TOTP**, 30-min idle timeout, throttled login/2FA, CLI-only account creation | cookie-isolated from tenant sessions; super-admin flag not mass-assignable |
| BCP device | `X-BCP-Device-Token` (SHA-256 matched) for reconcile; HMAC-signed snapshot bundle with TTL | snapshot drives display only, never billing |

Tenant isolation itself is an auth boundary: schema-per-tenant means a tenant
session physically cannot query another studio's rows, and central tables hold
no cross-schema FKs.

## Authorization model

Role **flags on `User`**, enforced in three layers: route middleware
(`monitor`, `pos.terminal`, `embed.headers`, `EnforceTenantStatus`), Filament
`canAccess()` per resource, and the **service layer** (universal
profile/waiver gates, tier/plan gates, cross-tenant guards in `TicketService`).
The platform side adds `platform.super` for destructive lifecycle actions.
Gates fail closed: no waiver document → everything rejects; unresolvable plan
tier → paid features denied.

## Webhook & callback integrity

- **Stripe** (both endpoints): signature verification with per-endpoint
  secrets; idempotent on event id; the platform webhook additionally drops
  out-of-order events (high-water mark) and ignores subscription events whose
  id doesn't match the plan's current subscription.
- **SES/SNS**: real signature verification (`SnsMessageValidator` — cert URL
  must be `sns.<region>.amazonaws.com` over HTTPS ending `.pem`; cert fetched,
  cached, `openssl_verify`'d per `SignatureVersion`).
- **Google Calendar push**: acks 200 always, queues a tenant-aware job;
  config-gated.
- Suspended/offboarding tenants: all webhooks short-circuit (still 200) — no
  background mutation for blocked tenants.

## Signed & single-use URLs

- Email open pixel, click redirect, and unsubscribe are **HMAC-signed**
  (`URL::signedRoute`); the unsubscribe key is a per-preference 32-byte random
  token — delivery ids are never exposed. RFC 8058 one-click POST is
  signature-gated and CSRF-exempt by exact path.
- Platform **impersonation tokens are single-use**: redemption row-locks and
  stamps `redeemed_at`; a captured URL can't be replayed. Expiry swept by cron;
  ending an impersonation is owner-scoped.
- BCP pairing URLs are one-time.

## Injection defenses

- **View-path injection:** marketing block types map to partials via a fixed
  allowlist — never interpolated `@include`.
- **XSS:** all email tokens escape by default; only `_html`-suffixed
  server-composed tokens skip escaping, and their composers `e()` every user
  value. JSON-LD is `JSON_HEX_TAG|JSON_HEX_AMP`-encoded against `</script>`
  breakout.
- **CSS injection:** theme override values are sanitized (reject `;{}<>:`,
  escapes, over-long strings) before entering `<style>` blocks.
- **CSP injection:** embed parent domains are sanitized to hostname-legal
  characters before entering the `frame-ancestors` header.
- **SSRF:** the site importer allows http(s) only and blocks
  loopback/private-address literals.
- **SQL:** no raw SQL outside migrations; Eloquent/query-builder everywhere.

## CSRF & embedding

Laravel's CSRF everywhere, with two deliberate, narrow exemptions: webhook
paths and the signed one-click unsubscribe. Framing is **denied by default**
(`X-Frame-Options: DENY`); enabling embeds swaps to a
`frame-ancestors` CSP listing sanitized parent domains. The cross-origin
portal-embed mode uses an embed-aware CSRF subclass that *additionally* trusts
POSTs whose Origin is an allowed parent — strictly additive and fail-closed;
non-embed CSRF is never weakened.

## PII & privacy

- **Error capture scrubs before storing:** sensitive headers/body keys
  (auth, cookies, passwords, card/SSN/DOB) plus contact-PII keys and
  value-level email/PAN scrubbing in free text; fingerprint messages are
  scrubbed too.
- **Pageview analytics is cookie-free:** visitor hash = sha256(IP + UA +
  daily-rotating salt) — no cross-day tracking; DNT honored; raw events pruned
  per retention setting; optional country-level GeoIP only.
- **Demographics are opt-in** with consent copy; withdrawing consent wipes the
  stored values; report callables emit only cell-suppressed aggregates
  (cells < 5 → `<5`).
- **Public projections are privacy-safe at the query layer:** embeds and
  marketing live-data blocks expose instructor first names, seat counts (not
  rosters), and publicly-bookable rows only.
- **Waiver signatures** (incl. drawn images, size/type-sanitized) are
  immutable; donor data is gated to development staff; PDFs (tax receipts) are
  generated server-side and delivered to the addressee only.

## Rate limiting & abuse

Named limiters on platform login/2FA, embed endpoints (30/min/IP), the
pageview beacon (60/min/IP), signup checkout return, and POS PIN attempts
(per terminal). Error-capture sampling caps runaway loops; plan caps get a
soft nightly audit.

## Audit trail

Tenant side: spatie activity log on audit-sensitive models (memberships,
applications, board terms, volunteer assignments, firings, donor
stage/notes, grant amounts). Platform side: append-only
`PlatformAdminActivityLog` for every operator action **including auth events
and reading the audit log itself**. Status changes on grants/tickets write
append-only history tables. Client IPs are captured through a single
`Support\ClientIp` helper.
