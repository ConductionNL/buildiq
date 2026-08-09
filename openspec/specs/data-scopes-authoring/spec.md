# data-scopes-authoring Specification

## Purpose
TBD - created by archiving change data-scopes-authoring. Update Purpose after archive.
## Requirements
### Requirement: The Schema Designer MUST provide an Access sub-editor with per-operation scopes (REQ-OBDSA-001)

The Schema Designer detail view SHALL mount an `AccessEditor` sub-editor
(following the `FieldEditor` / `LifecycleEditor` / `RelationEditor`
pattern: staged copy owned by the view, `update:access` events, exported
converter helpers) that lets the author declare, independently for each
of the `read`, `create`, `update`, and `delete` operations, exactly one
scope kind: *everyone with app access*, *specific NC groups*, *own
records (creator)*, or *condition* (field-value match against user
context). Group pickers SHALL use `NcSelect` with `inputLabel`. Editing
a scope SHALL mark the schema as staged-dirty exactly like a field edit,
and Discard SHALL revert it.

**ID:** REQ-OBDSA-001

#### Scenario: Author restricts read access to a group
- GIVEN a schema open in the Schema Designer
- WHEN the author sets the `read` scope to *specific groups* with group `vets`
- THEN the Save button enables (staged-dirty)
- AND after Save the persisted schema body contains `authorization.read = ["vets"]`

#### Scenario: Per-operation scopes are independent
- GIVEN a schema whose `read` scope is group `vets`
- WHEN the author sets the `delete` scope to group `admin` and leaves `create`/`update` as *everyone*
- THEN the persisted `authorization` block contains exactly the `read` and `delete` keys

### Requirement: Scopes MUST compile to OpenRegister authorization metadata and round-trip losslessly (REQ-OBDSA-002)

The Access sub-editor's output SHALL be compiled into the schema-level
`authorization` block that OpenRegister enforces (per-operation NC group
ID lists today; the `@creator` sentinel and `authorization.conditions`
shapes once OR advertises them). `bodyToStaged()` SHALL read the
persisted `authorization` block and `composeSchemaBody()` SHALL write it
back; a designer Save MUST NOT strip or reorder an `authorization` block
set outside the designer. Entries the editor cannot represent (unknown
sentinels, conditions on an instance without the capability, annotation
keys such as `_note`) SHALL be preserved verbatim and surfaced read-only
as managed outside the designer.

**ID:** REQ-OBDSA-002

#### Scenario: Persisted scopes survive an unrelated designer save
- GIVEN a schema whose persisted body contains `authorization.read = ["vets"]` set outside the designer
- WHEN the author edits an unrelated field title and saves
- THEN the persisted body still contains `authorization.read = ["vets"]` unchanged

#### Scenario: Unrepresentable entries render read-only and are never dropped
- GIVEN a persisted `authorization` block containing an entry the editor cannot represent (e.g. `"@creator"` while the connected OR does not advertise the `creator` capability)
- WHEN the schema is opened, edited elsewhere, and saved
- THEN the unrepresentable entry is shown as read-only in the Access sub-editor
- AND it is byte-identical in the persisted body after Save

### Requirement: The editor MUST offer only scope kinds the connected OpenRegister supports (REQ-OBDSA-003)

Scope-kind availability SHALL be derived from
`openregister.authorization.scopes` in the Nextcloud capabilities
document (read via `@nextcloud/capabilities`), with a baseline of
`['group']` when the key is absent. *Everyone* and *specific groups*
SHALL always be offered; *own records* SHALL be offered only when
`creator` is advertised; *condition* only when `condition` is
advertised. The editor MUST NOT offer a scope the connected OR cannot
enforce.

**ID:** REQ-OBDSA-003

#### Scenario: Baseline OR offers only everyone and groups
- GIVEN a connected OpenRegister that does not advertise `openregister.authorization.scopes`
- WHEN the author opens a scope-kind picker in the Access sub-editor
- THEN the options are exactly *everyone with app access* and *specific NC groups*

#### Scenario: Advertised capabilities unlock own-records and condition
- GIVEN capabilities advertising `openregister.authorization.scopes = ["group", "creator", "condition"]`
- WHEN the author opens a scope-kind picker
- THEN *own records (creator)* and *condition* are offered
- AND selecting *own records* for `update` compiles to `authorization.update = ["@creator"]`

@e2e exclude capability-injection spec — the deployed dev OR does not advertise `openregister.authorization.scopes`, so the unlocked branch cannot be driven through a live Playwright session; covered by Vitest tests mocking `@nextcloud/capabilities` for `useOrAccessCapabilities` and the AccessEditor option rendering (the baseline branch IS e2e-covered by the previous scenario)

### Requirement: The designer MUST warn when scopes would hide the schema's data from the author (REQ-OBDSA-004)

The Schema Designer SHALL show a warning note when the staged `read` scope is group-based, the selected groups do not
intersect the author's own group memberships (from
`getCurrentUserGroups()`, initial state per ADR-004), and the author is
not an NC admin (admins bypass OR enforcement) — the note states that
saving will make this schema's records invisible to the author. The warning SHALL be advisory: Save remains
enabled. Own-records and condition scopes SHALL NOT trigger the warning.

**ID:** REQ-OBDSA-004

#### Scenario: Non-admin author scoping themself out sees a warning
- GIVEN a non-admin author who is not a member of group `vets`
- WHEN they set the `read` scope to *specific groups* with only `vets`
- THEN a warning note states the schema's records will become invisible to them
- AND the Save button remains enabled

#### Scenario: No warning when the author remains covered
- GIVEN a non-admin author who is a member of group `vets`
- WHEN they set the `read` scope to *specific groups* with `vets`
- THEN no lock-out warning is shown

### Requirement: SchemaListPanel MUST summarise scoped schemas with a badge (REQ-OBDSA-005)

`SchemaListPanel` rows SHALL show a "Restricted" badge for any schema
whose body carries an `authorization` block, with the per-operation
scope summary available as the badge's accessible title. Schemas without
an `authorization` block SHALL show no badge. Badge derivation SHALL be
a pure exported helper so it is unit-testable.

**ID:** REQ-OBDSA-005

#### Scenario: Scoped schema shows a badge in the list
- GIVEN one schema with `authorization.read = ["vets"]` and one schema with no `authorization` block
- WHEN the author opens the Schema Designer list
- THEN the scoped schema's row shows a "Restricted" badge whose title includes `read: vets`
- AND the unscoped schema's row shows no badge

### Requirement: Scope edits MUST be version-scoped like all schema edits (REQ-OBDSA-006)

Editing under `?_version=` SHALL stage and save against that
version's register only — access scopes live inside the schema body in the per-app/per-version
register, and navigation from the Access sub-editor SHALL
preserve `?_version=` (REQ-OBVR-006 conventions). A scope change on a
draft version MUST NOT alter the production version's `authorization`.

**ID:** REQ-OBDSA-006

#### Scenario: Draft-version scope change leaves production untouched
- GIVEN an application with a production version and a draft version
- WHEN the author sets a group `read` scope on a schema under `?_version=<draft>` and saves
- THEN the draft register's schema body carries the new `authorization.read`
- AND the same schema in the production register is unchanged

### Requirement: Production-version scope changes MUST be owner-only (REQ-OBDSA-007)

The Access sub-editor SHALL render read-only with an i18n note that production access scopes can only
be changed by an owner, when the active version is the Application's `productionVersion` and the
caller's role (via `useRole`) is `editor`. Owners and NC admins SHALL retain edit access.
This UI gate mirrors the owner-only release rule (REQ-OBRBAC-004); the
authoritative server-side path to production remains the owner-gated
publish transition (`ApplicationVersionOwnerGuard`) plus OR's register
manage-permission.

**ID:** REQ-OBDSA-007

#### Scenario: Editor sees a read-only Access sub-editor on production
- GIVEN a caller whose role on the Application is `editor`
- WHEN they open a schema of the production version in the Schema Designer
- THEN the Access sub-editor's controls are disabled
- AND a note explains that only an owner can change production access scopes

#### Scenario: Owner can edit production scopes
- GIVEN a caller whose role on the Application is `owner`
- WHEN they open the same production-version schema
- THEN the Access sub-editor is editable

### Requirement: Authored scopes MUST rely on OpenRegister enforcement, not designer gating (REQ-OBDSA-008)

OpenBuild MUST NOT add client-side row filtering that substitutes for
the compiled `authorization` metadata: the Access sub-editor is an authoring surface only, and the authoritative
row-level control is OpenRegister's server-side evaluation of the
compiled block, exactly as the
runtime-group-scoped-access boundary rule states for navigation gating.
Author documentation SHALL state that the
`permission` field (runtime-group-scoped-access) hides navigation while
Access scopes are what actually restrict data.

**ID:** REQ-OBDSA-008

#### Scenario: Compiled scopes are enforced server-side without OpenBuild code
- GIVEN a schema saved with `authorization.read = ["vets"]` through the Access sub-editor
- WHEN a non-vet, non-admin user requests that schema's objects directly from OpenRegister
- THEN OpenRegister returns no objects for that user

@e2e exclude enforcement is OpenRegister server-side and already live-verified plus specified in openspec/changes/runtime-group-scoped-access ("Object access holds even if navigation is bypassed"); this change ships no enforcement code path of its own to drive — its authoring surface is covered by the REQ-OBDSA-001/002 Playwright scenarios

