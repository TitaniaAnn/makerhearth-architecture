# ADR 004 — Filament/Blade public site, not Statamic

**Status:** Accepted (supersedes the original Statamic plan)

## Context

The spec's original stack named Statamic 5 (the Wagtail equivalent) for the
per-tenant public marketing site. That meant a second CMS dependency, its own
multi-tenancy story, and a parallel authoring stack — for sites that are
structurally simple (a page tree of content blocks + live studio data).

## Decision

Build the public site on the **existing stack**: a JSONB block model
(`MarketingPage.blocks`) rendered by allowlisted Blade partials, authored in a
Filament block-builder page, served from central routes inside
`$tenant->run()`.

## Consequences

- No second CMS to install, tenant-scope, upgrade, or theme; live-data blocks
  reuse the domain services directly.
- Editor UX is Filament-form-shaped rather than a WYSIWYG page canvas — traded
  deliberately; templates, previews, and the setup wizard close most of the
  gap.
- The block partial allowlist becomes a security invariant (no interpolated
  include paths).
