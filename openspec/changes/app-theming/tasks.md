## 1. Manifest

- [ ] 1.1 Add `runtime.appTheme` to the manifest validation layer (logoRef, primaryColor, secondaryColor, accentColor, headerStyle enum); reject unknown headerStyle/non-hex colors/unknown keys.
- [ ] 1.2 Regression test: a themeless app's manifest serializes byte-identically to the pre-feature baseline.

## 2. Contrast guardrail

- [ ] 2.1 `checkThemeContrast.js` — pure WCAG relative-luminance + contrast-ratio function (4.5:1 text, 3:1 UI element); unit tests against known-good/known-bad pairs.

## 3. Theme editor UI

- [ ] 3.1 Theme section in `AppSettingsModal` — color pickers (`inputLabel`), logo picker defaulting to `icon`/`iconDark` with opt-in dedicated upload, header-style select, live preview swatch strip.
- [ ] 3.2 Save gated by `checkThemeContrast`; inline per-pair failure explanation (pair, computed ratio, required threshold); no bypass.

## 4. Applier

- [ ] 4.1 `useAppCustomTheme` composable — maps appTheme colors onto the pinned `--color-*`/`--nldesign-*` variable-name list, scoped to `[data-openbuild-theme-scope]`, injected as a managed `<style data-openbuild-app-theme>` element, torn down on app leave.
- [ ] 4.2 Injection-order coordination: appTheme style injected before the existing nldesign-theme-selection style when both are active.
  - acceptance: shared integration test asserts nldesign wins for any shared variable name
- [ ] 4.3 Header-style + logo consumption in the runtime chrome (`branded`/`compact`/`default`), applying regardless of an active nldesign theme.

## 5. Verification against existing guarantees

- [ ] 5.1 Confirm version snapshot/promotion/export carry `runtime.appTheme` losslessly via the existing manifest-carrying machinery (no new plumbing expected — verification only).

## 6. Tests

- [ ] 6.1 Vitest: manifest validation, contrast function, applier variable mapping, teardown.
- [ ] 6.2 Playwright: set a non-compliant theme (blocked with explanation), set a compliant theme (saves, renders scoped), open the same app with an nldesign theme also active (nldesign color wins, appTheme header style still applies).

## 7. Verify

- [ ] 7.1 `composer check:strict`/vitest and hydra mechanical gates (nc-input-labels, spec-coverage) green on the diff.
- [ ] 7.2 `openspec validate "app-theming"` passes and `openspec status` shows all artifacts complete before archiving.
