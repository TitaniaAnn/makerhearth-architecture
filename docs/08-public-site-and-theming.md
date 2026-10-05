# 8. Public Site & Theming

## The block-based site builder

Each tenant's public marketing site is served from **central** routes
(`GET /studio/{tenant}` + child pages at `/studio/{tenant}/{path}`), rendering
inside `$tenant->run()` so live-data blocks resolve tenant models. Built on
Filament/Blade — a deliberate decision to skip the Statamic dependency.

- **Allowlisted block renderer.** `MarketingPage.blocks` (JSONB) maps each
  block type to a Blade partial via a **fixed allowlist** — never an
  interpolated include path. Static blocks (hero, heading, paragraph, image,
  CTA, FAQ, quote, …) plus **live-data blocks** (class catalog, upcoming
  events, teacher roster, membership/passes pricing, campaign progress) that
  are cached 5 minutes and privacy-safe at the query layer (publicly-bookable
  rows only, instructor first names, seat counts not rosters).
- **Page tree** with slugs, cycle-guarded path resolution, scheduled publishing
  (go-live/expire evaluated at request time), and per-block visibility windows.
- **SEO**: JSON-LD (LocalBusiness + Event, `</script>`-breakout-proofed), SEO
  columns, per-tenant sitemap.
- **Authoring suite**: page templates + a first-run setup wizard, site chrome
  (nav/announcement/footer singleton), site modes (PUBLIC / PORTAL_ONLY /
  COMING_SOON), reusable content snippets (incl. ordered media galleries), an
  SSRF-guarded single-page site importer, live-data block preview iframes in
  the editor, and a weekly site-health digest (stale pages, empty live blocks,
  broken external links). The importer, the live preview and the outbound
  link check are currently **darkened** behind a platform switch; the block
  builder and templates stay live.
- **Media library**: `MediaService` is the single upload entry point; native-GD
  `ImageOptimizer` produces WebP/AVIF variants + EXIF strip, degrading to
  original-only on any gap. `<x-media-picture>` emits the source-set.
  Config-gated AI alt-text and AI copy assistance are inert by default, need
  the AI add-on on the studio's plan, have a per-studio monthly token cap,
  and never overwrite human input.
- **Pageview analytics**: cookie-free beacon (`POST /studio/{tenant}/_pa/beacon`,
  DNT-honoring, daily-rotating visitor-hash salt so there is no cross-day
  tracking), raw events → nightly rollup (the only read path) → pruning per
  retention setting; staff dashboard with CSV export. The whole stack is
  darkened by default and, when relit, needs the analytics add-on.
- **Lead capture** fires the tenant email engine (autoresponder + staff
  notification) inside `$tenant->run()`.

## The theme system

A token-driven theming layer over every tenant-facing surface.

- **Tokens + component vocabulary**: `resources/css/tokens.css` defines the
  named palette slots (13 palette slots + semantic roles), typography, and
  radius; `components.css` carries a fixed unlayered component vocabulary;
  Tailwind utilities are **var-backed**, so utilities never change per tenant —
  only the variables they resolve to.
- **Catalog**: five code-defined themes (`PotteryStudio` default, `Porcelain`,
  `Stoneware`, `SageGarden`, `StudioDark`) registered in a singleton
  `ThemeCatalog`. **All themes fill the same named slots**, so every swept view
  renders under any theme.
- **Resolution**: `ThemeResolver::resolve()` layers catalog base ← legacy
  `brand_*` columns folded into slots ← sanitized `theme_overrides` JSONB, and
  emits CSS variables per surface. Override values are sanitized against CSS
  injection (no `;{}<>:`, length caps).
- **Application point**: `<x-theme-styles surface="…">` emits the `:root`
  block into the portal/kiosk/POS layouts, the Filament admin (via an additive
  render hook — deliberately not a full `viteTheme()` stylesheet fork), the
  public page, and (opt-in) email. Five per-surface `apply_theme_to_*` flags.
- **Safety**: the picker gates saves on **WCAG-AA contrast** (button text at
  AA-large, body text at AA-normal) via `WcagContrast`; a live `srcdoc`
  preview iframe tracks unsaved form state in isolation.
- **Exclusions**: the platform operator console (not a studio surface) and the
  BCP offline PWA (self-contained by design) are outside theme scope.

## The staff admin

The Filament admin was redesigned to the "kiln ledger" prototype: a charcoal
top bar with six folder tabs (Today, Programs, People, Finance, Funding,
Settings), a terracotta sidebar and a cream paper surface, all bound to the
same theme tokens so a theme swap retints it. The styling is additive (a
render-hook stylesheet plus scoped per-screen classes), never a fork of
Filament's compiled CSS, so a stale selector does nothing rather than
breaking a screen.

The six section panels it was first built on were later **consolidated into
one panel**. The sidebar is scoped to the section you're in and stays
collapsed unless something needs attention (a warning count on the section,
or the group containing the current page). Filament remembers collapsed
groups in the browser forever, so the server rewrites that stored list on
every page load; `->collapsed()` alone would do nothing for anyone who had
visited before. Old per-panel URLs 301 to their new home.

Each section's resource list doubles as its permission area for staff roles,
so the sidebar layout and who-can-edit-what can't drift apart.

## Website embeds (Squarespace et al.)

- **`EmbedSetting`** (per-tenant singleton) drives the `embed.headers`
  middleware: enabled → `Content-Security-Policy: frame-ancestors` with
  sanitized parent domains; disabled → framing forbidden (`DENY`). Copy-paste
  iframe snippets in the admin.
- **Public calendar embeds**: anonymous, throttled
  `/embed/calendar/{classes,events,lessons,parties,all}` render chrome-less
  fragments (or JSON) from privacy-safe `CalendarItem` projections, with a
  parent-page auto-resizer script.
- **Portal embed**: the real member portal under `/portal/embed/*`, reusing
  the exact Livewire components with chrome-reduced layout and in-frame login.
  Cross-origin (no-CNAME) mode uses SameSite=None cookies plus an
  **embed-aware CSRF subclass** that additionally trusts allowed parent
  origins — strictly additive, fails closed, never weakens non-embed CSRF.
