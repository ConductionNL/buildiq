## Context

`nldesign-theme-selection` already solved the hard part of per-app theming: scoping CSS variables to exactly one virtual app's root (`data-openbuild-theme-scope="<appSlug>"`), a defensive rewriter that never injects partially-transformed CSS, a managed `<style data-openbuild-theme="<appSlug>">` element removed on teardown, and the guarantee that theming never touches the NC header/chrome or other apps. That mechanism is scoped to *selecting a whole curated nldesign token set* (`runtime.theme.tokenSet`) — it fetches a static `tokens/<id>.css` asset from the nldesign app. This change adds a second, lighter theming layer for apps that are not on a mandated government design system: a handful of custom colors + a logo + a header style, generated in-app rather than fetched from nldesign, injected through the *same* scoping mechanism.

`app-icon-management` already gives every `Application` an `icon`/`iconDark` ref (OR-attached SVG file). This change's logo picker defaults to those fields rather than adding a third place to upload an app icon.

Constraint: WCAG 2.2 AA becomes a hard NL procurement gate end-2026; the contrast check must be a hard block, not a lint warning a developer can ignore.

## Goals / Non-Goals

**Goals:**
- A citizen developer can set a logo + 3 colors + a header style without touching CSS.
- The contrast guardrail makes an inaccessible theme unsavable, with the failing pair and computed ratio shown inline.
- The applier reuses `nldesign-theme-selection`'s exact scoping mechanism — no second, parallel scoping system to maintain.
- When both an app theme and an nldesign theme are active, the nldesign theme's colors win for every variable it defines (precedence, not conflict).
- Theme travels with version snapshots/promotion/export automatically because it is a plain manifest field (verified against the existing `nldesign-theme-selection` REQ-NTS-004 guarantee, not re-implemented).

**Non-Goals:**
- Arbitrary custom CSS injection — the theme surface is a closed set of typed properties (logo, 3 colors, header style enum), never a free-text CSS field. An open CSS field would bypass the contrast guardrail entirely and is explicitly out of scope.
- Per-page themes — one `appTheme` per Application, same single-object constraint `nldesign-theme-selection` already applies to `runtime.theme`.
- Overriding nldesign when both are set — app theme is always the base layer; there is no "force app theme over nldesign" escape hatch in v1.
- Dark-mode-specific theme tokens — v1's colors apply in both light and dark; a dark-mode variant is deferred (matches the CSS-variable-only constraint's simplicity goal).

## Decisions

### D1 — `appTheme` colors map onto the SAME `--nldesign-*`/`--color-*` custom-property names nldesign's token sets set, not a new namespace
**Choice:** `useAppCustomTheme`'s generated CSS declares `--color-primary`, `--color-primary-element` (or the nldesign-equivalent names nldesign's own token CSS sets, confirmed against a sample `tokens/<id>.css` fetched at implementation time), scoped to `[data-openbuild-theme-scope="<appSlug>"]` — the identical selector `nldesign-theme-selection`'s applier uses.
**Why:** Nextcloud/nldesign components already read these variable names; mapping onto the same names means every existing `NcButton`/`NcTextField`/etc. inside the virtual app picks up the app's colors with zero component-level changes, and it is the only way precedence-by-injection-order (D3) can work — two different variable namespaces would never compete for the same rendered pixel.
**Alternative considered:** A new `--openbuild-app-*` namespace, consumed only by a handful of OpenBuild-authored components. Rejected — it would not theme the standard NC components the manifest's widgets are built from, defeating the point.

### D2 — WCAG contrast check is a pure function run client-side before every save, no bypass
**Choice:** `checkThemeContrast(theme)` computes WCAG 2.x relative luminance (`L = 0.2126R + 0.7152G + 0.0722B` on linearised sRGB channels) and contrast ratio `(L1+0.05)/(L2+0.05)` for: primary-text-on-background (must be ≥4.5:1), and each of primary/secondary/accent-as-UI-element-on-background (must be ≥3:1, the WCAG 1.4.11 non-text threshold). `AppSettingsModal`'s Save action calls this synchronously and blocks with an inline per-pair explanation (computed ratio + required threshold) if any pair fails. No admin override.
**Why:** A lint-level warning is not a gate — the whole point of citing the WCAG 2.2 AA end-2026 mandate as evidence is that OpenBuild should make a non-compliant theme structurally unsavable, matching how OpenBuild already treats manifest validation (hard block, not a warning) elsewhere (e.g. `nldesign-theme-selection`'s unknown-`source` rejection).
**Alternative considered:** Warn but allow save. Rejected — defeats the compliance-gate purpose; a warning a busy citizen developer dismisses once achieves nothing.

### D3 — Precedence is achieved by DOM injection order, not variable-name special-casing
**Choice:** The runtime bootstrap injects `appTheme`'s `<style data-openbuild-app-theme="<appSlug>">` element first (if `runtime.appTheme` is set), then `nldesign-theme-selection`'s existing `<style data-openbuild-theme="<appSlug>">` element (if `runtime.theme` is set) second. Both target the identical `[data-openbuild-theme-scope="<appSlug>"]` selector at identical specificity; CSS cascade rules give the later (nldesign) declaration precedence for any custom property both define. `headerStyle` and `logoRef` are consumed directly by manifest-aware components (not CSS variables nldesign would ever set), so they always apply regardless of which theme is active.
**Why:** Zero special-casing means the two appliers stay fully independent modules — `nldesign-theme-selection`'s applier is never modified by this change, and a future third theme layer could adopt the same "inject earlier to lose, later to win" rule without either existing applier needing to know about it.
**Alternative considered:** Have the appTheme applier explicitly skip any variable name nldesign's token set defines (requires fetching/parsing the nldesign CSS just to compute an exclusion set). Rejected — strictly more complex, adds a runtime dependency from the simpler applier onto the more complex one, for a result CSS cascade order gives for free.

### D4 — Logo defaults to the existing `icon`/`iconDark` Application fields; upload is opt-in, not a new primary path
**Choice:** The theme editor's logo picker shows the current `icon`/`iconDark` as the default swatch with a "use a different image for the theme logo" opt-in that, if used, uploads through the exact same OR-attached-file mechanism `IconService`/`IconController` already implement (a distinct `logoRef` field, same storage pattern).
**Why:** Most apps' brand mark already IS their app icon; forcing a second required upload for the common case is friction the evidence (Appsmith#3095) does not ask for — "logo (app icon reuse or upload)" in the proposal's own scope names reuse first.
**Alternative considered:** A single new `logoRef` field with no default-from-icon behaviour, always requiring an explicit upload. Rejected — extra required step for the common case where the icon already IS the desired theme logo.

### Declarative-vs-imperative decision (ADR-031)
The `runtime.appTheme` manifest block is a declarative, additive JSON property (mirrors `runtime.theme`'s existing declarative shape). The contrast computation (a pure mathematical function, not OpenRegister data) and the CSS-variable generation/DOM injection (crossing into runtime/browser-API territory, identical justification to `nldesign-theme-selection`'s existing applier) are imperative, under the same ADR-031 exception `nldesign-theme-selection`'s design.md already established as precedent for this exact class of runtime CSS-application logic.

## Risks / Trade-offs

- **A citizen developer picks colors that pass the automated contrast check but still look poor together (aesthetics, not accessibility)** → out of scope; the guardrail is a compliance floor, not a design-quality judge, matching what WCAG itself measures.
- **Injection-order precedence (D3) is invisible in the code — a future refactor could reorder the two `<style>` injections and silently invert precedence** → mitigated by a shared integration test asserting computed-style precedence when both themes are set, referenced from both appliers' test files.
- **`--color-primary`/etc. variable-name choice drifts from what a future nldesign token-set actually sets** → mitigated by pinning the exact variable-name list against a real fetched `tokens/<id>.css` sample at implementation time (task item), not guessed from memory.
- **Two managed `<style>` elements per themed app (one for appTheme, one for nldesign) doubles the teardown surface** → both follow the identical existing teardown contract (`nldesign-theme-selection` REQ-NTS-003's "removes that element on app leave/teardown"); the appTheme applier is unit-tested against the same teardown scenario.

## Migration Plan

1. Add `runtime.appTheme` to the manifest validation layer (additive; themeless apps serialize byte-identically — verified by the same regression-test pattern `nldesign-theme-selection` used).
2. Implement `checkThemeContrast.js` (pure function) + unit tests against known-good/known-bad WCAG pairs.
3. Implement the Theme section in `AppSettingsModal` (color pickers, logo picker defaulting to `icon`/`iconDark`, header-style select, live preview, inline contrast-failure explanations, Save gated by (2)).
4. Implement the `useAppCustomTheme` applier (scoped CSS-variable generation + injection before the existing nldesign applier), reusing `data-openbuild-theme-scope`.
5. Confirm version snapshot/promotion/export carry `runtime.appTheme` losslessly (verification task against the existing `nldesign-theme-selection` REQ-NTS-004 machinery — no new plumbing expected).
6. No data migration — fully additive.

**Rollback:** Remove the Theme section from `AppSettingsModal` and stop injecting the appTheme `<style>` element; existing `runtime.appTheme` manifest data becomes inert (ignored, harmless). No impact on `nldesign-theme-selection`'s independent applier.

## Open Questions

- Exact `--color-*`/`--nldesign-*` variable-name list to target — pin against a real fetched nldesign token CSS sample during implementation rather than guessing here. Lean: start with the Nextcloud-standard `--color-primary`/`--color-primary-element`/`--color-background-hover` set (broadest component coverage) and extend if nldesign's own token sets define additional names worth overriding.
- Should `headerStyle: "branded"` show the logo in the app's own header bar (inside the CnAppRoot chrome) or only on entry/landing surfaces? Lean: app header bar — matches the white-label framing of the evidence (Appsmith#3095), decided precisely at implementation time against the current `CnAppRoot` header slot API.

## Seed Data

Example manifest `runtime.appTheme` block:

```json
{
  "runtime": {
    "appTheme": {
      "logoRef": null,
      "primaryColor": "#1D4ED8",
      "secondaryColor": "#0F172A",
      "accentColor": "#F59E0B",
      "headerStyle": "branded"
    }
  }
}
```

`logoRef: null` means "use the Application's existing `icon`/`iconDark`." Example with an opt-in dedicated theme logo:

```json
{
  "runtime": {
    "appTheme": {
      "logoRef": { "ref": "theme-logo.svg" },
      "primaryColor": "#065F46",
      "secondaryColor": "#111827",
      "accentColor": "#DC2626",
      "headerStyle": "compact"
    }
  }
}
```

Example `checkThemeContrast` failure result (blocks Save):

```json
{
  "passed": false,
  "failures": [
    {
      "pair": "primaryColor-on-background",
      "ratio": 2.1,
      "required": 4.5,
      "kind": "text"
    }
  ]
}
```
