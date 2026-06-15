# nldesign-theme-selection Specification

## Purpose
TBD - created by archiving change nldesign-theme-selection. Update Purpose after archive.
## Requirements
### Requirement: REQ-NTS-001 Theme declaration in the v2 manifest

The system SHALL support an optional `theme` object in the manifest v2 `runtime` block. The object SHALL carry:

- `source` (closed enum `nldesign`, required) — theme provider; only nldesign is supported in v1.
- `tokenSet` (string, required, kebab-case id) — the nldesign token-set id (e.g. `rijkshuisstijl`, `amsterdam`).
- `tokenSetName` (string, required) — display snapshot of the set's name at pick time (refreshed on edit).
- `preview` (object, optional) — `{ primaryColor, backgroundColor }` hex snapshots for swatch rendering without an nldesign call.

OpenBuild's manifest validation layer SHALL reject: an unknown `source`, a missing or non-kebab-case `tokenSet`, non-hex `preview` colours, and unknown keys. At most one `theme` object exists per app (it is a single object, not an array); per-page themes are not supported in v1. Apps without a theme SHALL serialize byte-identical manifests (purely additive). Codification into the canonical `app-manifest-v2.schema.json` is an external `nextcloud-vue` follow-up, not part of this requirement.

#### Scenario: Valid theme declaration passes validation

<!-- @e2e exclude pure app-side manifest validation, covered by vitest tests/services/themeValidation.spec.js. -->

- **GIVEN** a virtual app manifest
- **WHEN** it declares `runtime.theme: { source: "nldesign", tokenSet: "amsterdam", tokenSetName: "Gemeente Amsterdam", preview: { primaryColor: "#004699", backgroundColor: "#FFFFFF" } }`
- **THEN** the validator pass reports no errors
- **AND** the saved manifest round-trips the block losslessly

#### Scenario: Unknown source is rejected

<!-- @e2e exclude pure app-side manifest validation, covered by vitest tests/services/themeValidation.spec.js. -->

- **WHEN** the manifest declares `runtime.theme.source: "material"`
- **THEN** the validator reports `openbuild.theme.error.unknown-source` against the theme block
- **AND** the Save button is disabled

#### Scenario: Themeless app serializes byte-identically

<!-- @e2e exclude serialization-regression assertion, covered by vitest tests/components/ThemeSection.spec.js. -->

- **GIVEN** a virtual app that has never had a theme set
- **WHEN** the app is saved through a build containing this feature
- **THEN** the persisted manifest is byte-identical to the pre-feature baseline

### Requirement: REQ-NTS-002 Builder UI: visual theme picker

The system SHALL provide `ThemePickerDialog.vue` (standalone dialog in `src/dialogs/` per the modal-isolation rule), opened from a "Theme" section on the application-detail/designer surface that shows the current theme (swatches + name) or "Default (Nextcloud)". The dialog SHALL populate its token-set list by, in order: (a) `GET /apps/nldesign/settings/tokensets` when the session is admin (the route is `AuthorizedAdminSetting` today — a 403 is treated as "list unavailable", probed once per session, never surfaced as an error); (b) the flagged non-admin nldesign list endpoint once it exists, detected via a cached feature probe; (c) a **validated free-text fallback**: a token-set id input that verifies the id by fetching the static `css/tokens/<id>.css` asset (404 ⇒ inline "unknown token set" error) and derives swatches by parsing the fetched variables. When a list IS available, each entry SHALL render name, description, and colour swatches. The dialog SHALL offer "Default (Nextcloud)" to remove the theme (deleting `runtime.theme` entirely) and a **live preview toggle** that applies the candidate theme to the designer's preview surface via the same applier as REQ-NTS-003 before saving. Saving SHALL write/refresh `tokenSet`, `tokenSetName`, and `preview` snapshots. All `NcSelect`s carry `inputLabel`; every user-visible string uses English-source i18n keys under `openbuild.theme.*` with nl translations.

#### Scenario: Admin builder picks a theme from the visual list

- **GIVEN** nldesign is installed and the builder's session is admin
- **WHEN** the builder opens the Theme section, clicks Change, picks "Gemeente Amsterdam" from the list, and saves
- **THEN** the in-flight manifest gains `runtime.theme` with `tokenSet: "amsterdam"` and refreshed name + preview snapshots
- **AND** the Theme section shows the Amsterdam swatches

#### Scenario: Non-admin builder uses the validated free-text fallback

- **GIVEN** nldesign is installed, the session is non-admin, and the flagged non-admin list endpoint does not exist
- **WHEN** the builder opens the dialog
- **THEN** no error toast appears and the free-text token-set input is offered
- **WHEN** the builder enters `rijkshuisstijl`
- **THEN** the dialog validates it by fetching `css/tokens/rijkshuisstijl.css`, renders derived swatches, and enables Save

#### Scenario: Unknown token-set id is rejected inline

- **WHEN** the builder enters `not-a-real-set` in the free-text input
- **THEN** the asset fetch returns 404 and the dialog shows `openbuild.theme.error.unknown-token-set` inline
- **AND** Save remains disabled

#### Scenario: Live preview applies before saving and reverts on cancel

- **GIVEN** the dialog is open with "Rijkshuisstijl" selected and the preview toggle on
- **WHEN** the designer preview surface re-renders
- **THEN** it renders with the Rijkshuisstijl variables scoped to the preview root
- **WHEN** the builder cancels the dialog
- **THEN** the preview surface reverts to the previously saved theme (or default) and the manifest is unchanged

### Requirement: REQ-NTS-003 Runtime: scoped theme application

The virtual-app runtime host SHALL carry a `data-openbuild-theme-scope="<appSlug>"` attribute on its root element. The system SHALL provide `useAppTheme.js`, which, when the resolved manifest declares `runtime.theme`: (1) fetches the static asset `generateFilePath('nldesign', 'css', 'tokens/<tokenSet>.css')`; (2) rewrites every `:root` selector in the fetched text to `[data-openbuild-theme-scope="<appSlug>"]` (mechanical selector-prefix transform; no style values are altered or user-authored); (3) injects the result as exactly one managed `<style data-openbuild-theme="<appSlug>">` element; and (4) removes that element on app leave/teardown. Fetched CSS SHALL be cached per token set for the session. The transform SHALL be defensive: if the fetched text contains constructs the rewriter does not positively recognise (e.g. nested at-rules), the applier SHALL inject nothing and degrade to default styling with one console warning — never partially-rewritten CSS. Theme application SHALL NOT modify any nldesign appconfig, SHALL NOT inject unscoped (`:root`) rules, and SHALL NOT affect the NC header/chrome, other apps, or other virtual apps.

#### Scenario: Themed app renders scoped variables

- **GIVEN** a published virtual app whose manifest declares `tokenSet: "amsterdam"`
- **WHEN** an end user opens the app
- **THEN** a single `<style data-openbuild-theme>` element exists containing `[data-openbuild-theme-scope=...]`-scoped declarations and no `:root` rule
- **AND** `--nldesign-color-primary` computed inside the app root is `#004699`
- **AND** the same variable computed on the NC header is unchanged

#### Scenario: Leaving the app removes the injected style

- **GIVEN** the themed app is open
- **WHEN** the user navigates away and the runtime tears down
- **THEN** the managed `<style>` element is removed from the document

#### Scenario: Missing token asset degrades to default styling

<!-- @e2e exclude applier 404-degradation, covered by vitest tests/composables/useAppTheme.spec.js. -->

- **GIVEN** a manifest referencing a token set whose CSS asset returns 404 (set removed/renamed in nldesign)
- **WHEN** the app renders
- **THEN** the app renders fully functional in default styling
- **AND** exactly one console warning notes the failed theme load
- **AND** no error surface is shown to the end user

#### Scenario: Unrecognised CSS constructs inject nothing

<!-- @e2e exclude applier at-rule bail-out, covered by vitest tests/composables/useAppTheme.spec.js + rewriteRootScope unit tests. -->

- **GIVEN** a token CSS payload containing a nested `@media` block the rewriter does not positively recognise
- **WHEN** the applier processes it
- **THEN** no style element is injected and default styling applies
- **AND** one console warning identifies the token set

### Requirement: REQ-NTS-004 Theme travels with versioning, promotion, and export

Because `runtime.theme` lives in the manifest, it SHALL be captured in ApplicationVersion snapshots, carried by version promotion (all strategies), included verbatim in the exporter's bundled manifest, and applied by the exported app through the same `useAppTheme` path. Builder preview of a non-production version via `?_version=` SHALL render that version's theme (which may differ from production's).

#### Scenario: Promotion carries the theme

<!-- @e2e exclude version-promotion exercised by the existing promoteDestructive/version e2e; theme is a plain manifest field carried losslessly, asserted by applier vitest. -->

- **GIVEN** a development version themed `rijkshuisstijl` and a production version themed `nextcloud`-default
- **WHEN** the development version is promoted to production
- **THEN** the production manifest's `runtime.theme.tokenSet` is `rijkshuisstijl`

#### Scenario: Version preview renders the version's own theme

<!-- @e2e exclude version-routing covered by the existing versionRouting e2e; theme-per-version selection covered by useAppTheme applyTheme(version) vitest. -->

- **GIVEN** the same two versions
- **WHEN** an editor opens the app with `?_version=` targeting the development version
- **THEN** the rendered app is scoped to the Rijkshuisstijl variables while production users continue to see the default

### Requirement: REQ-NTS-005 Capability check and graceful absence of nldesign

At design time, when `useAppStatus('nldesign')` reports the app missing or disabled, the Theme section SHALL render its change action disabled with the i18n hint `openbuild.theme.hint.nldesign-missing` (an existing theme remains visible and removable). At runtime on an instance without nldesign, a themed manifest SHALL render in default styling with one console warning. The theme SHALL NOT add `"nldesign"` to the manifest `dependencies[]` array and SHALL NOT trigger CnAppRoot's dependency gate — theming is a progressive enhancement, never a gate. No openbuild surface SHALL hard-fail, blank, or throw because nldesign is absent.

#### Scenario: Designer degrades when nldesign is missing

- **GIVEN** nldesign is not installed
- **WHEN** the builder opens the Theme section
- **THEN** the change action is disabled with the missing-app hint
- **AND** an existing theme declaration can still be removed

#### Scenario: Themed app still renders without nldesign

- **GIVEN** a published app themed `amsterdam`, on an instance where nldesign was uninstalled after publication
- **WHEN** an end user opens the app
- **THEN** the app renders fully functional in default styling
- **AND** no dependency-gate block is shown

#### Scenario: Saving a theme never edits dependencies

<!-- @e2e exclude dependencies-untouched assertion, covered by vitest tests/components/ThemeSection.spec.js. -->

- **WHEN** the builder saves a manifest after picking a theme
- **THEN** the persisted `dependencies[]` array is unchanged from before the pick

### Requirement: REQ-NTS-006 Integration contract pinned to nldesign's existing surface

OpenBuild's nldesign integration SHALL call exactly: the static asset `css/tokens/{tokenSet}.css` (runtime + free-text validation + swatch derivation), `GET /apps/nldesign/settings/tokensets` (builder list, admin sessions only, 403-tolerant), and `GET /apps/nldesign/settings/tokenset-preview/{tokenSetId}` (builder swatches, admin sessions only, 403-tolerant) — plus, once it exists, the flagged non-admin list endpoint. OpenBuild SHALL NOT write to any nldesign endpoint or appconfig, SHALL NOT import nldesign PHP classes or read its tables, and SHALL NOT bundle a copy of nldesign's token catalogue. The static-asset contract SHALL be pinned by a Newman assertion (asset returns 200, `text/css`, contains a `:root` block declaring `--nldesign-color-primary`) so nldesign-side drift fails CI rather than production. A Codeberg issue against `Conduction/nldesign` MUST be filed during apply requesting (a) a `#[NoAdminRequired]` read-only token-set list endpoint and (b) documentation of `css/tokens/*.css` as a consumable contract; the issue URL is recorded in tasks.md.

#### Scenario: Contract surface is closed

<!-- @e2e exclude static source-tree assertion + Newman contract; pinned by tests/integration/openbuild-nldesign-theme.postman_collection.json, not a browser flow. -->

- **WHEN** the openbuild source tree is scanned for `/apps/nldesign/` and `nldesign` asset references
- **THEN** every call target is one of the listed reads (or the feature-probed non-admin list endpoint)
- **AND** no nldesign PHP namespace is imported anywhere in openbuild
- **AND** no POST/PUT/DELETE to nldesign exists

#### Scenario: Newman pins the token asset shape

<!-- @e2e exclude Newman asset-contract scenario; pinned by tests/integration/openbuild-nldesign-theme.postman_collection.json, not a browser flow. -->

- **GIVEN** the Newman collection's token-asset request for `rijkshuisstijl`
- **WHEN** the collection runs against the dev instance
- **THEN** the response is 200 with a CSS body containing `:root` and `--nldesign-color-primary`

