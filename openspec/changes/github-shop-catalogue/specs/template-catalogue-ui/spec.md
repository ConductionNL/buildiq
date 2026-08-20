## ADDED Requirements

### Requirement: Templates page offers Templates (GitHub) / Blocks tabs

> **Amended 2026-07-30 to match what shipped.** This requirement was written as
> "Local / Registry / GitHub source tabs … the Local and Registry surfaces SHALL
> be unchanged … the GitHub tab is additive, so the page never regresses on the
> existing two sources". The code contradicts every clause: the shipped
> `TemplateGallery.vue` has a two-tab strip ("Templates" / "Blocks"), no Local
> tab, no Registry tab, no `storeConfigured` reference, and no reference to
> `application-template` anywhere — the Local source was REPLACED, not kept
> alongside. Verified live and against `origin/development`. Restating the
> requirement as shipped; re-adding a Local (and/or Registry) source is a
> product decision, not a description of the current build.

`TemplateGallery` SHALL present a top-level tablist with **Templates** — the
GitHub source described below — and **Blocks** (`component-blocks`). "Templates"
SHALL be the default selected tab.

Seeded and user-authored `ApplicationTemplate`s are no longer surfaced by this
page. They remain seeded by `Repair\SeedApplicationTemplates`, authored from an
Application's detail page (Save as template / Edit template metadata), and
cloneable via `POST /api/applications/from-template/{templateSlug}`.

@e2e exclude retrofit component-contract spec — the tab-strip source selection is a
`TemplateGallery` component-state contract verified by Vitest; the end-to-end
per-source install flows are covered by the openbuild-template-catalogue Playwright
tests.

#### Scenario: The source tabs are rendered

- **WHEN** the Templates page loads
- **THEN** the page renders exactly two tabs, "Templates" and "Blocks"
- **AND** "Templates" is selected, showing the GitHub search field and card grid

#### Scenario: No local or registry template source is offered

- **WHEN** the Templates page loads
- **THEN** no Local tab and no Registry tab are rendered
- **AND** no locally-seeded `ApplicationTemplate` is listed on the page

### Requirement: GitHub tab searches and renders GitHub app cards

The "Templates" tab SHALL render a server-backed search box that calls
`GET /api/shop/github/search?q=…` (debounced) and a grid of the returned GitHub
result cards (`name`, `description`, `category`, `appType`, `version`, declared
credentials, repo owner/name, optional stars). Each installable card SHALL expose
an **Install** action that opens the existing `CloneTemplateDialog` seeded with
that GitHub app. A card whose repo descriptor is unparseable SHALL be shown as a
non-installable candidate (no Install action) rather than omitted. Any new
dialog/modal introduced by this tab SHALL live in its own file under `src/modals/`
(modal-isolation).

@e2e exclude retrofit component-contract spec — the GitHub search-renders-cards and
install-opens-dialog behaviours are `TemplateGallery` component-state contracts
verified by Vitest; the integration is covered by the openbuild-template-catalogue
Playwright tests.

#### Scenario: GitHub search renders result cards

- **WHEN** the user types a query in the GitHub tab search box that matches
  `topic:openbuild-app` repos
- **THEN** the tab renders the returned GitHub app cards

#### Scenario: Install opens the clone dialog seeded with the GitHub app

- **WHEN** the user clicks "Install" on an installable GitHub card
- **THEN** the page opens `CloneTemplateDialog` seeded with that GitHub app

### Requirement: GitHub install routes through CloneTemplateDialog to the shop endpoint

`CloneTemplateDialog` SHALL route the install of a GitHub card to the GitHub shop
endpoint. When opened from a GitHub card, a successful submit SHALL call
`POST /api/shop/github/install` with the repo identity plus the user-supplied
`name` + `slug`, and on success SHALL close the dialog and redirect to the new
application's editor exactly like a local or registry clone. Submission SHALL
remain gated on a valid target (`canSubmit`), and a strict-parse failure returned
by the endpoint SHALL be surfaced in the dialog as an actionable error (naming the
offending file) without creating anything.

@e2e exclude retrofit component-contract spec — the dialog submit-routes-to-GitHub
and redirect/error behaviours are dialog-component contracts verified by Vitest;
the integration is covered by the openbuild-template-catalogue Playwright tests.

#### Scenario: Successful GitHub install redirects

- **WHEN** the user submits a valid `name` + `slug` for a GitHub app install
- **THEN** the dialog calls `POST /api/shop/github/install`
- **AND** on success the dialog closes and the page redirects to the new
  application

#### Scenario: A malformed-repo install error is surfaced in the dialog

- **WHEN** the endpoint returns a strict-parse failure for the chosen repo
- **THEN** the dialog shows an actionable error naming the offending file
- **AND** nothing is created

### Requirement: GitHub tab degrades clearly when browsing is unavailable

When the GitHub search is rate-limited or unreachable, the "Templates" tab SHALL
show a clear, non-blocking hint (and, when an allowed broker `github` credential
would raise the rate limit, a pointer to add one) WITHOUT breaking the "Blocks"
tab. The feature-detection of a broker credential SHALL be advisory only — the
authoritative gate is the server-side broker; the tab SHALL still function
anonymously when no credential is available.

Because this tab is the page's only template source, "unavailable" is a state the
page MUST state explicitly rather than render as an empty grid — an empty grid is
indistinguishable from "no repositories matched".

#### Scenario: Rate-limited GitHub search shows a hint, the Blocks tab unaffected

- **WHEN** the GitHub search returns a rate-limited outcome
- **THEN** the "Templates" tab shows a clear rate-limit hint
- **AND** the "Blocks" tab continues to work normally

#### Scenario: A credential pointer is shown when it would help

- **WHEN** the GitHub tab is rate-limited and the user has no allowed github
  credential
- **THEN** the tab shows a pointer to add a github credential to raise the limit
- **AND** the tab still allows anonymous browsing
