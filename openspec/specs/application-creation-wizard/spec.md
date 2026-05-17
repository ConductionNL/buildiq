# application-creation-wizard Specification

## Purpose
TBD - created by archiving change openbuilt-app-creation-wizard. Update Purpose after archive.
## Requirements
### Requirement: REQ-OBWIZ-001 Wizard replaces the legacy Add-Application entry point

The Virtual apps index SHALL open the four-step `CreateApplicationWizard` dialog when the
admin clicks the "Add Application" button. The legacy single-form Add-Application dialog
SHALL be removed in the same change. No fallback / feature-flagged escape hatch SHALL
exist.

#### Scenario: Clicking Add Application opens the wizard

- **WHEN** the admin clicks the "Add Application" button on the Virtual apps index
- **THEN** the `CreateApplicationWizard` `NcModal` opens at step 1 (Basics)
- **AND** no other dialog opens; the legacy single-form Add dialog is absent from the bundle

### Requirement: REQ-OBWIZ-002 Four-step wizard shape

The wizard SHALL consist of four steps in fixed order: (1) **Basics** — name, slug, description, optional light + dark icon upload; (2) **Preset** — radio cards for `single`, `dev-prod`, `dev-staging-prod`, `custom`; (3) **Custom chain** — admin-defined version list, shown ONLY when preset is `custom`; (4) **Review** — read-only summary + Create button.

Selecting any preset other than `custom` SHALL skip step 3 and jump straight to step 4.

#### Scenario: Selecting a canned preset skips the custom step

- **WHEN** the admin selects the `dev-prod` preset in step 2 and clicks Next
- **THEN** the wizard advances directly to step 4 (Review)
- **AND** step 3 (Custom chain) is not visible at any point

#### Scenario: Selecting Custom shows the custom-chain composer

- **WHEN** the admin selects the `custom` preset in step 2 and clicks Next
- **THEN** the wizard advances to step 3 (Custom chain)
- **AND** step 3 is pre-populated with one row named `Production` (slug `production`)

#### Scenario: Back navigation preserves state

- **WHEN** the admin is on step 4 and clicks Back twice (to land on step 2)
- **THEN** the previously-entered name, slug, and description from step 1 are still in place
- **AND** the previously-selected preset is still highlighted

### Requirement: REQ-OBWIZ-003 Preset shapes

Each preset SHALL produce a deterministic version chain when reviewed and submitted:

| Preset | Chain (upstream → downstream) | Production pointer |
|--------|-------------------------------|--------------------|
| `single` | `production` | `production` |
| `dev-prod` | `development → production` | `production` |
| `dev-staging-prod` | `development → staging → production` | `production` |
| `custom` | Admin-defined in step 3 | Terminal (bottom) row |

#### Scenario: dev-staging-prod preset produces a three-version chain

- **WHEN** the admin completes the wizard with preset `dev-staging-prod`, app name `My App`,
  app slug `my-app`
- **THEN** three `ApplicationVersion` records exist with slugs `development`, `staging`,
  `production`
- **AND** `development.promotesTo` points at the staging record's UUID
- **AND** `staging.promotesTo` points at the production record's UUID
- **AND** `production.promotesTo` is null (terminal)
- **AND** the Application's `productionVersion` points at the production record's UUID

#### Scenario: Single preset produces one-version chain with terminal production

- **WHEN** the admin completes the wizard with preset `single`, app slug `<slug>`
- **THEN** one `ApplicationVersion` record exists with slug `production`
- **AND** the Application's `productionVersion` points at it
- **AND** the version's `promotesTo` is null

### Requirement: REQ-OBWIZ-004 Custom-chain composer

When the admin selects the `custom` preset, step 3 SHALL present an add-row list where each
row carries a `name` text input and an auto-derived `slug` chip. The composer SHALL support
adding rows (`+ Add version` button), removing rows (`×` per row), and reordering rows
(both via drag handles and via `↑`/`↓` keyboard-accessible buttons). Row order top-to-bottom
SHALL be interpreted as upstream-to-downstream. The composer SHALL enforce a minimum of one
row.

#### Scenario: Admin composes a 3-version chain by adding rows

- **WHEN** the admin starts on step 3 (one default row `Production`)
- **AND** clicks `+ Add version` to append a second row, types `Staging` as the name
- **AND** clicks `+ Add version` again to append a third row, types `Development` as the name
- **AND** uses the `↑` button on the `Development` row twice to move it to the top
- **THEN** the row order top-to-bottom is `Development`, `Staging`, `Production`
- **AND** advancing to step 4 shows the chain as `development → staging → production`

#### Scenario: Composer cannot have zero rows

- **WHEN** the admin starts on step 3 with the default `Production` row
- **AND** clicks `×` on the `Production` row
- **THEN** the row is NOT removed; an inline error appears: "At least one version is required"

### Requirement: REQ-OBWIZ-005 Slug derivation + leading-underscore rejection

The wizard SHALL auto-derive slugs from names client-side via a `toKebabCase` function: lowercase the input, replace spaces with `-`, strip characters outside `[a-z0-9-]`, collapse double `--`, trim leading/trailing `-`. The derived slug SHALL be displayed as an editable chip with an `Advanced` toggle that reveals the slug input.

The slug pattern (enforced both client-side and server-side) SHALL be `^(?!_)[a-z0-9][a-z0-9-]*[a-z0-9]$`. Leading underscores SHALL be rejected with the user-facing message: "Version slugs cannot start with `_` (reserved for openbuilt system use)."

#### Scenario: Slug auto-derives from app name

- **WHEN** the admin types `My Cool App` in step 1's name field
- **THEN** the slug chip displays `my-cool-app`

#### Scenario: Leading-underscore slug is rejected

- **WHEN** the admin opens the Advanced toggle and types `_internal` as a version slug
- **THEN** an inline error appears: "Version slugs cannot start with `_` (reserved for
  openbuilt system use)"
- **AND** the wizard's Next / Create button is disabled until the slug is corrected

#### Scenario: Slug with invalid characters is rejected

- **WHEN** the admin types `My App!` as a version name and opens the Advanced toggle
- **AND** types `my app!` (with space and `!`) in the slug input
- **THEN** an inline error appears: "Slug must match `^(?!_)[a-z0-9][a-z0-9-]*[a-z0-9]$` —
  lowercase letters, digits, and hyphens only"

### Requirement: REQ-OBWIZ-006 No duplicate version slugs within a chain

Within a single app's chain, two `ApplicationVersion` rows SHALL NOT share a slug. The
wizard SHALL enforce this client-side as the admin types (inline error on the duplicating
row) and server-side at the `/api/applications/wizard` endpoint (the endpoint rejects the
whole payload with `422 Unprocessable Entity` and a JSON body identifying both colliding
rows).

#### Scenario: Client-side duplicate-slug error

- **WHEN** the admin's chain in step 3 contains two rows both named `Staging` (auto-derived
  slug `staging`)
- **THEN** the second row displays an inline error: "Slug `staging` is already used in this
  chain"
- **AND** the wizard's Create button is disabled until the duplicate is resolved

#### Scenario: Server-side duplicate-slug rejection

- **WHEN** a client POSTs a wizard payload to `/api/applications/wizard` with two version
  entries both carrying slug `staging`
- **THEN** the response is `422` with body
  `{ "code": "duplicate_version_slug", "slug": "staging", "rows": [<row-index-1>, <row-index-2>] }`
- **AND** no Application or ApplicationVersion records have been created

#### Scenario: Different apps can each have a `production` version

- **WHEN** Application `app-a` already exists with a version `production`
- **AND** the admin creates Application `app-b` with a version also named `production`
- **THEN** the wizard accepts the payload and creates `app-b`'s `production` version without error
- **AND** `openbuilt-app-a-production` and `openbuilt-app-b-production` registers coexist

### Requirement: REQ-OBWIZ-007 Atomic creation with full rollback on failure

The wizard's backend endpoint SHALL provision the full chain atomically by sequencing:
(1) validate payload, (2) create `Application`, (3) for each version create
`ApplicationVersion` + provision per-version register `openbuilt-{appSlug}-{versionSlug}`,
(4) wire each non-terminal version's `promotesTo` to the next downstream UUID, (5) set
`Application.productionVersion` to the terminal version's UUID.

On ANY failure at any step, the endpoint SHALL roll back every successfully-created object
in reverse creation order (registers first, then ApplicationVersion rows, then Application
row), then return `500` with body
`{ "code": "wizard_rollback", "failedAtStep": "<step-name>", "message": "<original-error>", "rollbackStatus": "complete" | "partial" }`.

If the rollback itself encounters a failure (e.g. OR refuses a register-delete), the response
status SHALL still be `500`, the `rollbackStatus` SHALL be `partial`, and the body SHALL
include `"orphanedResources": [<list of register names + record UUIDs that could not be cleaned>]`
so the admin can resolve manually.

#### Scenario: Validation failure returns 422 without creating anything

- **WHEN** a client POSTs a wizard payload with an invalid app slug (`!nope`)
- **THEN** the response is `422` with body identifying the validation failure
- **AND** no Application, ApplicationVersion, or register has been created

#### Scenario: Register-provisioning failure triggers full rollback

- **WHEN** a client POSTs a valid `dev-prod` wizard payload
- **AND** OR's register-create API rejects the second register-provision call (e.g.
  storage quota exceeded)
- **THEN** the endpoint deletes the first version's already-provisioned register
- **AND** deletes both ApplicationVersion records (regardless of which step they were
  created in)
- **AND** deletes the Application record
- **AND** returns `500` with `failedAtStep: "register-provision-staging"`,
  `rollbackStatus: "complete"`

#### Scenario: Rollback-partial reports orphaned resources

- **WHEN** the same register-provisioning failure occurs as in the previous scenario
- **AND** the rollback's first-register-delete call also fails (e.g. OR is unreachable)
- **THEN** the endpoint returns `500` with `rollbackStatus: "partial"`,
  `orphanedResources: ["openbuilt-<slug>-development"]`
- **AND** the message body advises the admin to remove the orphaned register manually

### Requirement: REQ-OBWIZ-008 Per-version registers + seed schema set

For each `ApplicationVersion` row created by the wizard, a corresponding OR register SHALL
be provisioned with the name `openbuilt-{appSlug}-{versionSlug}`. Each freshly-provisioned
register SHALL have the default schema set (the single `hello-message` schema from
`lib/Resources/wizard/default-schemas.json`) installed and zero objects in it.

#### Scenario: Each version gets its own register with the seed schema

- **WHEN** the wizard successfully creates an app `helloworld` with preset `dev-prod`
- **THEN** OR has two new registers: `openbuilt-helloworld-development` and
  `openbuilt-helloworld-production`
- **AND** each register has exactly one schema named `hello-message`
- **AND** each register has zero objects

### Requirement: REQ-OBWIZ-009 Initial manifest, semver, status per version

Each freshly-created `ApplicationVersion` row SHALL carry:
- `manifest` — copy of `lib/Resources/wizard/default-manifest.json` with the per-version
  register name (`openbuilt-{appSlug}-{versionSlug}`) substituted into the `pages[*].config.register`
  fields.
- `semver` — `0.1.0`.
- `status` — `draft`.
- `application` relation — the new Application's UUID.

#### Scenario: Versions start with the default Hello-World manifest

- **WHEN** the wizard creates an app with preset `single` and slug `hello-world`
- **THEN** the resulting `ApplicationVersion` has `manifest.pages[1].config.register` set to
  `openbuilt-hello-world-production`
- **AND** the version's `semver` is `0.1.0`
- **AND** the version's `status` is `draft`

### Requirement: REQ-OBWIZ-010 Caller becomes sole owner

The wizard endpoint SHALL set the new Application's `permissions.owners` to a single-element
array containing the calling user's UID (in `user:<uid>` form). `permissions.editors` and
`permissions.viewers` SHALL be empty arrays. The admin grants further roles via the
permissions editor post-creation.

#### Scenario: Caller becomes owner; no other principals

- **WHEN** user `admin` POSTs a valid wizard payload
- **THEN** the new Application's `permissions.owners` is `["user:admin"]`
- **AND** `permissions.editors` is `[]`
- **AND** `permissions.viewers` is `[]`

### Requirement: REQ-OBWIZ-011 No install-time auto-seed

The openbuilt app SHALL NOT create any virtual app at install / upgrade time. After
`occ maintenance:repair`, the Virtual apps index SHALL be empty for a fresh install.

#### Scenario: Fresh install has no virtual apps until admin creates one

- **WHEN** openbuilt is installed on a fresh Nextcloud (no prior virtual apps)
- **AND** `occ maintenance:repair` has run
- **THEN** the Virtual apps index page shows the empty state
- **AND** the Add Application button (opening the wizard) is the only call-to-action

