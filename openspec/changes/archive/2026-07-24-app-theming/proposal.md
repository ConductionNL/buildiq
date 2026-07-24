---
kind: code
---

## Why

Theming/white-label is the single largest demand signal in the whole market scan (Appsmith#3095, 43↑ — bigger than any other ask), and WCAG 2.2 AA becomes a hard NL procurement gate at end-2026 (DigiToegankelijk). OpenBuild already ships the mechanism this needs — `nldesign-theme-selection`'s scoped CSS-variable injection (`data-openbuild-theme-scope`, a managed per-app `<style>` element, defensive rewriter, session cache) and the `icon`/`iconDark` Application fields (`app-icon-management`) — but that capability only lets an app *pick a whole curated nldesign token set*. Most virtual apps are not government apps on a mandated design system; they need a lightweight logo + a few brand colors, with a hard guardrail so a citizen developer cannot ship illegible text.

## What Changes

- **`runtime.appTheme` manifest block**: `logoRef` (reuses the existing `icon`/`iconDark` Application fields by default, or a dedicated upload), `primaryColor`, `secondaryColor`, `accentColor` (hex), `headerStyle` (`default`|`compact`|`branded` enum). Additive, optional, byte-identical serialization for themeless apps (same guarantee as `nldesign-theme-selection` REQ-NTS-001).
- **Theme editor section in `AppSettingsModal`**: color pickers + logo picker (reusing the existing icon upload flow) + header-style select, with a live preview swatch strip. All `NcSelect`/`NcColorPicker` usages carry `inputLabel`.
- **WCAG contrast guardrail**: a pure `checkThemeContrast(theme)` function computes the WCAG relative-luminance contrast ratio for text-on-background and UI-element-on-background pairs derived from the chosen colors; Save is blocked with an inline explanation (which pair fails, the computed ratio, the 4.5:1/3:1 threshold) until every pair passes. No override/bypass — this is a hard gate, not a warning.
- **Scoped CSS-variable application, reusing the existing applier pattern**: a new `useAppTheme` extension (or sibling composable) maps `appTheme` colors onto the existing NL Design System `--color-*`/`--nldesign-*` custom-property names, injected into the *same* `[data-openbuild-theme-scope]`-scoped managed `<style>` mechanism `nldesign-theme-selection` already established — never a second, competing scoping mechanism.
- **Precedence: base < app theme < nldesign org theme**: when a manifest declares both `runtime.appTheme` and `runtime.theme` (an active nldesign token set), the nldesign token set's color variables win for every custom property it defines; `appTheme`'s logo and `headerStyle` (which nldesign's token sets do not govern) still apply. Achieved by DOM injection order (appTheme's `<style>` element injected before nldesign's), not by special-casing variable names.
- **Export includes the theme block**: `runtime.appTheme` is a plain manifest field, so it is already carried by version snapshots, promotion, and the exporter's bundled manifest — verified, not re-implemented (same guarantee `nldesign-theme-selection` REQ-NTS-004 already provides for `runtime.theme`).

## Capabilities

### New Capabilities
- `app-theming`: the `runtime.appTheme` manifest block, the theme editor section in `AppSettingsModal`, the WCAG contrast guardrail, the scoped CSS-variable applier (reusing the existing `data-openbuild-theme-scope` mechanism), and the precedence rule against an active nldesign theme.

### Modified Capabilities
(none — purely additive; `nldesign-theme-selection` and `app-icon-management` are consumed unchanged, referenced below)

### Referenced (no change here)
- `nldesign-theme-selection` — the scoped `<style>`/`data-openbuild-theme-scope` applier mechanism this change reuses, and the theme this change yields precedence to when active.
- `app-icon-management` — the existing `icon`/`iconDark` Application fields this change's logo picker defaults to.

## Impact

- **Schema:** none in OpenBuild's own register (the manifest `theme` block is a plain page/runtime-config JSON property, validated app-side, not an OR schema — matches how `nldesign-theme-selection`'s `runtime.theme` is handled).
- **Backend:** none beyond existing manifest validation — theming is entirely a manifest-shape + frontend-applier feature, no new controller/route.
- **Frontend:** new "Theme" section in `AppSettingsModal` (color pickers, logo picker, header-style select, live preview, inline WCAG failure explanations); new `checkThemeContrast.js` pure function; extension to the `useAppTheme` composable / a sibling `useAppCustomTheme` composable for the CSS-variable mapping + injection-order coordination with the nldesign applier.
- **RBAC:** unaffected — theme editing follows the existing editor/owner rights on `AppSettingsModal`.
- **WCAG:** this change is a compliance-positive addition — a citizen developer cannot save a theme that fails 4.5:1 text / 3:1 UI-element contrast.
