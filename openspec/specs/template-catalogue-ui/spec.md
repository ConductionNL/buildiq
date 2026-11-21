---
retrofit: true
---

# template-catalogue-ui Specification

## Purpose

The template-catalogue UI is Buildiq's starter-template gallery.
`TemplateGallery` fetches `ApplicationTemplate` records, filters by category,
resolves per-template screenshots, and opens the clone dialog;
`CloneTemplateDialog` validates the clone target, submits, and redirects to the
new application.

This capability is observed behaviour of those components. It is the frontend
half of the `buildiq-template-catalogue` backend capability.

**OpenSpec changes**: [buildiq-remote-template-store](../../changes/archive/2026-06-20-buildiq-remote-template-store/) _(archived 2026-06-20)_, [github-shop-catalogue](../../changes/github-shop-catalogue/), [github-shop-catalogue](../../changes/archive/2026-11-20-github-shop-catalogue/) _(archived 2026-11-20)_

**Status**: in-progress

## Requirements

### Requirement: Gallery lists the built-in templates, filters them and opens the clone dialog

`TemplateGallery` SHALL fetch the `ApplicationTemplate` records of the `buildiq` register on mount (`fetchTemplates`) and SHALL render them in a **Built-in templates** section of the Templates tab, above the GitHub search section. Each card SHALL show the template's title, a category badge, its use case and its description, plus a **Use this template** action. Seeded templates (`isSeeded: true`) SHALL be listed before organisation templates (`sortedTemplates`), and organisation templates SHALL carry an "Organisation template" badge (REQ-SAT-005). The cards SHALL be grouped by category (`templateGroups`, `categoryLabel`), and one category filter (`templateCategoryOptions`, `onTemplateCategory`) SHALL narrow the built-in and GitHub cards alike. When no template exists the section SHALL say so and point admins at the Buildiq admin settings rather than render an empty grid. When loading fails the section SHALL show a warning note and the GitHub search SHALL keep working.

**Use this template** SHALL open `CloneTemplateDialog` in local mode, seeded with that template (`openClone`). On submit the gallery SHALL call `POST /api/applications/from-template/{templateSlug}` with the user-supplied `name`, `slug` and optional `description` (`onCloneSubmit`), and SHALL open the new application on success (`redirectAfterClone`). The new application SHALL be created with status `draft`. When the description is left empty, the new application SHALL take the template's description. A failed request SHALL be shown inside the dialog.

@e2e exclude retrofit component-contract spec — `fetchTemplates`, `sortedTemplates`, `templateGroups`, `templateCategoryOptions`, `openClone`, `onCloneSubmit` and `redirectAfterClone` are `TemplateGallery` component-state contracts verified by Vitest; the end-to-end clone flow is covered by the buildiq-template-catalogue Playwright tests.

#### Scenario: Filter by category

- **WHEN** the user selects a category
- **THEN** the gallery narrows the visible templates to that category

#### Scenario: Open clone

- **WHEN** the user clicks "Use this template"
- **THEN** the gallery opens the clone dialog seeded with that template

#### Scenario: The Templates tab lists the built-in templates above the GitHub search

- **WHEN** the Templates tab loads on an instance with the four seeded templates
- **THEN** the Built-in templates section lists Permit Tracker, Stakeholder Consultation, Employee Onboarding and Incident Reporter, each with its category badge and a "Use this template" action
- **AND** the GitHub search field and card grid render below the built-in section

#### Scenario: Seeded templates come before organisation templates

- **WHEN** the register holds seeded templates and organisation templates
- **THEN** every seeded template is listed before every organisation template
- **AND** each organisation template carries the "Organisation template" badge

#### Scenario: No built-in template is seeded

- **WHEN** the register holds no application template
- **THEN** the section shows "No built-in templates yet" with a pointer to the Buildiq admin settings instead of an empty grid

#### Scenario: Use this template creates a draft application

- **WHEN** the user clicks "Use this template" on Permit Tracker, enters the name "My permits" and an optional description, and clicks Create
- **THEN** the gallery posts `{ name: "My permits", slug: "my-permits", description }` to `POST /api/applications/from-template/permit-tracker`
- **AND** on success the dialog closes and the gallery opens the new application, whose status is `draft`

### Requirement: Clone dialog validates, submits and redirects

`CloneTemplateDialog` SHALL open seeded from a template (`open`, `resolvedTitle`), gate submission on a valid target (`canSubmit`), submit the clone (`submit`), surface errors (`setError`), and close (`onClose`). On success the gallery SHALL redirect to the new application.

In local mode (neither `remote` nor `github`) the dialog SHALL also show an optional **Description** field, and SHALL include `description` in the submitted payload. In every mode the slug field SHALL follow the name, derived as a kebab-case slug of at most 32 characters, until the user edits the slug field directly. From then on the slug SHALL stay as the user typed it.

@e2e exclude retrofit component-contract spec — `resolvedTitle`, `canSubmit`, `submit`, `setError`, `onClose` and the slug-follows-name behaviour are dialog-component contracts verified by Vitest unit tests; clone-dialog open/submit/redirect integration is covered by the buildiq-template-catalogue Playwright tests

#### Scenario: Reject an empty target

- **WHEN** the clone target is incomplete
- **THEN** `canSubmit` is false and submission is blocked

#### Scenario: Redirect after clone

- **WHEN** the clone succeeds
- **THEN** the dialog closes and the gallery redirects to the new application

#### Scenario: The slug follows the name until edited

- **WHEN** the user types "Bouwvergunningen Noord" in the name field
- **THEN** the slug field reads `bouwvergunningen-noord`
- **WHEN** the user then types `permits-north` in the slug field and changes the name again
- **THEN** the slug field still reads `permits-north`

#### Scenario: Description is offered for a built-in template only

- **WHEN** the dialog opens for a built-in template
- **THEN** it shows an optional Description field
- **WHEN** the dialog opens for a GitHub app
- **THEN** it shows no Description field

### Requirement: Templates page renders the remote store as its primary surface

`TemplateGallery` SHALL render the remote **store as the page's primary surface**
when a registry is configured (`storeConfigured` is true) — a search box and a
grid of remote result cards as the main content — and SHALL render the
locally-seeded templates in a **secondary "Built-in templates" section** below
(kept, not removed). The search box SHALL call `GET /api/store/templates?q=…`
(debounced) and render the returned remote cards (`title`, `useCase`,
`description`, `category`, `version`, optional screenshot). Each remote card
SHALL expose an "Install" action that opens the existing `CloneTemplateDialog`.
The local built-in templates SHALL keep working (install via the local
from-template path) as the secondary section.

@e2e exclude retrofit component-contract spec — the marketplace search-renders-results
and install-opens-dialog behaviours are `TemplateGallery` component-state
contracts verified by Vitest; the end-to-end install flow is covered by the
buildiq-template-catalogue Playwright tests.

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

`CloneTemplateDialog` SHALL route installs of a remote marketplace card to the store
endpoint. When the dialog is opened from a remote marketplace card, a successful
submit SHALL call `POST /api/store/templates/{slug}/install` (instead of the
local from-template endpoint) with the user-supplied name + slug, and on success
SHALL close the dialog and redirect to the new application's editor exactly like
a local clone. Submission SHALL remain gated on a valid target (`canSubmit`) and
errors SHALL be surfaced in the dialog.

@e2e exclude retrofit component-contract spec — the dialog submit-routes-to-store
and redirect-after-install behaviours are dialog-component contracts verified by
Vitest; the integration is covered by the buildiq-template-catalogue Playwright
tests.

#### Scenario: Successful remote install redirects

- **WHEN** the user submits a valid name + slug for a remote template install
- **THEN** the dialog calls the store install endpoint
- **AND** on success the dialog closes and the gallery redirects to the new
  application

### Requirement: No-registry fallback shows built-in templates as the primary surface

`TemplateGallery` SHALL fall back to rendering the locally-seeded **built-in
templates as the primary content** when no registry is configured
(`storeConfigured` is false) — exactly as today's local Templates page — and
SHALL NOT render the marketplace search box or remote cards, and SHALL NOT issue any
store request. Admin
users SHALL additionally see a "configure a registry" hint linking to the
Buildiq admin settings; non-admins SHALL simply see the local templates. This
guarantees a registry-less instance is non-regressed.

#### Scenario: Falls back to local templates when unconfigured

- **WHEN** the Templates page loads with `storeConfigured` false
- **THEN** the marketplace search box and remote cards are not rendered
- **AND** no `GET /api/store/templates` request is issued
- **AND** the local built-in templates are listed as the primary content

### Requirement: Templates can be filtered and browsed by category (REQ-BQGL-001)

The "Templates" view of the app store SHALL offer a category filter and SHALL
group built-in and organisation templates under a heading per category, with
templates without a category under "Other". The filter SHALL also narrow the
GitHub cards by the category their descriptor carries. The chosen category SHALL
be kept in the `category` query parameter.

#### Scenario: A maker looks for field work templates

- **GIVEN** a maker on the app store with built-in templates in two categories and GitHub apps in three
- **WHEN** they pick "Field work" in the category filter
- **THEN** only field work templates and field work GitHub apps show, and the address ends in `?category=field-work`

#### Scenario: A shared link opens the same view

- **GIVEN** a colleague who receives a link to the app store ending in `?category=citizen-engagement`
- **WHEN** they open it
- **THEN** the filter shows "Citizen engagement" and the list is narrowed to it

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
per-source install flows are covered by the buildiq-template-catalogue Playwright
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
verified by Vitest; the integration is covered by the buildiq-template-catalogue
Playwright tests.

#### Scenario: GitHub search renders result cards

- **WHEN** the user types a query in the GitHub tab search box that matches
  `topic:buildiq-app` repos
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
the integration is covered by the buildiq-template-catalogue Playwright tests.

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
