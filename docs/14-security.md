# 14. Security Architecture

## Authentication surfaces

| Surface | Mechanism | Notes |
|---|---|---|
| Member portal | tenant `users` session (email verify, password reset) | schema-per-tenant isolates credentials per studio |
| Staff admin (Filament) | same tenant session + role flags (`is_studio_staff`, …) + owner-defined staff roles | one `StudioPolicy` for every model; donor data, payroll, comps, POS PINs and devices are separate role powers |
| Kiosk | **no user session** — paired-device token (bcrypt-hashed, issue/rotate/revoke) + physical presence; PIN/QR for identification | device pairing via one-time `?token=`, persisted as a cookie |
| POS | paired-device token + operator PIN per action (rate-limited per terminal); inactivity lock | operators are staff or members holding a volunteer role that grants POS access; every transaction shift-attributed |
| Receipt printer | the printer polls `/cloudprnt` with HTTP Basic, its device token as the password | a printer only ever sees its own jobs |
| Live-update channels | `/broadcasting/auth` in the tenant domain group, signed per socket | staff, POS operators, paired terminals and kiosks only; a channel naming another tenant is denied and reported |
| Platform console | dedicated `platform_admin` guard, central table, **mandatory TOTP**, 30-min idle timeout, throttled login/2FA, CLI-only account creation | cookie-isolated from tenant sessions; super-admin flag not mass-assignable |
| BCP device | `X-BCP-Device-Token` (SHA-256 matched) for reconcile; HMAC-signed snapshot bundle with TTL | snapshot drives display only, never billing |

Tenant isolation itself is an auth boundary: schema-per-tenant means a tenant
session physically cannot query another studio's rows, and central tables hold
no cross-schema FKs.

## Authorization model

Role **flags on `User`**, enforced in four layers: route middleware
(`monitor`, `pos.terminal`, `embed.headers`, `EnforceTenantStatus`), Filament
`canAccess()` per resource, **`StudioPolicy`** (every model's policy:
viewing is any active staff; create/update needs the area's *edit*
permission; delete needs its *delete* permission), and the **service layer**
(universal profile/waiver gates, tier/plan gates, cross-tenant guards in
`TicketService`). Permission areas come from the admin sidebar sections, so a
resource's permission moves with it. Owner-defined staff roles grant areas
and extra powers on top of a default role that every staff member has; the
studio owner has everything. A screen checked with no signed-in user is
denied. Payroll lines can't be edited by the person they pay (unless that's
the owner), staff can't comp themselves, and donor reports refuse to run
for anyone without the donor-data power, including when a schedule runs as
its creator.
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
- **Square**: HMAC-verified **before** entering the tenant; a payment event
  for one of our orders is matched by processor + reference, else by the
  order reference echoed back.
- **Accounting OAuth**: providers need a fixed redirect URI, so the callback
  is central. The tenant, user, provider, a nonce and a 15-minute expiry
  travel in an **encrypted** `state`; the nonce is single-use. Zoho's
  data-centre domains from the callback are only used if they match Zoho's
  own allowlist over https, so a forged callback can't redirect tokens.
  Provider tokens are stored with encrypted casts.
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
- **Gift-card codes are never stored.** The table keeps an HMAC keyed with
  the app key (findable, not readable) and the last four characters; the
  code is emailed as a secret token, so even the stored email row is
  masked. Balance checks are rate-limited per member.

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
  immutable; donor data is gated to development staff and the donor-data
  role power (including the donor/grant activity logs and anonymous gifts on
  dashboards); PDFs (tax and order receipts) are generated server-side and
  delivered to the addressee only; a member's order receipt URL returns 404,
  not 403, to anyone else.
- **Live-update messages carry no data**, only a topic and an id; screens
  re-read the database, so a message can't leak or spoof content.
- **Nonprofit determination letters** live on the central private disk and
  download only from the operator console.

## Rate limiting & abuse

Named limiters on platform login/2FA, embed endpoints (30/min/IP), the
pageview beacon (60/min/IP), signup checkout return, POS PIN attempts (per
terminal), gift-card balance checks (10/min/member), the CloudPRNT endpoint,
the browser-error beacon, and live-channel auth. Error-capture sampling caps runaway loops; plan caps get a
soft nightly audit.

## Audit trail

Tenant side: spatie activity log on audit-sensitive models (memberships,
applications, board terms, volunteer assignments, firings, kiln-load status,
rental assignments, procedures, donor stage/notes, grant amounts), guarded by
a test that fails if a model drops the trait. Studio owners' billing actions
(add-ons, discount applications) are logged on both sides: the studio's
activity log and the platform's audit log. Platform side: append-only
`PlatformAdminActivityLog` for every operator action **including auth events
and reading the audit log itself**. Status changes on grants/tickets write
append-only history tables. Client IPs are captured through a single
`Support\ClientIp` helper.
