## ADDED Requirements

### Requirement: Templates page renders a remote store search surface

`TemplateGallery` SHALL render a store section — a search box and a row of remote
result cards — alongside/under the existing local template list, **only when a
registry is configured** (`storeConfigured` is true). The search box SHALL call
`GET /api/store/templates?q=…` (debounced) and render the returned remote cards
(`title`, `useCase`, `description`, `category`, `version`, optional screenshot).
Each remote card SHALL expose an "Install" action that opens the existing
`CloneTemplateDialog`. The local template list SHALL remain unchanged and SHALL
keep working whether or not a registry is configured.

@e2e exclude retrofit component-contract spec — the store search-renders-results
and install-opens-dialog behaviours are `TemplateGallery` component-state
contracts verified by Vitest; the end-to-end install flow is covered by the
openbuild-template-catalogue Playwright tests.

#### Scenario: Search renders remote results

- **WHEN** a registry is configured and the user types a query in the store
  search box that matches remote templates
- **THEN** the gallery renders the returned remote template cards in the store
  section

#### Scenario: Install opens the clone dialog

- **WHEN** the user clicks "Install" on a remote template card
- **THEN** the gallery opens `CloneTemplateDialog` seeded with that remote
  template

### Requirement: Install through CloneTemplateDialog calls the store endpoint

`CloneTemplateDialog` SHALL route installs of a remote store card to the store
endpoint. When the dialog is opened from a remote store card, a successful
submit SHALL call `POST /api/store/templates/{slug}/install` (instead of the
local from-template endpoint) with the user-supplied name + slug, and on success
SHALL close the dialog and redirect to the new application's editor exactly like
a local clone. Submission SHALL remain gated on a valid target (`canSubmit`) and
errors SHALL be surfaced in the dialog.

@e2e exclude retrofit component-contract spec — the dialog submit-routes-to-store
and redirect-after-install behaviours are dialog-component contracts verified by
Vitest; the integration is covered by the openbuild-template-catalogue Playwright
tests.

#### Scenario: Successful remote install redirects

- **WHEN** the user submits a valid name + slug for a remote template install
- **THEN** the dialog calls the store install endpoint
- **AND** on success the dialog closes and the gallery redirects to the new
  application

### Requirement: No-registry empty state hides the store section

`TemplateGallery` SHALL hide the store section when no registry is configured.
When `storeConfigured` is false, `TemplateGallery` SHALL NOT render the store
search section and SHALL NOT issue any store request.
Admin users SHALL see a "configure a registry" hint linking to the OpenBuild
admin settings; non-admins SHALL simply see the local templates with no store
section. The local template list SHALL render exactly as it did before this
change.

#### Scenario: Store section hidden when unconfigured

- **WHEN** the Templates page loads with `storeConfigured` false
- **THEN** the store search box and remote cards are not rendered
- **AND** no `GET /api/store/templates` request is issued
- **AND** the local templates are listed unchanged
