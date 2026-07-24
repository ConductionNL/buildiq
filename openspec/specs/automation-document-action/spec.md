# automation-document-action Specification

**OpenSpec changes**: [automation-document-action](../../changes/archive/2026-07-24-automation-document-action/) _(archived 2026-07-24)_

**Status**: done

## Purpose

Lets an OpenBuild automation trigger Docudesk document generation
automatically (on object create/update/delete or a lifecycle transition) —
no interactive browser click required. Extends the existing
`automation-designer` compiler with a new `generateDocument` action kind and
reuses `docudesk-document-templates`'s already-integration-tested,
Newman-pinned Docudesk contract unchanged, only naming a second caller shape
(owner-impersonated, server-side) alongside the existing interactive one.

## Requirements

### Requirement: DocumentGenerationService dispatches attach, download-link, and notify output modes

`DocumentGenerationService::generate()` SHALL, given a triggering object and
a `generateDocument` action config, call Docudesk's `correspondence/generate`
route (owner-impersonated, per the modified `docudesk-document-templates`
REQ-DDT-006) and then, per `output`: `attach` — write the returned bytes to
Nextcloud Files via `OCP\Files\IRootFolder` and set a `{ "ref": "<fileId>" }`
reference on the triggering object's designated attachment field;
`download-link` — generate a short-lived signed download URL without
persisting a file; `notify` — dispatch a notification referencing the
generated document, requiring `attach` or `download-link` to also be
configured (an automation with only `notify` and neither other mode SHALL be
rejected at editor-save time as incomplete).

#### Scenario: Attach mode writes a file reference to the object

- **WHEN** a `generateDocument` action with `output: "attach"` fires for an
  object
- **THEN** the generated document is written to Nextcloud Files
- **AND** the object's attachment field is set to `{ "ref": "<fileId>" }`

#### Scenario: Download-link mode does not persist a file

- **WHEN** a `generateDocument` action with `output: "download-link"` fires
- **THEN** a short-lived signed download URL is generated
- **AND** no file is written to Nextcloud Files

#### Scenario: Notify-only with no attach or download-link is rejected

- **WHEN** an editor configures a `generateDocument` action with only
  `output: "notify"` and no attach/download-link mode
- **THEN** the save is rejected as an incomplete configuration

### Requirement: Object data maps to template variables exclusively via dataRefs

`DocumentGenerationService` SHALL pass the triggering object as exactly one
`dataRefs` entry (`{register, schema, id}`) to Docudesk's
`correspondence/generate` call. OpenBuild SHALL NOT flatten, transform, or
duplicate the object's field data before sending it — Docudesk's own
`DataResolverService` performs the object-to-template-variable resolution,
identical to the existing manual-generation path in
`docudesk-document-templates`.

#### Scenario: Triggering object is passed as a single dataRef

- **WHEN** an object matching a `generateDocument` trigger fires
- **THEN** the Docudesk call's `dataRefs` contains exactly one entry naming
  that object's register, schema, and id
- **AND** no flattened copy of the object's fields is constructed by
  OpenBuild

### Requirement: Template picker reuses the existing Docudesk-template-list component

`AutomationEditDialog`'s `generateDocument` action editor SHALL populate its
template picker from the same `GET /apps/docudesk/api/templates` call and
component `docudesk-document-templates`'s Documents-section builder UI
already uses — a single shared implementation, not a second template-list
fetch/render.

#### Scenario: Template list is shared, not duplicated

- **WHEN** the automation editor's `generateDocument` template picker and the
  Documents section's template picker are both open
- **THEN** both render from the same underlying template-list fetch/component
