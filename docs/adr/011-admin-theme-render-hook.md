# ADR 011 — Admin theming via render hook, not Filament `viteTheme()`

**Status:** Accepted

## Context

Deep-theming the Filament admin (cream surface, serif headings) has two
routes: `->viteTheme()` — replacing Filament's **entire** compiled stylesheet
(all-or-nothing, high blast radius, and not browser-validatable in the remote
build environment) — or an additive style injection.

## Decision

Inject `<x-theme-styles surface="admin">` plus a conservative chrome rebind via
a `PanelsRenderHook::STYLES_AFTER` render hook, gated on the tenant's
`apply_theme_to_admin` flag.

## Consequences

- Additive and safe: a stale selector no-ops instead of breaking the admin,
  and any studio whose admin looks wrong flips the toggle — instant escape
  hatch, no rebuild.
- Depth is bounded — we restyle surfaces and headings, not every Filament
  component. A real `viteTheme()` fork remains an option once it can be
  browser-validated.
- The same per-surface flag pattern governs portal/kiosk/public/email theming,
  so the admin isn't a special case.
