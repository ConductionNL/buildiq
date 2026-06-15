---
kind: code
depends_on: []
chain:
  - docudesk-document-templates
---

## Why

OpenBuild's app-store summary promises composing virtual apps from "registers, connectors, workflows, and **documents**"; the description names "Docudesk-documenten" and the README says documents are "consumed via template attachments". The 2026-06-11 feature re-evaluation found this third ecosystem-composition leg has no spec and no in-flight change (the only repo hit is a context-brief aside in `business-rules-engine`). This change closes it, completing the trio with the already-authored siblings `openconnector-api-sources` (data in) and `procest-workflow-attachments` (handling out).

The concrete user story: the "Kapvergunning aanvragen" virtual app stores applications as OR objects. The municipality needs to produce the confirmation letter, the besluitbrief, and the publication notice — branded, templated documents rendered from the object's data. Today the builder has no way to say "objects of schema X can generate a document from Docudesk template Y with one click". Authoring the template (layout, huisstijl, placeholders) stays entirely in Docudesk; openbuild only attaches, triggers, and downloads.

Docudesk already exposes exactly the API surface this needs, all `@NoAdminRequired` (verified in `docudesk/appinfo/routes.php` + controllers 2026-06-11):

- `GET /apps/docudesk/api/templates` — list templates for the attach picker; `GET .../templates/{id}` — detail.
- `POST /apps/docudesk/api/templates/{id}/preview` — render a preview of a specific template.
- `POST /apps/docudesk/api/correspondence/generate` — generate a document from `templateId` + **`dataRefs` (array of `{register, schema, id}` references)** + `options {format, huisstijlId, caseReference}` + `filename`, returning the document as a download. The `dataRefs` shape is purpose-built for OR objects — the contract could not fit openbuild better.
- `POST .../correspondence/generate/batch` + `GET .../correspondence/jobs/{jobId}` — batch path (deferred to v2, see Open Questions).

OpenBuild stays a pure API consumer of that existing public surface (ADR-022 posture: no document rendering, no template logic, zero new PHP).

## What Changes

- **NEW** Manifest v2 document-attachment declaration: a `documents[]` array carried in the manifest's `runtime` block (alongside the sibling `workflows[]`), each entry `{ id, schema, templateId, templateName, label, format?, filenameTemplate? }`. Multiple attachments per schema are allowed (an object commonly has several letters), unlike the sibling's one-case-per-schema rule. Declarative only; validated app-side (canonical-schema codification filed as a `nextcloud-vue` follow-up, riding `additionalProperties: true` like both siblings).
- **NEW** `src/dialogs/DocumentTemplateAttachmentDialog.vue` — builder UI (standalone dialog per the modal-isolation rule) to attach a Docudesk template to a virtual-app schema: template picker fed by Docudesk's template list, target-schema picker, action label, output format, optional filename template with `{{objectProperty}}` placeholders, and a "preview with sample data" affordance using Docudesk's preview endpoint.
- **MODIFIED** Application-detail/designer surface — a "Documents" section listing the app's attachments with add/edit/detach actions (same section host pattern as the sibling's Workflows section).
- **NEW** `src/composables/useDocudeskDocument.js` — runtime integration: a user-invoked **Generate** action that POSTs `correspondence/generate` with the attachment's `templateId`, a single `dataRefs` entry referencing the current OR object (register/schema/id resolved from the app's active-version data context), rendered filename, and format — then hands the returned document to the browser as a download. Failure-tolerant: errors surface as a non-blocking toast, never navigate away or mutate the object.
- **NEW** `src/components/runtime/DocumentActions.vue` — detail-page surface (registered in the virtual-app runtime's component registry as `docudesk-document-actions`, referencable from `sidebarProps.tabs` or as a detail action group) listing each attachment for the object's schema as a labelled generate button with per-action busy/error states.
- **NEW** Capability check + graceful absence: `"docudesk"` auto-managed in the manifest `dependencies[]` when ≥1 attachment exists (shared `ensureDependency(appId)` utility with the siblings); designer soft-check disables the attach action with a hint when Docudesk is absent; runtime hides generate actions and degrades per-surface rather than blanking the app.
- **NO** new openbuild PHP controllers or routes; no Docudesk code changes inside this change; openbuild never stores rendered documents, template content, or huisstijl assets — only template id/name snapshots in the manifest.

### Capabilities

#### New Capabilities

- `docudesk-document-templates`: the manifest `documents[]` declaration, the DocumentTemplateAttachmentDialog builder UI with preview, the `useDocudeskDocument` runtime generate-and-download path, the DocumentActions detail surface, and capability-checked graceful absence.

#### Modified Capabilities

- `openbuild-page-designer`: the application-detail/designer surface gains the Documents attachments section; detail pages gain the `docudesk-document-actions` referencable surface. Existing flows untouched; everything is additive and hidden when no attachment exists.

## Impact

- **New frontend code**: ~950 LOC (dialog ~300, actions surface ~200, composable ~200, attachments section ~150, validation ~100) + Vitest suites. Zero new PHP.
- **Integration contract (pinned to Docudesk's existing public surface)** — openbuild calls exactly:
  1. `GET /apps/docudesk/api/templates` — attach picker (name + id; loading/error states).
  2. `GET /apps/docudesk/api/templates/{id}` — refresh the name snapshot on edit + existence check.
  3. `POST /apps/docudesk/api/templates/{id}/preview` — builder-side preview with sample data.
  4. `POST /apps/docudesk/api/correspondence/generate` — runtime generation: `{ templateId, dataRefs: [{register, schema, id}], options: { format }, filename }` → document download.
  All calls ride the caller's NC session; Docudesk's own authorization applies. Any payload-shape mismatch discovered during apply is fixed on the openbuild side or filed against Docudesk — never worked around by importing Docudesk internals.
- **Explicit Docudesk dependencies (flagged, NOT assumed)**:
  1. **Placeholder/field metadata per template** — the attach dialog would ideally show which placeholders a template expects so the builder can check the schema provides them. No public "template placeholders" read was found beyond the template object itself; during apply, verify whether `GET api/templates/{id}` exposes placeholder metadata, and if not, file a Codeberg issue against `Conduction/docudesk` requesting it. v1 ships without placeholder validation (generation errors surface at preview/generate time).
  2. **Supported `options.format` value set** — the generate endpoint accepts `format` but the closed value set (pdf/docx/…) is not documented as a contract; pinned during apply via the Newman collection and recorded in the dialog's format picker; the same issue asks Docudesk to document it.
- **Security**: no credentials stored; openbuild persists only template UUID/name + labels in the manifest. Whether a given user may use a template or read the referenced object's data remains Docudesk's call — a 403 on generate renders a "no access" toast distinct from generic failure. Rendered documents go straight to the user's download; openbuild never persists them.
- **No breaking changes** — purely additive; apps without attachments serialize byte-identical manifests.

## Open Questions

- **OQ-1**: Batch generation ("generate for all selected objects" on index pages) via `correspondence/generate/batch` + job polling — deferred to v2; v1 is single-object, user-invoked.
- **OQ-2**: Automatic generation triggers (on-create / on-status-change, e.g. auto-produce the confirmation letter) — deferred; would compose with the procest sibling's trigger model and the fleet notification engine.
- **OQ-3**: Saving generated documents to a Files folder / attaching them to the OR object (instead of direct download) — deferred; needs a fleet decision on document-object linkage (content-types-as-leaves: files belong in NC Files).
- **OQ-4**: Huisstijl coupling — `options.huisstijlId` exists on the generate endpoint; should the attachment pin a huisstijl, or should it follow the app's nldesign theme (sibling change `nldesign-theme-selection`)? v1 omits `huisstijlId` (Docudesk's template default applies).
