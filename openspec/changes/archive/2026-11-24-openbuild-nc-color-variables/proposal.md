---
kind: code
---

## Why

ADR-004 (frontend) and ADR-010 (NL Design) require every color in app CSS to come from Nextcloud CSS variables so government theming (`nldesign`) and dark mode work without forks. Buildiq mostly complies (the same files fall back correctly elsewhere, e.g. `var(--color-primary-element-text, #fff)` at `src/views/VersionHistory.vue:484`), but a badge-styling cluster ships **hardcoded text colors on translucent hardcoded backgrounds**, which is unreadable in dark mode and invisible to NL Design theming:

- `src/components/applicationDetail/ApplicationDetailHeader.vue:555-577` — four badge variants: `color: #2e5ed9` on `rgba(67,118,252,.15)` (status), `color: #555` on `rgba(120,120,120,.15)` (role), `color: #246b3d` on `rgba(46,184,102,.15)` (semver), `color: #444` on `rgba(120,120,120,.18)` (type-hybrid). In dark theme `#555`/`#444` text on a near-transparent dark background fails WCAG AA outright.
- `src/components/applicationDetail/widgets/GroupsWidget.vue:175-187` — the same pattern duplicated for role chips: `#a06900` (owners), `#2e5ed9` (editors), `#555` (viewers), each on a hardcoded `rgba()` wash.
- `src/views/VersionHistory.vue:479` — `color: #fff` on `var(--color-success, #2d7d46)`; the sibling production badge two rules below already does it right with `var(--color-primary-element-text, #fff)`.
- `src/dialogs/IconUploadSection.vue:428` — `color: #fff` on `var(--color-primary-element, #0082c9)`; same fix.

**Explicitly exempt (intentional, must NOT be "fixed"):** the icon-preview canvases that simulate a fixed light/dark Nextcloud background regardless of the active theme — `src/dialogs/IconUploadSection.vue:399` (`#ffffff`) / `:404` (`#1c1c1e`) and `src/dialogs/CreateApplicationWizard/Step4Review.vue:228` (`#1a1a2e`) / `:243` (`#aaa` caption inside that simulated dark canvas). These deliberately do not track the theme; they get a `/* intentional: simulated light/dark canvas for icon preview */` comment so lint sweeps skip them.

## What Changes

- **Replace the four `ApplicationDetailHeader.vue` badge variants** with NC variables: status → `var(--color-primary-element-light)` background + `var(--color-primary-element)` text; role and type-hybrid → `var(--color-background-dark)` background + `var(--color-text-maxcontrast)` text; semver → `var(--color-success-hover)` background + `var(--color-success-text)` text.
- **Replace the three `GroupsWidget.vue` role chips** with the same system: owners → `var(--color-warning-hover)` / `var(--color-warning-text)`; editors → `var(--color-primary-element-light)` / `var(--color-primary-element)`; viewers → `var(--color-background-dark)` / `var(--color-text-maxcontrast)`.
- **Fix the two text-on-brand cases**: `VersionHistory.vue:479` → `var(--color-success-text, #fff)`; `IconUploadSection.vue:428` → `var(--color-primary-element-text, #fff)`.
- **Annotate the exempt icon-preview swatches** with the intentional-comment so the exemption is self-documenting.
- **No BREAKING changes.** Pure CSS substitution; light-theme rendering stays visually equivalent (the chosen variables resolve to near-identical light values), dark theme becomes correct instead of broken.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `frontend-foundation`: adds a requirement that all component styling consumes Nextcloud CSS variables for color, with the single documented icon-preview exemption.

## Impact

- 4 Vue files touched, CSS only (`ApplicationDetailHeader.vue`, `GroupsWidget.vue`, `VersionHistory.vue`, `IconUploadSection.vue`); 2 files gain exemption comments (`IconUploadSection.vue`, `Step4Review.vue`).
- No JS, no PHP, no schema, no routes. Stylelint should pass unchanged; visual review in both themes required.
