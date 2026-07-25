## Context

`nldesign-theme-selection` (archived 2026-06-15) shipped honestly against a real gap: at
the time, nldesign's only token-set discovery endpoints were `#[AuthorizedAdminSetting]`
(admin-only), so OpenBuild built a three-tier picker fallback (admin list → feature-probed
non-admin list that never activated → validated free-text input) and its OWN scoped-CSS
applier (`useAppTheme.js`) to keep the runtime path working without any nldesign code
change. Its own spec (REQ-NTS-002, REQ-NTS-006) documented this as a stopgap and filed the
exact dependency that would let it collapse: a non-admin catalogue endpoint on nldesign's
side.

That endpoint — plus a shared contrast-evaluation endpoint and a *published* (not just
reverse-engineered) scoped-application contract — is now being built in
`nldesign/openspec/changes/app-token-set-selection`. A companion
`nextcloud-vue/openspec/changes/scoped-theme-applier` implements a fleet-wide
`useScopedTheme()` composable against that contract and wires `CnAppRoot` to self-apply
`manifest.runtime.theme`, so every `CnAppRoot`-hosted app gets scoped theming with zero
per-app applier code. OpenBuild's `nldesign-theme-selection` was, by its own design.md
Decision 2's "alternatives considered", always meant to converge on exactly this once
nldesign published the contract rather than OpenBuild reverse-engineering it. This change
is that convergence.

## Goals / Non-Goals

**Goals:**

- Delete `useAppTheme.js` and `manifestValidation/theme.js` — the two OpenBuild-owned
  duplicates of what nc-vue/nldesign now own.
- Re-point `ThemePickerDialog.vue` at the real non-admin catalogue endpoint, collapsing the
  three-tier fallback to one direct call + one graceful-absence state.
- Add warn-only contrast display without adding any contrast math.
- Zero change to the manifest wire format (`runtime.theme` shape is unchanged) — this is
  an implementation-source refactor, not a schema change.

**Non-Goals:**

- No scoped-CSS applier of any kind remains in OpenBuild — `useScopedTheme` is the only
  applier, full stop.
- No contrast math in OpenBuild — `checkThemeContrast.js` (the `app-theming` duplicate) is
  already deleted by the PR #20 revert; this change's job is to confirm nothing re-adds
  it, not to write a replacement.
- No per-app custom-color/token authoring — that is `app-theming`'s territory (reverted)
  or, if ever wanted, a new nldesign `custom-token-sets` feature. Out of scope here.
- No admin-only fallback path retained "just in case" — once the non-admin endpoint is a
  real, published contract, keeping the admin-only leg around is exactly the kind of
  dead/duplicate code this change exists to remove.

## Decisions

### Decision 1 — This change is gated on two external repos merging first; task 0 blocks everything else

Neither `app-token-set-selection` (nldesign) nor `scoped-theme-applier` (nextcloud-vue) has
merged as of this proposal. Every task in this change assumes both have shipped and
`package.json`'s `@conduction/nextcloud-vue` pin has been bumped to a version that includes
`useScopedTheme`, `CnAppRoot`'s self-application, and the 2.20.0 schema. Task 0 is a hard
prerequisite gate (mirrors `app-delta-override` task 0's "Confirm ... BLOCK all tasks below
until confirmed" pattern) — it is not optional groundwork, it is the thing that makes every
other task's premise true.

**Rationale**: writing `useAppTheme.js`'s deletion as task 1.1 with no gate would let an
apply run delete the ONLY working applier OpenBuild has today before the replacement
exists, breaking every themed app on the instance. The gate makes the dependency explicit
and checkable rather than assumed.

**Alternatives considered**: *Ship this change piecemeal — delete only what's provably safe
without the bump* — rejected: `useAppTheme.js` and its host-level wiring are the ONLY
runtime theme applier that exists until `CnAppRoot` self-applies; there is no safe partial
deletion. The change is all-or-nothing by construction, matching its own chain declaration
(`chain: [nldesign-theme-selection]`).

### Decision 2 — `ThemePickerDialog.vue`'s three-tier fallback collapses to one call + one absence state

Old: (a) admin `GET /apps/nldesign/settings/tokensets` (403-tolerant) → (b) feature-probed
non-admin endpoint (never activated in practice — the endpoint didn't exist) → (c)
validated free-text `css/tokens/<id>.css` fetch. New: `useScopedTheme().listTokenSets()`
(wrapping the now-real `GET /api/token-sets`) → nldesign-absent/unreachable degrades to the
existing REQ-NTS-005 "disabled with hint" state (unchanged from today). The free-text
input, the admin-list call, and the feature-probe are all removed — they existed
specifically to route around the absence of a real non-admin endpoint, and that absence is
what this change resolves.

**Rationale**: `listTokenSets()` (per `scoped-theme-applier` REQ-STA-2) already resolves to
`[]` on ANY failure (missing app, network error, non-2xx, malformed body) rather than
throwing — so "endpoint returns nothing usable" and "nldesign not installed" collapse into
the SAME UI state OpenBuild already has to handle (REQ-NTS-005's disabled-with-hint). No
new failure-mode UI is needed; only the now-redundant intermediate legs are removed.

**Alternatives considered**: *Keep the free-text fallback as a permanent power-user
escape hatch* — rejected: it was a workaround for a missing catalogue, not a feature
request in its own right; keeping it duplicates validation logic (the 404-on-asset check)
against a real, listable catalogue for no user benefit.

### Decision 3 — Live preview retargets the sandboxed `CnAppRoot`, not a separate host-level applier

Today, `ThemePickerDialog`'s live-preview toggle calls `PageDesignerHost.vue`'s
`onThemePreview()`, which directly invokes `useAppTheme().apply()`/`.teardown()` against the
HOST route's own `data-openbuild-theme-scope` wrapper (see `PageDesignerHost.vue:377-400`).
That mechanism disappears with `useAppTheme.js`. Going forward, the dialog's preview toggle
mutates the in-flight (unsaved) manifest object already bound to the page-designer
live-preview-pane's sandboxed `CnAppRoot` instance (`page-designer-live-preview-pane`
change) — that `CnAppRoot` instance self-applies theme changes automatically per
`scoped-theme-applier` REQ-STA-3 ("MUST re-apply when the effective manifest's
`runtime.theme` changes (e.g. an in-app theme-picker edit)"). One less bespoke code path;
the SAME preview surface that already shows layout changes now also shows theme changes.

**Rationale**: building a second, OpenBuild-specific "preview applier" when `CnAppRoot`
already watches for exactly this kind of live edit would be exactly the anti-pattern this
change is closing out. See design.md's Open Question OQ-1 (proposal.md) for the one
un-resolved edge case: what happens when the live-preview pane itself is unavailable
(`previewAvailable === false`).

### Decision 4 — Contrast display is warn-only, sourced from `evaluateContrast()`, never a save gate

The dialog calls `useScopedTheme().evaluateContrast(candidates, background)` to show
WCAG ratio/level/pass facts next to a candidate token set, purely informational. Save is
never blocked by the result.

**Rationale**: matches nldesign's own policy (`app-token-set-selection` "Selection
Contrast Is Non-Blocking" — evaluating a catalogue SELECTION is warn-only, consistent with
nldesign's upload-time policy) and explicitly avoids resurrecting `app-theming`'s reverted
hard-block behaviour, which the revert commit called out by name as the wrong shape. OQ-2
(proposal.md) corrects a naming mismatch: the consumed API is `evaluateContrast`, not a
separate `fetchTokenCss`-shaped call.

### Decision 5 — `manifestValidation/theme.js` is deleted outright, not reduced to a pass-through

Once `@conduction/nextcloud-vue`'s `validateManifest()` (already the first call
`useManifestValidator.js` makes, before any app-side checks run) validates `runtime.theme`
against the canonical `$defs/runtimeTheme` schema (source enum, kebab-case `tokenSet`,
unknown-key rejection — confirmed against `scoped-theme-applier` REQ-STA-4's own
scenarios), every check `validateTheme()` performs today is a byte-for-byte duplicate
running a second time. A "thin pass-through" that just re-exports the library's errors
would be dead code with extra indirection; deleting the file and its
`useManifestValidator.js` wiring (`import { validateTheme } ...` +
`.concat(validateTheme(manifest))`) is the honest outcome.

**Rationale**: matches the same logic already applied to `useAppTheme.js` — once an owning
primitive is real and verified, the local duplicate has no remaining job.

**Alternatives considered**: *Keep `theme.js` as a defensive second check* — rejected: the
whole point of REQ-STA-4 shipping is that OpenBuild no longer needs to independently
maintain theme-shape validation; a "just in case" duplicate re-creates exactly the drift
risk ADR-022 warns about (the two validators silently diverging over time).

## Risks / Trade-offs

- **Hard external dependency**: this change is fully blocked until two other repos ship.
  Mitigated: task 0 makes the gate explicit and checkable rather than a silent assumption;
  no task attempts a workaround.
- **Live-preview gap during the transition** (OQ-1): if the sandboxed `CnAppRoot` preview
  pane is unavailable in some designer state, live theme preview could regress until
  confirmed/fixed. Mitigated: task 3.3 keeps a minimal fallback if apply-time investigation
  shows a gap; not silently dropped.
- **`useScopedTheme` API surface assumption**: this spec cites `{ apply, teardown,
  listTokenSets, evaluateContrast }` as read directly from `scoped-theme-applier`'s own
  spec at the time of writing; if that spec changes before it ships, tasks referencing
  exact function names need re-verification against the shipped package, not this
  document, at apply time.

## Migration Plan

1. Confirm both external dependencies have shipped and bump `package.json` (task 0).
2. Delete `useAppTheme.js` + its tests; remove host-level wiring in `BuilderHost.vue` /
   `PageDesignerHost.vue` (task 1).
3. Re-point `ThemePickerDialog.vue` at `useScopedTheme()` (task 2).
4. Delete `manifestValidation/theme.js` + its `useManifestValidator.js` wiring (task 4).
5. Regression: an app with an existing `runtime.theme` (saved under the OLD
   implementation) renders identically under the new applier — the manifest shape never
   changed, only which code applies it. No data migration.

Rollback: revert the commit; the deleted files return, `package.json` pin reverts. No
persisted-data rollback needed (manifest shape unchanged throughout).

## Open Questions

See proposal.md's Open Questions (OQ-1/OQ-2) — carried here for traceability, not
duplicated.
