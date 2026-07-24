## 1. Manifest

- [x] 1.1 Add `runtime.appTheme` to the manifest validation layer (logoRef, primaryColor, secondaryColor, accentColor, headerStyle enum); reject unknown headerStyle/non-hex colors/unknown keys.
- [x] 1.2 Regression test: a themeless app's manifest serializes byte-identically to the pre-feature baseline.

## 2. Contrast guardrail

- [x] 2.1 `checkThemeContrast.js` — pure WCAG relative-luminance + contrast-ratio function (4.5:1 text, 3:1 UI element); unit tests against known-good/known-bad pairs.

## 3. Theme editor UI

- [x] 3.1 Theme section — color pickers (native `<input type=color>` + a labeled `NcTextField` hex value each), logo picker defaulting to `icon`/`iconDark` with opt-in dedicated upload, `NcSelect` header-style select (`inputLabel`), live preview swatch strip. Deviation: implemented as `AppCustomThemeSection.vue`, a sibling of the existing `ThemeSection.vue` wired into `PageDesignerHost.vue` — the codebase's own established reuse pattern for `runtime.*` theme editing (manifest-driven, controlled-component sections) rather than `AppSettingsModal.vue`, which design.md only guessed at and which has no manifest access. See PR description.
- [x] 3.2 Save gated by `checkThemeContrast`; inline per-pair failure explanation (pair, computed ratio, required threshold) both in the section itself and re-checked as the hard block at the actual persist boundary (`PageDesignerHost.save()`); no bypass.

## 4. Applier

- [x] 4.1 `useAppCustomTheme` composable — maps appTheme colors onto the pinned `--color-*` variable-name list (with an `--nldesign-color-*` fallback chain — see deviation note below) plus app-scoped `--ob-theme-secondary`/`--ob-theme-accent`, scoped to `[data-openbuild-theme-scope]`, injected as a managed `<style data-openbuild-app-theme>` element, torn down on app leave.
- [x] 4.2 Injection-order coordination: appTheme style injected before the existing nldesign-theme-selection style when both are active.
  - acceptance: shared integration test asserts nldesign wins for any shared variable name — `tests/composables/useAppCustomTheme.spec.js` "nldesign precedence" suite. Deviation: the real fetched nldesign token CSS (`nldesign/css/tokens/*.css`) sets ONLY `--nldesign-*`-prefixed names, never `--color-*` — nldesign's scoped applier (`useAppTheme.js`) therefore never sets `--color-primary` in-scope, so pure DOM injection order alone cannot make "nldesign wins" true (no shared property name to cascade on). The applier instead sets `--color-primary: var(--nldesign-color-primary, <appThemeColor>)` — a CSS `var()` fallback chain against the REAL nldesign variable name — which is what genuinely implements precedence here. Injection order is still followed (belt-and-braces / future-proofing) but is not the load-bearing mechanism. See design.md Open Question + PR description for the full rationale.
- [x] 4.3 Header-style + logo consumption in the runtime chrome (`branded`/`compact`/`default`), applying regardless of an active nldesign theme. Deviation: `CnAppRoot` (installed `@conduction/nextcloud-vue`) exposes no dedicated top-bar logo/branding slot — implemented as an OpenBuild-side `AppBrandedHeader.vue` rendered above the nested `CnAppRoot` inside `BuilderHost.vue`'s existing `[data-openbuild-theme-scope]` wrapper. See PR description.

## 5. Verification against existing guarantees

- [x] 5.1 Confirm version snapshot/promotion/export carry `runtime.appTheme` losslessly via the existing manifest-carrying machinery (no new plumbing expected — verification only). Verified: `runtime.appTheme` is a plain JSON property under the manifest's `runtime` block (`additionalProperties: true` in `app-manifest-v2.schema.json`), persisted via the SAME `manifest` field PATCH/PUT `PageDesignerHost.save()` already uses for `runtime.theme` and every other manifest block — no new plumbing, same guarantee `nldesign-theme-selection` REQ-NTS-004 established.

## 6. Tests

- [x] 6.1 Vitest: manifest validation (`appThemeValidation.spec.js`), contrast function (`checkThemeContrast.spec.js`), applier variable mapping + teardown + nldesign-precedence integration (`useAppCustomTheme.spec.js`), editor UI (`AppCustomThemeSection.spec.js`), branded header + logo resolution (`AppBrandedHeader.spec.js`), BuilderHost/PageDesignerHost wiring (`BuilderHost.spec.js`, additions to `PageDesignerHost.spec.js`).
- [x] 6.2 Playwright: `tests/e2e/spec-coverage/app-theming.spec.ts` — scenarios tagged `@e2e app-theming::*`, `test.skip` with the same Conduction/openbuild#41 quarantine reason the sibling `nldesign-theme-selection.spec.ts` already uses (builder/page-designer UI not reachable in this build); logic coverage delegated to the vitest suites above, matching the established precedent.

## 7. Verify

- [x] 7.1 `vitest run` (1338/1338 passing, up from the 1268 baseline), `phpunit -c phpunit-unit.xml` (699/699, unchanged — no PHP touched), eslint/stylelint clean on the diff (0 errors). Hydra mechanical gates (`run-hydra-gates.sh --scope-to-diff`): 38/39 green; the one failure (gate-46 spec-anchor-existence) is pre-existing dangling `@spec` references to now-archived change directories in `BuilderHost.vue`/`PageDesignerHost.vue`/`useManifestValidator.js`, verified byte-identical on `origin/development` before this change (not introduced here). `composer check:strict` N/A — proposal.md scopes this change as frontend-only ("Backend: none beyond existing manifest validation"), no PHP files were added or modified.
- [ ] 7.2 `openspec validate "app-theming"` passes and `openspec status` shows all artifacts complete before archiving.
