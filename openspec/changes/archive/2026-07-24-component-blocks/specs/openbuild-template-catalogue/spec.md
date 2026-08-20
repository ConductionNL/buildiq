## MODIFIED Requirements

### Requirement: Gallery view lists templates with filter and detail

The OpenBuild frontend SHALL register a Vue route `/templates` whose
view (`src/views/TemplateGallery.vue`) lists every
`ApplicationTemplate` visible to the caller via OR REST, plus every
`ComponentBlock` visible to the caller under a distinct "Blocks" filter (see
`component-blocks`). The gallery SHALL:

- Show each `ApplicationTemplate`'s `title`, `useCase`, `description`,
  `category`, and `screenshotUrl` if present
- Show each `ComponentBlock`'s `name`, `description`, `category`, and a
  fragment preview
- Provide filter controls for `category` and a free-text search over
  `title`/`name` + `useCase` + `description`, plus a top-level toggle between
  "Templates" and "Blocks"
- Surface a "Use this template" action per `ApplicationTemplate` card; a
  `ComponentBlock` card SHALL NOT offer "Use this template" — blocks insert
  via the page designer's block library, not the gallery
- Be reachable from a top-level OpenBuild left-nav entry and from a
  "Create from template" CTA on the empty-state of the Application
  list

The gallery SHALL render using `@conduction/nextcloud-vue`'s standard
`CnAppRoot` chrome (no bespoke layout system) and SHALL use Nextcloud
CSS variables only (per ADR-010 — no hardcoded colours).

**ID:** REQ-OBTC-003

#### Scenario: Filtering by category narrows the gallery

- **WHEN** a user opens `/index.php/apps/openbuild/templates` and
  selects the `government-services` category filter
- **THEN** the gallery shows only the `permit-tracker` template
- **AND** the three other seeded templates are hidden from view

#### Scenario: Empty Application list surfaces the gallery CTA

- **WHEN** a user with no Applications navigates to the OpenBuild
  shell home
- **THEN** the empty-state of the Application list shows a "Create
  from template" CTA
- **AND** clicking the CTA navigates to `/templates`

#### Scenario: Blocks filter shows blocks without the clone action

- **WHEN** a user opens `/templates` and switches to the "Blocks" filter
- **THEN** the gallery lists `ComponentBlock` entries with name, description,
  category and a preview
- **AND** no card in the "Blocks" filter offers a "Use this template" action
