## MODIFIED Requirements

### Requirement: REQ-DDT-006 Integration contract pinned to Docudesk's existing public API surface

OpenBuild's Docudesk integration SHALL call exactly the following existing Docudesk routes and no others: `GET /apps/docudesk/api/templates` (template picker), `GET /apps/docudesk/api/templates/{id}` (snapshot refresh + existence check), `POST /apps/docudesk/api/templates/{id}/preview` (builder preview), and `POST /apps/docudesk/api/correspondence/generate` (runtime generation, both interactively from an end user via `docudesk-document-templates` and automation-triggered via `automation-document-action`). Every call SHALL use a Nextcloud session: the interactive caller's own session for end-user-invoked generation, or the Application owner's session, impersonated for the duration of exactly one internal call via the existing `JobOwnerImpersonator` pattern, for automation-triggered generation — there is no third calling shape. openbuild SHALL NOT bypass or re-implement Docudesk's authorization, SHALL NOT import Docudesk PHP classes or read its tables, SHALL NOT persist rendered documents, template content, or huisstijl assets beyond the one file an `attach`-mode automation action explicitly writes to Nextcloud Files (per `automation-document-action`), and SHALL NOT modify any Docudesk data. The contract — including the `dataRefs` request shape and the pinned `options.format` value set — SHALL be pinned by a Newman collection asserting each route's request/response shape against the dev instance, so Docudesk-side drift fails CI rather than production.

#### Scenario: Contract surface is closed

<!-- @e2e exclude static source-tree assertion + Newman contract scenario; pinned by tests/integration/openbuild-docudesk-documents.postman_collection.json, not a browser flow. -->

- **WHEN** the openbuild source tree is scanned for `/apps/docudesk/` references
- **THEN** every call target is one of the four listed routes
- **AND** no Docudesk PHP namespace is imported anywhere in openbuild, including `DocumentGenerationService`

#### Scenario: Newman pins the generate contract

<!-- @e2e exclude Newman API-contract scenario; pinned by tests/integration/openbuild-docudesk-documents.postman_collection.json, not a browser flow. -->

- **GIVEN** the Newman collection's generate request with a seeded template and a seeded OR object reference
- **WHEN** the collection runs against the dev instance
- **THEN** `correspondence/generate` returns the document response for `dataRefs: [{ register, schema, id }]`
- **AND** an unknown `templateId` returns the documented 4xx error shape

#### Scenario: Automation-triggered call uses the owner-impersonated session, not a PHP import

- **WHEN** an automation's `generateDocument` action fires
- **THEN** `DocumentGenerationService` issues an HTTP request to
  `POST /apps/docudesk/api/correspondence/generate` with the Application
  owner impersonated as the active NC session for that call
- **AND** no `OCA\DocuDesk\*` class is imported or instantiated directly
