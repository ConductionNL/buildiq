## ADDED Requirements

### Requirement: Templates page offers Local / Registry / GitHub source tabs

`TemplateGallery` SHALL present a source **tab strip** with **Local** (the
locally-seeded built-in templates), **Registry** (the remote-OpenRegister store,
shown when `storeConfigured`), and **GitHub** (the new GitHub source) tabs. The
Local and Registry surfaces SHALL be unchanged from their current behaviour — the
GitHub tab is additive, so the page never regresses on the existing two sources.
The default selected tab SHALL preserve today's behaviour (Registry-primary when
a registry is configured, otherwise Local), with GitHub as an opt-in tab.

@e2e exclude retrofit component-contract spec — the tab-strip source selection is a
`TemplateGallery` component-state contract verified by Vitest; the end-to-end
per-source install flows are covered by the openbuild-template-catalogue Playwright
tests.

#### Scenario: The source tabs are rendered

- **WHEN** the Templates page loads on an instance with a registry configured
- **THEN** the page renders Local, Registry, and GitHub source tabs
- **AND** the Local and Registry tabs list their templates exactly as before

#### Scenario: Registry tab hidden when no registry is configured

- **WHEN** the Templates page loads with `storeConfigured` false
- **THEN** the Registry tab is not shown
- **AND** the Local tab lists the built-in templates as today (no regression)

### Requirement: GitHub tab searches and renders GitHub app cards

The GitHub tab SHALL render a server-backed search box that calls
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

When the GitHub search is rate-limited or unreachable, the GitHub tab SHALL show
a clear, non-blocking hint (and, when an allowed broker `github` credential would
raise the rate limit, a pointer to add one) WITHOUT breaking the Local or Registry
tabs. The feature-detection of a broker credential SHALL be advisory only — the
authoritative gate is the server-side broker; the tab SHALL still function
anonymously when no credential is available.

#### Scenario: Rate-limited GitHub search shows a hint, other tabs unaffected

- **WHEN** the GitHub search returns a rate-limited outcome
- **THEN** the GitHub tab shows a clear rate-limit hint
- **AND** the Local and Registry tabs continue to work normally

#### Scenario: A credential pointer is shown when it would help

- **WHEN** the GitHub tab is rate-limited and the user has no allowed github
  credential
- **THEN** the tab shows a pointer to add a github credential to raise the limit
- **AND** the tab still allows anonymous browsing
