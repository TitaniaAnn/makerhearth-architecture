# 6. Messaging & Email Automation

All outbound communication flows through **one trigger-driven engine**. There
are no bespoke transactional Mailables — `app/Mail/` holds exactly
`AutomatedEmail` (generic, pre-rendered subject+body) and `BulkCampaignMessage`
(manual campaigns). Adding a communication means adding a **trigger**, never a
Mailable.

## The dispatch contract

```php
EmailAutomationDispatcher::fire($trigger, $context, $recipient, $dedupKey);
```

- **`EmailTrigger`** is a fixed enum catalog (69 cases). Each case declares
  its surface (TENANT / PLATFORM / PUBLIC), opt-out category, whether it's a
  *system* trigger (editable copy, can't be disabled, bypasses opt-out — 
  enforced at the model layer by observers), and its context tokens.
- **Copy lives in editable defaults** (`DefaultEmailTemplates`), not code
  branches. The dispatcher falls back to the seeded default when a studio
  hasn't configured anything, so behavior never regresses pre-setup. Studios
  edit `EmailAutomation` + `EmailAutomationStep` rows via Filament.
- **Conditional/iterative content is composed tokens**, not Blade logic:
  callers pre-compose e.g. `{{pieces_html}}`. Only `_html`-suffixed tokens skip
  escaping, and their composers must `e()` every user value.
- **Drip steps**: `delay_minutes > 0` schedules the tenant-aware
  `SendAutomatedEmail` job; every fire writes an immutable
  `AutomatedEmailDelivery` row; the unique `dedup_key` makes fires idempotent.
- Optional in-memory **attachments** (the tax-receipt PDF, the order-receipt
  PDF when a studio turns it on) ride the immediate EMAIL path only.
- **Secret tokens** carry values that must be emailed but never stored, such
  as a gift-card code: the sent copy has the real value, the saved delivery
  row keeps a masked copy (last four only), and a drip step sending later
  from the row gets the masked value.

### Two engines, three surfaces

| Surface | Engine | Tables | Notes |
|---|---|---|---|
| TENANT (member comms) | `EmailAutomationDispatcher` | tenant schema | opt-out aware, multi-channel, A/B |
| PLATFORM (operator → tenant ops) | `PlatformEmailAutomationDispatcher` | central/public schema | email-only, raw-email recipients, no opt-out gate; managed at `/platform/emails`; add-on changes, discount changes, member-cap notices, the weekly growth digest |
| PUBLIC (marketing-site lead capture) | fires TENANT triggers inside `$tenant->run()` | tenant schema | autoresponder + staff notify |

## Multi-channel + A/B (tenant engine only)

Each step (and each A/B variant) carries a `MessageChannel` (EMAIL/SMS/PUSH),
routed through a `ChannelDriver` resolved by `ChannelManager`:

- Drivers **never throw on config gaps** — they return
  `ChannelResult::skipped(reason)` (`sms_disabled`, `no_phone`,
  `push_unconfigured`, …). SMS (AWS SNS) and Web Push (VAPID,
  `PushSubscription` rows registered from the portal) transport only when
  config **and** SDK are present, so CI runs the whole engine inert. SMS
  also needs the studio's plan to include it (`sms_not_on_plan` otherwise).
- **A/B variants are sticky and cross-channel**: one arm per recipient, picked
  by a weight-respecting hash of (recipient, step), stable across fires. An
  email arm can be tested against an SMS arm; each delivery row records its
  variant, so per-arm outcomes are a query over delivery rows.

## Bulk campaigns & deliverability

- `BulkMessageCampaign` → `CampaignSender` (owns
  SCHEDULED→SENDING→SENT/PARTIAL/FAILED) → per-recipient
  `BulkMessageDelivery` rows with open/click tracking (`EmailOpen`,
  `EmailClick`). Suppressions and preferences are loaded for the whole list
  up front, not per recipient.
- **HMAC-signed central routes** for the open pixel, click redirect, and
  unsubscribe (`/m/o`, `/m/c`, `/m/u` via `URL::signedRoute`); the unsubscribe
  key is a per-`EmailPreference` random token, never a delivery id. RFC 8058
  one-click headers (`List-Unsubscribe-Post`) point at the signature-gated
  POST route.
- **SES feedback loop:** `/webhooks/ses` verifies the real SNS signature
  (`SnsMessageValidator` — cert-URL host check, cert fetch+cache,
  `openssl_verify`), resolves the tenant from the message tag, and on hard
  bounce/complaint writes **both** a first-class `EmailSuppression` row and
  the legacy opt-out flag.
- **Suppression** (`EmailSuppressionService`) is checked by both the campaign
  sender and the automation dispatcher (EMAIL channel, even for
  system/transactional — a dead mailbox is undeliverable). One active
  suppression per (email, source) via partial unique index; releasable, with
  CSV bulk import; staff UI under *Messaging → Suppression list*.
- **Member preferences:** per-category opt-in toggles + hard "unsubscribe from
  all" on `/portal/account/email-preferences`; campaign footers offer both
  "manage preferences" and one-click unsubscribe.
- Scheduled sends: `campaigns:dispatch-scheduled` (4-hourly) claims each due
  campaign under `lockForUpdate` + status re-check, so concurrent sweeps never
  double-send; fatal failures park the campaign FAILED.

## Re-engagement & lifecycle layers

All evaluator-driven, cron-swept, idempotent, and opt-out aware:

- **Conversion attribution** — `ConversionTracker` stamps the most recent
  eligible campaign delivery when a recipient enrolls / reserves a ticket /
  activates a membership (best-effort post-commit hooks; idempotent on the
  source id; 14d window, 30d for renewals).
- **Behavioral triggers** (opened-no-conversion / never-opened /
  clicked-no-conversion) — pure-SQL evaluators over delivery rows, swept
  4-hourly, per-month dedup keys, seeded disabled (need a source campaign).
- **Dormancy / win-back** (30/60/90-day disjoint bands off the last studio
  visit + never-visited) — daily sweep, 60-day per-trigger dedup, seeded
  disabled.
- **Lifecycle** (birthday, membership anniversary, studio-hours milestones at
  25/50/100/250h) — anniversaries fire hourly but only in each tenant's
  **local** send hour; milestones fire once ever via an immutable
  `MemberMilestoneAward` row. Seeded enabled.
- **Fundraising sequences** — donation acknowledgment (transactional),
  stewardship 30/90/365-day touches, dedication notices, matching-gift nudges,
  lapsed-donor recovery (re-armed on each new gift). The stewardship,
  lapsed-recovery, behavioral, dormancy and anniversary sweeps are currently
  **darkened**: built and tested, but unscheduled until relit (see
  [09](09-scheduled-work.md)).
- **Operational emails added since** — rental started / ending soon /
  waitlist offer, gift card issued, procedure acknowledgment required and
  review due, waiver signed.

## Theming

`EmailThemeWrapper` optionally wraps rendered bodies in the tenant's theme
(literal hex — mail clients don't resolve CSS vars); off by default, wired at
every send site, stores the unwrapped body on delivery rows.

## Deliverability metrics

A pure read model (`DeliverabilityMetrics`) rolls the suppression feed and
delivery logs into bounce and complaint rates against SES's reputation
thresholds, shown on a staff dashboard with an 8-week trend. Per-tenant
delivery logs are pruned on a configurable retention window (off by default).
