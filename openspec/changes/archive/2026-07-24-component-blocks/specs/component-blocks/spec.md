## ADDED Requirements

### Requirement: ComponentBlock schema captures a manifest fragment with dependency metadata

The system SHALL declare a `ComponentBlock` schema in the `openbuild`
register namespace with properties `uuid`, `slug`, `name`, `description`,
`category`, `schemaDependencies` (array of de-namespaced schema slugs the
fragment references), `fragment` (the captured widget or page-section
manifest subtree, keyed by its original `widgetEntry.id`/`page.id`),
`sourceApplicationSlug` (provenance only), and `createdBy`. A `ComponentBlock`
SHALL NEVER contain object data (record rows) — only structure, matching the
`save-as-template` "never captures rows" guarantee.

#### Scenario: Saving a widget captures its config, not its data

- **GIVEN** a page with a `status-badge` widget bound to a schema holding 200
  records
- **WHEN** an editor saves that widget as a `ComponentBlock`
- **THEN** the block's `fragment` contains the widget's type and config only
- **AND** no record from the bound schema appears anywhere in the block

### Requirement: Save-as-block flow captures a widget or a page section

The system SHALL provide `SaveBlockDialog.vue` (`src/dialogs/`), opened from
the page designer for a selected widget or a selected contiguous page
section, exposing `name`, `description`, `category`, and a dependency
summary listing each de-namespaced `schemaDependencies` entry. Saving SHALL
create the `ComponentBlock` via standard OR REST.

#### Scenario: Save a single widget as a block

- **WHEN** an editor selects a configured widget and saves it via
  `SaveBlockDialog` with name "Status badge"
- **THEN** a `ComponentBlock` is created whose `fragment` is exactly that
  widget's manifest subtree

#### Scenario: Save a page section as a block

- **WHEN** an editor selects a multi-widget page section and saves it via
  `SaveBlockDialog`
- **THEN** a `ComponentBlock` is created whose `fragment` contains every
  selected widget, preserving their relative layout

### Requirement: Block library panel lists, filters and previews blocks

The system SHALL provide a block-library panel in the page designer listing
every `ComponentBlock` visible to the caller (org-scoped, per D3), filterable
by `category`, with a preview of each block's rendered fragment before
insert.

#### Scenario: Library lists org-wide blocks

- **GIVEN** editor A saved a block from app `permits` and editor B is
  building app `subsidies` in the same organisation
- **WHEN** editor B opens the block library panel
- **THEN** editor B sees the block editor A saved from `permits`

### Requirement: Insert deep-copies the fragment with freshly minted ids

Inserting a `ComponentBlock` into a page SHALL deep-copy its `fragment` into
the target page's manifest, generating a new `widgetEntry.id`/`page.id` for
every node in the copied subtree so that repeated insertions of the same
block never collide on id. Insert SHALL NOT create any live reference,
pointer, or subscription back to the source `ComponentBlock` — editing the
source block after insertion SHALL NOT affect any previously inserted copy,
and editing an inserted copy SHALL NOT write back to the source block.

#### Scenario: Inserting the same block twice does not collide

- **WHEN** an editor inserts the same `ComponentBlock` into the same page
  twice
- **THEN** the two inserted copies have distinct `widgetEntry.id` values
- **AND** both render correctly on the page

#### Scenario: Editing the source block does not affect an inserted copy

- **WHEN** an editor edits and re-saves the source `ComponentBlock`
- **THEN** a copy of that block inserted before the edit remains unchanged
  on its page

### Requirement: Schema-dependency mismatch triggers an explicit remap prompt

Insert SHALL open `BlockRemapDialog.vue`, requiring the developer to map each unmatched dependency to a schema in the target app before the fragment is inserted, whenever a block's `schemaDependencies` do not exact-match
schema slugs present in the target app. The system SHALL NOT silently guess a
remapping. A binding left unresolved SHALL insert as a visible "needs remap"
placeholder rather than being silently dropped.

#### Scenario: Cross-app insert with matching schema name needs no prompt

- **GIVEN** a block whose `schemaDependencies` is `["permit-application"]`
- **WHEN** it is inserted into an app that also has a schema slug
  `permit-application`
- **THEN** no remap prompt appears and the fragment inserts bound to that
  schema

#### Scenario: Cross-app insert with no matching schema requires remap

- **GIVEN** a block whose `schemaDependencies` is `["permit-application"]`
- **WHEN** it is inserted into an app with no schema slug
  `permit-application`
- **THEN** `BlockRemapDialog` opens requiring the developer to choose a
  target schema before the insert completes

#### Scenario: Unresolved remap inserts a visible placeholder, not a silent drop

- **WHEN** a developer dismisses `BlockRemapDialog` without resolving a
  dependency
- **THEN** the corresponding binding in the inserted fragment renders a
  "needs remap" placeholder
- **AND** the field is not silently omitted from the inserted config

### Requirement: Blocks export and import as standalone JSON

A `ComponentBlock` SHALL be exportable as a standalone JSON file
(`{schemaVersion, kind: "component-block", block: {...}}`, `uuid`/`createdBy`
stripped) and importable back into any organisation, triggering the same
schema-dependency remap flow as a normal insert when the importing
organisation's schemas do not match.

#### Scenario: Exported block imports into a different organisation

- **WHEN** a block exported from organisation A is imported into
  organisation B whose schemas use different slugs
- **THEN** the import flow opens `BlockRemapDialog` before creating the new
  `ComponentBlock`

### Requirement: Blocks appear in the template catalogue gallery

The template catalogue gallery SHALL list `ComponentBlock`s alongside
`ApplicationTemplate`s under a distinct "Blocks" filter, showing each block's
`name`, `description`, `category`, and a preview, without offering the
full-app "Use this template" clone action (blocks insert via the page
designer's block library, not the gallery's clone flow).

#### Scenario: Blocks filter shows only blocks

- **WHEN** a user opens `/templates` and selects the "Blocks" filter
- **THEN** only `ComponentBlock` entries render
- **AND** no `ApplicationTemplate` entry renders
