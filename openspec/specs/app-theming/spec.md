# app-theming Specification

**OpenSpec changes**: [app-theming](../../changes/archive/2026-07-24-app-theming/) _(archived 2026-07-24)_

**Status**: done

## Purpose

Lightweight logo + 3-color + header-style theming for OpenBuild virtual apps
that are not on a mandated NL Design System token set, with a hard,
non-bypassable WCAG contrast guardrail. Reuses `nldesign-theme-selection`'s
scoped `[data-openbuild-theme-scope]` CSS-variable applier mechanism and
`app-icon-management`'s `icon`/`iconDark` fields unmodified.

## Requirements

### Requirement: appTheme manifest block declares logo, colors and header style

The system SHALL support an optional `appTheme` object in the manifest v2
`runtime` block, sibling to `runtime.theme` (nldesign-theme-selection). The
object SHALL carry `logoRef` (nullable OR-attached-file ref; `null` means
"use the Application's `icon`/`iconDark`"), `primaryColor`, `secondaryColor`,
`accentColor` (each a hex color string), and `headerStyle` (closed enum
`default`|`compact`|`branded`). OpenBuild's manifest validation layer SHALL
reject an unknown `headerStyle` value, a non-hex color, and unknown keys. At
most one `appTheme` object exists per app. Apps without an `appTheme` SHALL
serialize byte-identical manifests.

Implementation: `src/services/manifestValidation/appTheme.js`
(`validateAppTheme`), wired into `src/composables/useManifestValidator.js`.

#### Scenario: Valid appTheme declaration passes validation

<!-- @e2e exclude pure app-side manifest validation, covered by vitest tests/services/appThemeValidation.spec.js. -->

- **GIVEN** a virtual app manifest
- **WHEN** it declares `runtime.appTheme: { logoRef: null, primaryColor: "#1D4ED8", secondaryColor: "#0F172A", accentColor: "#F59E0B", headerStyle: "branded" }`
- **THEN** the validator pass reports no errors
- **AND** the saved manifest round-trips the block losslessly

#### Scenario: Unknown headerStyle is rejected

<!-- @e2e exclude pure app-side manifest validation, covered by vitest tests/services/appThemeValidation.spec.js. -->

- **WHEN** the manifest declares `runtime.appTheme.headerStyle: "fancy"`
- **THEN** the validator reports an error against the `appTheme` block
- **AND** the Save action is disabled

#### Scenario: Themeless app serializes byte-identically

<!-- @e2e exclude serialization-regression assertion, covered by vitest tests/components/AppCustomThemeSection.spec.js. -->

- **GIVEN** a virtual app that has never had an `appTheme` set
- **WHEN** the app is saved through a build containing this feature
- **THEN** the persisted manifest is byte-identical to the pre-feature baseline

### Requirement: WCAG contrast guardrail blocks saving a non-compliant theme

`checkThemeContrast(theme)` SHALL compute the WCAG relative-luminance
contrast ratio for primary-text-on-background (required ≥4.5:1) and for each
of primary/secondary/accent used as a UI element on the background (required
≥3:1), against a pinned `#FFFFFF` background reference (NC's
`--color-main-background` light default). `AppCustomThemeSection.vue`
surfaces an inline per-pair explanation as the developer edits, and
`PageDesignerHost.vue`'s Save action calls this check directly and SHALL
block persisting, when any pair fails. There SHALL be no override or bypass
of this check.

Implementation: `src/services/checkThemeContrast.js`, consumed by
`src/components/AppCustomThemeSection.vue` (live inline failures) and
`src/views/PageDesignerHost.vue` `save()` (persist-boundary hard block).

#### Scenario: Low-contrast primary color blocks save

<!-- @e2e exclude persist-boundary hard-block assertion, covered by vitest tests/views/PageDesignerHost.spec.js "save() WCAG contrast guardrail" suite. -->

- **WHEN** a developer sets `primaryColor` to a color yielding a 2.1:1
  contrast ratio against the background
- **THEN** Save is blocked with an inline message naming the pair, the 2.1:1
  computed ratio, and the 4.5:1 required threshold

#### Scenario: Compliant theme saves without friction

<!-- @e2e exclude persist-boundary assertion, covered by vitest tests/views/PageDesignerHost.spec.js "allows save() through when appTheme passes contrast". -->

- **WHEN** a developer sets colors where every required pair meets or exceeds
  its threshold
- **THEN** Save succeeds and the `appTheme` block persists

### Requirement: Theme applies via the existing scoped CSS-variable mechanism

The runtime SHALL apply an active `appTheme` by generating CSS custom
properties scoped to the existing `[data-openbuild-theme-scope="<appSlug>"]`
selector established by `nldesign-theme-selection`, injected as a managed
`<style data-openbuild-app-theme="<appSlug>">` element removed on app
leave/teardown. The applier SHALL NOT inject unscoped (`:root`) rules and
SHALL NOT affect the NC header/chrome, other apps, or other virtual apps.
`primaryColor` maps onto the real Nextcloud-standard `--color-primary`/
`--color-primary-element`/etc. names (so existing NC components pick it up
with zero component changes); `secondaryColor`/`accentColor` map onto
app-scoped `--ob-theme-secondary`/`--ob-theme-accent` custom properties (no
native NC equivalent exists for those roles).

Implementation: `src/composables/useAppCustomTheme.js`.

#### Scenario: Themed app renders scoped variables

<!-- @e2e exclude runtime not reachable in this build (Conduction/openbuild#41); covered by vitest tests/composables/useAppCustomTheme.spec.js. -->

- **GIVEN** a published virtual app whose manifest declares
  `appTheme.primaryColor: "#1D4ED8"`
- **WHEN** an end user opens the app
- **THEN** a `<style data-openbuild-app-theme>` element exists containing
  `[data-openbuild-theme-scope=...]`-scoped declarations and no `:root` rule
- **AND** `--color-primary` computed inside the app root reflects `#1D4ED8`
- **AND** the same variable computed on the NC header is unchanged

#### Scenario: Leaving the app removes the injected style

<!-- @e2e exclude runtime not reachable in this build (Conduction/openbuild#41); covered by vitest tests/composables/useAppCustomTheme.spec.js + tests/views/BuilderHost.spec.js "beforeDestroy tears down both appliers". -->

- **GIVEN** the app-themed app is open
- **WHEN** the user navigates away and the runtime tears down
- **THEN** the managed `<style data-openbuild-app-theme>` element is removed

### Requirement: An active nldesign theme takes precedence over appTheme colors

The system SHALL, when a manifest declares both `runtime.appTheme` and an
active `runtime.theme`, inject the `appTheme` style element before the
`nldesign-theme-selection` style element. Any CSS custom property both
blocks define SHALL resolve to the nldesign token set's value. `appTheme`
maps `primaryColor` onto `--color-primary` (etc.) via a CSS variable
fallback chain against nldesign's real per-app-scoped variable names —
nldesign's scoped token CSS sets only `--nldesign-*`-prefixed properties,
never `--color-*` directly, so pure DOM injection order alone has no shared
property name to act on; the fallback chain is the actual precedence
mechanism, and injection order is still followed for future-proofing.
`appTheme.logoRef` and `appTheme.headerStyle`, which no nldesign token set
governs, SHALL continue to apply regardless of an active nldesign theme.

#### Scenario: nldesign color wins over appTheme color for the same variable

<!-- @e2e exclude runtime not reachable in this build (Conduction/openbuild#41); covered by the dedicated integration test in vitest tests/composables/useAppCustomTheme.spec.js "nldesign precedence" suite, against a real fetched nldesign token CSS sample. -->

- **GIVEN** a manifest declaring both `appTheme.primaryColor: "#1D4ED8"` and
  an active `runtime.theme.tokenSet: "amsterdam"` (whose scoped token CSS
  sets `--nldesign-color-primary: #004699`)
- **WHEN** an end user opens the app
- **THEN** `--color-primary` computed inside the app root is `#004699`, not
  `#1D4ED8`

#### Scenario: appTheme header style still applies alongside an nldesign theme

<!-- @e2e exclude runtime not reachable in this build (Conduction/openbuild#41); covered by vitest tests/views/BuilderHost.spec.js "renders AppBrandedHeader only when headerStyle is branded". -->

- **GIVEN** the same manifest with `appTheme.headerStyle: "branded"`
- **WHEN** an end user opens the app
- **THEN** the branded header style renders regardless of the active
  nldesign theme

### Requirement: Logo defaults to the Application's existing icon fields

The theme editor's logo picker SHALL default to the Application's existing
`icon`/`iconDark` fields (app-icon-management) when `appTheme.logoRef` is
`null`, and SHALL offer an explicit opt-in to upload a dedicated theme logo
that sets `logoRef` to a distinct OR-attached-file reference.

Implementation: `src/components/AppCustomThemeSection.vue` (editor + opt-in
upload, reusing the existing generic OR object-files endpoints — no new
backend route) and `src/components/AppBrandedHeader.vue` (runtime render,
resolving a dedicated `logoRef` via OR's existing files-listing endpoint,
falling back to the app icon on any resolution failure).

#### Scenario: Default theme logo is the app icon

<!-- @e2e exclude runtime not reachable in this build (Conduction/openbuild#41); covered by vitest tests/components/AppBrandedHeader.spec.js "defaults to the app-icon URL when logoRef is null". -->

- **GIVEN** an Application with `icon.ref: "app-icon.svg"` and no dedicated
  theme logo uploaded
- **WHEN** the themed app renders its branded header
- **THEN** the header logo is the Application's `icon`

#### Scenario: Dedicated theme logo overrides the app icon

<!-- @e2e exclude page-designer + runtime UI not functional in this build (Conduction/openbuild#41); covered by vitest tests/components/AppCustomThemeSection.spec.js upload test + tests/components/AppBrandedHeader.spec.js dedicated-logo-resolution test. -->

- **GIVEN** a developer uploads a dedicated theme logo via the opt-in flow
- **WHEN** the themed app renders its branded header
- **THEN** the header logo is the uploaded theme logo, not the Application
  icon
