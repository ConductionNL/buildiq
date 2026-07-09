---
kind: code
---

## Why

`openspec/specs/openbuild-page-designer/spec.md:263-278` (REQ-OBPD-008)
requires: "The Page Designer SHALL provide an optional right-hand pane
that mounts a **sandboxed** `CnAppRoot` instance configured from the
in-flight (unsaved) manifest... The pane SHALL be considered available
**only when** the in-memory `useAppManifest(appId, manifestObject)`
overload from chain spec #2 (`nextcloud-vue-in-memory-manifest`) is
detected at runtime." That chain-spec-2 overload has now shipped: the
installed `node_modules/@conduction/nextcloud-vue/src/composables/useAppManifest.js:107`
exports `useAppManifest(appIdOrOptions, bundledManifest, options = {})`
— a 2-required-parameter signature whose `.length` is `2` (the
`options` param has a default, so it doesn't count toward `Function.length`).

OpenBuild's own feature-detector,
`src/composables/useLivePreview.js:27-29`, computes `available =
fnArity >= 2` from exactly that `.length` check — so `available` now
resolves to `true` in the running app. But `src/views/PageDesigner.vue`
never implements the "available" branch REQ-OBPD-008 requires:

- `src/views/PageDesigner.vue:74` guards the fallback message with
  `v-if="!previewAvailable"` — correct — but there is no matching
  `v-else` (or separate conditional block) that mounts the sandboxed
  `CnAppRoot` preview using `previewProps` when `previewAvailable` is
  true.
- `src/views/PageDesigner.vue:192,195` destructures and returns
  `previewProps` from `useLivePreview()` in `setup()`, but `previewProps`
  is never referenced anywhere in the `<template>` (confirmed: `grep -n
  "previewProps" src/views/PageDesigner.vue` matches only the two `setup()`
  lines) — it is computed and then discarded.
- `src/views/PageDesigner.vue:73` still carries the stale comment
  `<!-- TODO(chain-spec-2): live preview pane requires in-memory
  useAppManifest -->`, written when the overload did not yet exist.

The net effect, verified against the current code: the right-hand pane
of the Page Designer today renders **nothing** for any user on the
currently-installed library version — not the fallback message (correctly
suppressed because `previewAvailable` is true) and not a live preview
(never built). This regresses the pre-chain-spec-2 UX (which at least
showed the "Save & open preview" fallback) and leaves REQ-OBPD-008 and its
two Playwright-covered-by-reference scenarios ("Preview pane renders the
in-flight manifest" / "Fallback when in-memory loader is unavailable")
unimplemented for the now-active branch.

## What Changes

- Implement the `v-else` branch in `PageDesigner.vue`'s right-hand pane:
  when `previewAvailable` is true, mount a sandboxed `CnAppRoot` using the
  props from `previewProps(slug, manifest)` (`appId:
  openbuild-preview-{slug}`, the in-flight manifest object, and a `:key`
  bound to the manifest content hash per REQ-OBPD-008's re-mount contract).
- Keep the existing fallback branch (`v-if="!previewAvailable"`) as the
  degraded path for any environment where the library predates the
  overload (matches REQ-OBPD-008's "Fallback when in-memory loader is
  unavailable" scenario).
- Remove the stale `TODO(chain-spec-2)` comment once the pane is
  implemented (or update it to point at any genuinely remaining
  follow-up, e.g. debounce/throttle tuning for rapid edits).
- Wire the sandboxed `CnAppRoot`'s `registry`/`pageTypes` props from the
  same values `App.vue` passes to the production `CnAppRoot` mount, so
  custom-page components resolve identically in preview (this also
  closes the `CustomPageEditor.vue` dependency noted in REQ-OBPD-007 —
  "the keys of the `customComponents` registry ... passed to the
  sandboxed `CnAppRoot` mounted by the live-preview pane" — which today
  has no live registry to read because the pane doesn't exist).
- No BREAKING changes — purely additive UI; the manifest PUT/save flow is
  untouched (REQ-OBPD-008 explicitly forbids the preview from sending any
  PUT to OR).

## Capabilities

### Modified Capabilities

- `openbuild-page-designer`: REQ-OBPD-008 (live-preview pane) becomes
  implemented rather than deferred; REQ-OBPD-007's registry-backed
  component picker gains a live source of `customComponents` keys (delta
  spec at `specs/openbuild-page-designer/spec.md`).

## Impact

- Files touched: `src/views/PageDesigner.vue` (template + script); no
  changes needed to `src/composables/useLivePreview.js` (already correct)
  or any backend/route/schema.
- Removes a silent UX regression (blank pane) present today on the
  currently-installed `@conduction/nextcloud-vue` version.
