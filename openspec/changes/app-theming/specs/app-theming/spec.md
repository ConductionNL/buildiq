## ADDED Requirements

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

#### Scenario: Valid appTheme declaration passes validation

- **GIVEN** a virtual app manifest
- **WHEN** it declares `runtime.appTheme: { logoRef: null, primaryColor: "#1D4ED8", secondaryColor: "#0F172A", accentColor: "#F59E0B", headerStyle: "branded" }`
- **THEN** the validator pass reports no errors
- **AND** the saved manifest round-trips the block losslessly

#### Scenario: Unknown headerStyle is rejected

- **WHEN** the manifest declares `runtime.appTheme.headerStyle: "fancy"`
- **THEN** the validator reports an error against the `appTheme` block
- **AND** the Save action is disabled

#### Scenario: Themeless app serializes byte-identically

- **GIVEN** a virtual app that has never had an `appTheme` set
- **WHEN** the app is saved through a build containing this feature
- **THEN** the persisted manifest is byte-identical to the pre-feature baseline

### Requirement: WCAG contrast guardrail blocks saving a non-compliant theme

`checkThemeContrast(theme)` SHALL compute the WCAG relative-luminance
contrast ratio for primary-text-on-background (required ≥4.5:1) and for each
of primary/secondary/accent used as a UI element on the background (required
≥3:1). `AppSettingsModal`'s Save action SHALL call this check and SHALL block
saving, with an inline explanation naming the failing pair, the computed
ratio, and the required threshold, when any pair fails. There SHALL be no
override or bypass of this check.

#### Scenario: Low-contrast primary color blocks save

- **WHEN** a developer sets `primaryColor` to a color yielding a 2.1:1
  contrast ratio against the background
- **THEN** Save is blocked with an inline message naming the pair, the 2.1:1
  computed ratio, and the 4.5:1 required threshold

#### Scenario: Compliant theme saves without friction

- **WHEN** a developer sets colors where every required pair meets or exceeds
  its threshold
- **THEN** Save succeeds and the `appTheme` block persists

### Requirement: Theme applies via the existing scoped CSS-variable mechanism

The runtime SHALL apply an active `appTheme` by generating CSS custom
properties (mapped onto the same `--color-*`/`--nldesign-*` names Nextcloud
and nldesign components read) scoped to the existing
`[data-openbuild-theme-scope="<appSlug>"]` selector established by
`nldesign-theme-selection`, injected as a managed
`<style data-openbuild-app-theme="<appSlug>">` element removed on app
leave/teardown. The applier SHALL NOT inject unscoped (`:root`) rules and
SHALL NOT affect the NC header/chrome, other apps, or other virtual apps.

#### Scenario: Themed app renders scoped variables

- **GIVEN** a published virtual app whose manifest declares
  `appTheme.primaryColor: "#1D4ED8"`
- **WHEN** an end user opens the app
- **THEN** a `<style data-openbuild-app-theme>` element exists containing
  `[data-openbuild-theme-scope=...]`-scoped declarations and no `:root` rule
- **AND** `--color-primary` computed inside the app root reflects `#1D4ED8`
- **AND** the same variable computed on the NC header is unchanged

#### Scenario: Leaving the app removes the injected style

- **GIVEN** the app-themed app is open
- **WHEN** the user navigates away and the runtime tears down
- **THEN** the managed `<style data-openbuild-app-theme>` element is removed

### Requirement: An active nldesign theme takes precedence over appTheme colors

When a manifest declares both `runtime.appTheme` and an active `runtime.theme`, the system SHALL inject the `appTheme` style element before the `nldesign-theme-selection` style
element, so that any CSS custom property both blocks define resolves to the
nldesign token set's value by cascade order. `appTheme.logoRef` and
`appTheme.headerStyle`, which no nldesign token set governs, SHALL continue
to apply regardless of an active nldesign theme.

#### Scenario: nldesign color wins over appTheme color for the same variable

- **GIVEN** a manifest declaring both `appTheme.primaryColor: "#1D4ED8"` and
  an active `runtime.theme.tokenSet: "amsterdam"` (which sets
  `--color-primary: #004699`)
- **WHEN** an end user opens the app
- **THEN** `--color-primary` computed inside the app root is `#004699`, not
  `#1D4ED8`

#### Scenario: appTheme header style still applies alongside an nldesign theme

- **GIVEN** the same manifest with `appTheme.headerStyle: "branded"`
- **WHEN** an end user opens the app
- **THEN** the branded header style renders regardless of the active
  nldesign theme

### Requirement: Logo defaults to the Application's existing icon fields

The theme editor's logo picker SHALL default to the Application's existing
`icon`/`iconDark` fields (app-icon-management) when `appTheme.logoRef` is
`null`, and SHALL offer an explicit opt-in to upload a dedicated theme logo
that sets `logoRef` to a distinct OR-attached-file reference.

#### Scenario: Default theme logo is the app icon

- **GIVEN** an Application with `icon.ref: "app-icon.svg"` and no dedicated
  theme logo uploaded
- **WHEN** the themed app renders its branded header
- **THEN** the header logo is the Application's `icon`

#### Scenario: Dedicated theme logo overrides the app icon

- **GIVEN** a developer uploads a dedicated theme logo via the opt-in flow
- **WHEN** the themed app renders its branded header
- **THEN** the header logo is the uploaded theme logo, not the Application
  icon
