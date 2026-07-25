---
kind: code
depends_on: []
chain:
  - nldesign-theme-selection
---

## Why

PR #20 reverted `app-theming` (PR #15 + archive PR #16) — a per-app custom-theming feature
that duplicated nldesign's WCAG math and shipped its own hard-blocking contrast gate. The
revert commit (`f8b6612a2`) says: "App-specific theming belongs to nldesign (NlDesignTheme).
Removed entirely." `nldesign-theme-selection` (archived 2026-06-15) — the OTHER, KEPT
theming capability that lets a builder pick an nldesign token set per app — survived the
revert untouched, but its own spec already documents the gap it shipped with:

> REQ-NTS-002: "(a) admin `GET /apps/nldesign/settings/tokensets` ... (b) the flagged
> non-admin nldesign list endpoint **once it exists** ... (c) a validated free-text
> fallback." / REQ-NTS-006: "A Codeberg issue against `Conduction/nldesign` MUST be filed
> requesting a `#[NoAdminRequired]` read-only token-set list endpoint."

That endpoint now exists. nldesign's `app-token-set-selection` change (in progress, not yet
merged) ships exactly the two things OpenBuild's own spec asked for — `GET /api/token-sets`
(non-admin) and `POST /api/contrast/evaluate` (shared WCAG math) — plus a **published**
scoped-application contract. A companion `nextcloud-vue` change,
`scoped-theme-applier` (in progress, not yet merged), implements that contract as a shared
`useScopedTheme()` composable and wires `CnAppRoot` to self-apply `manifest.runtime.theme`
on its own root element (`data-nldesign-theme-scope="<appId>"`) with zero per-app code.

OpenBuild today still carries three local, now-redundant pieces built before either of
those existed: `src/composables/useAppTheme.js` (a hand-rolled `:root`-rewriter scoped to
`data-openbuild-theme-scope`, duplicating what `useScopedTheme` now does), a feature-probe
stub in `src/dialogs/ThemePickerDialog.vue` (lines 1-19: "always falls through to the
free-text fallback because the non-admin endpoint does not exist" — no longer true once
`app-token-set-selection` ships), and `src/services/manifestValidation/theme.js` (strict
shape checks the canonical `app-manifest-v2.schema.json` will validate natively once
`scoped-theme-applier`'s schema bump — 2.19.0 → 2.20.0 — ships). This change re-points the
KEPT `nldesign-theme-selection` capability at those now-existing owning primitives and
deletes OpenBuild's local duplicates. It is a refactor of a capability that already works,
not a new feature.

## What Changes

- **DELETE** `src/composables/useAppTheme.js` in full — its `:root`-rewrite, cache, and
  managed-`<style>` injection are now `useScopedTheme()`'s job
  (`@conduction/nextcloud-vue`, `scoped-theme-applier` change).
- **MODIFY** `src/views/BuilderHost.vue` and `src/views/PageDesignerHost.vue` — remove the
  `data-openbuild-theme-scope` attribute and every `useAppTheme()`/`appTheme.apply()`/
  `appTheme.teardown()` call. `CnAppRoot` (already mounted inside `BuilderHost.vue`) now
  carries `data-nldesign-theme-scope="<appId>"` itself and self-applies
  `manifest.runtime.theme` per `scoped-theme-applier` REQ-STA-3 — no host-level wiring
  needed.
- **MODIFY** `src/dialogs/ThemePickerDialog.vue` — collapse the three-tier fallback
  (admin list → feature-probed non-admin list → validated free-text) to a single direct
  call to `useScopedTheme().listTokenSets()` (which itself wraps nldesign's
  `GET /api/token-sets`), with nldesign-absent graceful degradation (existing REQ-NTS-005
  pattern: disabled with a hint) as the only remaining fallback state. The free-text input
  and the admin-only `settings/tokensets`/`settings/tokenset-preview` calls are removed.
  Live preview retargets the page-designer live-preview-pane's sandboxed `CnAppRoot`
  instance (mutating its in-flight manifest so `CnAppRoot`'s own REQ-STA-3 watcher
  re-applies) instead of `PageDesignerHost.vue`'s separate manual apply/teardown.
- **NEW (consumption)** warn-only contrast preview in the dialog via
  `useScopedTheme().evaluateContrast(candidates, background)` — diagnostic display only,
  never a save-blocking gate (matches nldesign's own non-blocking selection policy,
  `app-token-set-selection` "Selection Contrast Is Non-Blocking"). OpenBuild adds no local
  contrast math — `checkThemeContrast.js` (the `app-theming` duplicate) is already deleted
  by the PR #20 revert and stays deleted.
- **DELETE** `src/services/manifestValidation/theme.js` and its `validateTheme` import +
  `.concat(validateTheme(manifest))` call in `src/composables/useManifestValidator.js` —
  once `@conduction/nextcloud-vue` ships the `runtime.theme` schema field
  (`scoped-theme-applier` REQ-STA-4, `$defs/runtimeTheme`, schema `version` 2.20.0), the
  library's own `validateManifest()` call (already the first thing
  `useManifestValidator.js` runs) reports every check OpenBuild's local module duplicated:
  unknown `source`, non-kebab-case `tokenSet`, unknown keys.
- **DEPENDENCY** — bump `@conduction/nextcloud-vue` in `package.json` (currently
  `^1.0.0-beta.219`) to the first published version carrying `useScopedTheme`, the
  `CnAppRoot` wiring, and schema 2.20.0. Blocks every task below (see design.md Decision
  1 / tasks.md task 0).
- **NO** OpenBuild-owned scoped-CSS applier, **NO** OpenBuild-owned WCAG math, **NO**
  per-app custom-color authoring (that was `app-theming`; reverted; any future custom-token
  authoring is an nldesign `custom-token-sets` feature, out of scope here).

### Capabilities

#### New Capabilities

_None._

#### Modified Capabilities

- `nldesign-theme-selection`: the runtime applier, the picker's catalogue/preview source,
  and contrast display all re-point from OpenBuild-local implementations to
  `@conduction/nextcloud-vue`'s `useScopedTheme` + nldesign's `GET /api/token-sets` /
  `POST /api/contrast/evaluate`. The manifest `runtime.theme` shape (REQ-NTS-001) is
  unchanged; its validation source moves from app-local to library-canonical.

## Impact

- **Deleted OpenBuild files**: `src/composables/useAppTheme.js`,
  `src/services/manifestValidation/theme.js`, and their Vitest suites
  (`tests/composables/useAppTheme.spec.js` if present,
  `tests/services/themeValidation.spec.js` if present — confirm exact test paths during
  apply).
- **Modified OpenBuild files**: `src/dialogs/ThemePickerDialog.vue`,
  `src/views/BuilderHost.vue`, `src/views/PageDesignerHost.vue`,
  `src/composables/useManifestValidator.js`, `package.json`.
- **External dependencies (not yet merged, flagged not assumed)**:
  1. `nldesign/openspec/changes/app-token-set-selection` — `GET /api/token-sets`,
     `POST /api/contrast/evaluate`, and the published scoped-application contract.
  2. `nextcloud-vue/openspec/changes/scoped-theme-applier` — `useScopedTheme()`,
     `CnAppRoot` self-application, `app-manifest-v2.schema.json` 2.20.0.
  This change cannot be applied until BOTH ship and OpenBuild's `package.json` pin is
  bumped past the version that includes them (task 0 blocks all others).
- **ADR-022 note**: this is exactly the "apps consume OR/design-system abstractions, not
  local duplication" principle — `nldesign-theme-selection`'s own `app-token-set-selection`
  dependency (proposal, "The gap is not hypothetical") already cites OpenBuild's local
  duplicates (`useAppTheme.js`'s rewriter, `checkThemeContrast.js`'s WCAG math) as the
  evidence for why the shared surface needed to exist. This change closes the loop those
  primitives were built to enable.
- **No breaking changes** to the manifest shape or to apps that have never set a theme —
  `runtime.theme`'s wire format is unchanged; only its implementation source moves.

## Open Questions

- **OQ-1**: Exact current call sites for `PageDesignerHost.vue`'s `onThemePreview()` /
  `appTheme.apply()` / `appTheme.teardown()` (lines ~189, ~239-242, ~377-383, ~394-400)
  need re-tracing against the page-designer-live-preview-pane change's sandboxed
  `CnAppRoot` mount during apply, to confirm the live-preview retarget (this proposal's
  "What Changes" bullet 3) fully supersedes the host-level wiring rather than leaving a
  gap when the live-preview pane is unavailable (`previewAvailable === false`,
  `page-designer-live-preview-pane` REQ-OBPD-008). If a gap exists, task 3.3 keeps a
  minimal PageDesignerHost-level fallback rather than silently dropping live preview.
- **OQ-2**: `useScopedTheme()`'s actual exported shape (confirmed by reading
  `scoped-theme-applier`'s spec directly: `{ apply, teardown, listTokenSets,
  evaluateContrast }`) does **not** include a standalone `fetchTokenCss` — `apply()` does
  the fetch internally. Any task or design text elsewhere describing a separate
  `fetchTokenCss` export is corrected by this spec; there is no such export to consume.
