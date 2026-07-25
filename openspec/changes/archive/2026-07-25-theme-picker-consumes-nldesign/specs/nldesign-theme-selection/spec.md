## MODIFIED Requirements

### Requirement: REQ-NTS-002 Builder UI: visual theme picker

The system SHALL provide `ThemePickerDialog.vue` (standalone dialog in `src/dialogs/` per
the modal-isolation rule), opened from a "Theme" section on the application-detail/designer
surface that shows the current theme (swatches + name) or "Default (Nextcloud)". The dialog
SHALL populate its token-set list via a single call to
`@conduction/nextcloud-vue`'s `useScopedTheme().listTokenSets()`, which itself wraps
nldesign's real non-admin `GET /api/token-sets` catalogue endpoint
(`app-token-set-selection` change) and resolves `[]` on any failure (missing app, network
error, non-2xx, malformed body) rather than throwing. When the resolved list is non-empty,
each entry SHALL render name, design system, and colour swatches (`theming.primary_color`,
`theming.background_color`). When the resolved list is empty, the dialog SHALL render the
existing REQ-NTS-005 disabled-with-hint state (`openbuild.theme.hint.nldesign-missing`) —
there is no other fallback tier. The three-tier fallback this requirement previously
specified (admin `GET /apps/nldesign/settings/tokensets`, a feature-probed non-admin
endpoint, and a validated free-text `css/tokens/<id>.css` input) is REMOVED in full; none
of those calls or that input exist in the dialog. The dialog SHALL offer "Default
(Nextcloud)" to remove the theme (deleting `runtime.theme` entirely) and a **live preview
toggle** that, instead of driving a separate OpenBuild-owned applier, mutates the in-flight
manifest object already bound to the page-designer live-preview-pane's sandboxed
`CnAppRoot` instance — that instance re-applies the candidate theme itself per
`scoped-theme-applier` REQ-STA-3. Saving SHALL write/refresh `tokenSet`, `tokenSetName`,
and `preview` snapshots exactly as before. All `NcSelect`s carry `inputLabel`; every
user-visible string uses English-source i18n keys under `openbuild.theme.*` with nl
translations.

#### Scenario: Builder picks a theme from the visual list

- **GIVEN** nldesign is installed and `useScopedTheme().listTokenSets()` resolves a non-empty array
- **WHEN** the builder opens the Theme section, clicks Change, picks "Gemeente Amsterdam" from the list, and saves
- **THEN** the in-flight manifest gains `runtime.theme` with `tokenSet: "amsterdam"` and refreshed name + preview snapshots
- **AND** the Theme section shows the Amsterdam swatches
- **AND** no admin-only or free-text endpoint was ever called

#### Scenario: Empty catalogue renders the absence hint, not a free-text fallback

- **GIVEN** `listTokenSets()` resolves `[]` (nldesign absent, unreachable, or genuinely empty)
- **WHEN** the builder opens the dialog
- **THEN** the change action is disabled with `openbuild.theme.hint.nldesign-missing`
- **AND** no free-text token-set id input is rendered

#### Scenario: Live preview applies via the sandboxed live-preview-pane CnAppRoot and reverts on cancel

- **GIVEN** the dialog is open with "Rijkshuisstijl" selected and the preview toggle on, and the page-designer live-preview-pane's `CnAppRoot` instance is mounted
- **WHEN** the candidate theme changes
- **THEN** the live-preview-pane's bound manifest's `runtime.theme` updates to the candidate
- **AND** that `CnAppRoot` instance re-applies the theme itself (no OpenBuild-owned applier call)
- **WHEN** the builder cancels the dialog
- **THEN** the live-preview-pane's manifest reverts to the previously saved theme (or default) and the saved manifest is unchanged

### Requirement: REQ-NTS-003 Runtime: theme application delegates entirely to `CnAppRoot`/`useScopedTheme`

OpenBuild SHALL own no runtime theme applier. `src/composables/useAppTheme.js` — the
`:root`-rewrite/inject/teardown implementation this requirement previously specified — is
DELETED in full. `@conduction/nextcloud-vue`'s `CnAppRoot` SHALL carry
`data-nldesign-theme-scope="<appId>"` on its own root element and SHALL self-apply
`manifest.runtime.theme` (fetch, verify-flat-`:root`, rewrite, inject one managed
`<style data-nldesign-theme="<appId>">`, teardown on unmount) per that library's
`scoped-theme-applier` REQ-STA-1/REQ-STA-3, with zero OpenBuild-side wiring.
`src/views/BuilderHost.vue`'s nested `CnAppRoot` mount SHALL require no
`data-openbuild-theme-scope` attribute and no `useAppTheme()` call to render correctly; the
same applies to any other `CnAppRoot` mount OpenBuild hosts.

#### Scenario: Themed app renders via CnAppRoot's own applier, no OpenBuild composable involved

- **GIVEN** a published virtual app whose manifest declares `tokenSet: "amsterdam"`
- **WHEN** an end user opens the app (mounted through `BuilderHost.vue`'s nested `CnAppRoot`)
- **THEN** a single `<style data-nldesign-theme="...">` element exists, scoped by `[data-nldesign-theme-scope="..."]`
- **AND** `--nldesign-color-primary` computed inside the app root is `#004699`
- **AND** no `useAppTheme.js` file, no `data-openbuild-theme-scope` attribute, and no `data-openbuild-theme` style element exist anywhere

#### Scenario: Leaving the app removes the injected style (via CnAppRoot's own teardown)

- **GIVEN** the themed app is open
- **WHEN** the user navigates away and `CnAppRoot` tears down
- **THEN** the managed `<style>` element is removed from the document
- **AND** no OpenBuild-owned teardown call was involved

#### Scenario: Missing token asset degrades to default styling (unchanged end-user behaviour)

<!-- @e2e exclude applier 404-degradation now lives entirely in @conduction/nextcloud-vue (scoped-theme-applier); covered against the REAL published dist by tests/composables/nextcloud-vue-useScopedTheme.spec.js, not vitest tests/composables/useAppTheme.spec.js (deleted). -->

- **GIVEN** a manifest referencing a token set whose CSS asset is unreachable
- **WHEN** the app renders
- **THEN** the app renders fully functional in default styling
- **AND** the degrade decision is made entirely inside `useScopedTheme`/`CnAppRoot`, not OpenBuild code

### Requirement: REQ-NTS-006 Integration contract pinned to nldesign's real, published surface

OpenBuild's nldesign integration SHALL call exactly: `@conduction/nextcloud-vue`'s
`useScopedTheme()` — `apply`, `teardown`, `listTokenSets`, `evaluateContrast` — and,
through it only, nldesign's `GET /api/token-sets` and `POST /api/contrast/evaluate`
(`app-token-set-selection` change). OpenBuild SHALL NOT call
`/apps/nldesign/settings/tokensets`, `/apps/nldesign/settings/tokenset-preview/{id}`, or
any other `/settings/*` nldesign route. OpenBuild SHALL NOT fetch
`css/tokens/{tokenSet}.css` directly — that fetch is `useScopedTheme().apply()`'s internal
concern. OpenBuild SHALL NOT import nldesign PHP classes or read its tables, and SHALL NOT
bundle a copy of nldesign's token catalogue or WCAG contrast math. The previously-flagged
Codeberg dependency issue (REQ-NTS-006's original text: "requesting a
`#[NoAdminRequired]` read-only token-set list endpoint") is RESOLVED by
`app-token-set-selection` shipping; no further issue-filing task remains open for this
capability.

#### Scenario: Contract surface is closed to the published, non-admin endpoints only

<!-- @e2e exclude static source-tree assertion, pinned by grep during apply/verify (no /settings/tokensets, /settings/tokenset-preview, or css/tokens/*.css reference in src/) and by ThemePickerDialog.spec.js's "never calls any nldesign settings/* route or a direct css/tokens fetch" test; not a browser flow. -->

- **WHEN** the OpenBuild source tree is scanned for `/apps/nldesign/` references
- **THEN** every reference resolves inside `node_modules/@conduction/nextcloud-vue`'s `useScopedTheme` implementation, never in OpenBuild's own `src/`
- **AND** no `/settings/tokensets` or `/settings/tokenset-preview` call exists anywhere in OpenBuild's own code
- **AND** no direct `css/tokens/*.css` fetch exists in OpenBuild's own code

## ADDED Requirements

### Requirement: REQ-NTS-007 `@conduction/nextcloud-vue` dependency bump gates every deletion

OpenBuild's `package.json` `@conduction/nextcloud-vue` dependency SHALL be bumped to the
first published version that exports `useScopedTheme`, wires `CnAppRoot`'s
`runtime.theme` self-application, and carries the `app-manifest-v2.schema.json` 2.20.0+
`$defs/runtimeTheme` field, BEFORE `src/composables/useAppTheme.js` or
`src/services/manifestValidation/theme.js` is deleted. An apply run that finds the
installed `node_modules/@conduction/nextcloud-vue` missing `useScopedTheme` SHALL NOT
proceed with either deletion.

#### Scenario: Deletions are blocked until the bump is confirmed installed

<!-- @e2e exclude one-time apply-time prerequisite check (task 0.2), not a recurring runtime scenario: `node_modules/@conduction/nextcloud-vue/dist/esm/composables/useScopedTheme.js` was verified present BEFORE useAppTheme.js/manifestValidation/theme.js were deleted in this change; there is no ongoing UI to regress-test. -->

- **GIVEN** the installed `@conduction/nextcloud-vue` does not export `useScopedTheme`
- **WHEN** this change is applied
- **THEN** `useAppTheme.js` and `manifestValidation/theme.js` are NOT deleted
- **AND** the apply run reports the unmet prerequisite explicitly

### Requirement: REQ-NTS-008 Warn-only contrast preview, no local WCAG math

Any WCAG contrast display `ThemePickerDialog.vue` offers for a candidate theme SHALL be
sourced only from `useScopedTheme().evaluateContrast(candidates, background)`. Results
SHALL be displayed as informational only and SHALL NEVER block Save — consistent with
nldesign's own non-blocking selection policy. OpenBuild SHALL contain no relative-luminance or
contrast-ratio computation of its own; `checkThemeContrast.js` (the `app-theming` change's
duplicate, already removed by the PR #20 revert) SHALL NOT be reintroduced in any form.

#### Scenario: Contrast facts display without blocking save

<!-- @e2e exclude covered by vitest tests/components/ThemePickerDialog.spec.js "shows warn-only contrast facts without disabling Save", exercising the real published useScopedTheme.evaluateContrast() via the vitest stub's subpath re-export; not a browser flow. -->

- **GIVEN** `evaluateContrast()` resolves a result with `pass: false` for the candidate theme
- **WHEN** the dialog renders that result
- **THEN** the ratio/level/pass facts are shown
- **AND** the Save button remains enabled

#### Scenario: No local contrast math exists anywhere in OpenBuild

<!-- @e2e exclude static source-tree assertion, pinned by grep during apply/verify (no checkThemeContrast.js, no relative-luminance/contrast-ratio computation in src/); not a browser flow. -->

- **WHEN** the OpenBuild source tree is scanned for relative-luminance or contrast-ratio computation
- **THEN** none exists anywhere in `src/`
- **AND** no file named `checkThemeContrast.js` (or functional equivalent) exists
