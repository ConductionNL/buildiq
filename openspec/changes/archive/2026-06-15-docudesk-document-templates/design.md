## Context

A virtual app's objects routinely need to become *documents*: confirmation letters, besluiten, publication notices — branded, versioned, archivable artifacts. That machinery (template authoring, placeholder rendering, huisstijl, PDF/A, locking, template versioning) is Docudesk's whole job, exposed under `/apps/docudesk/api/...` with `@NoAdminRequired` routes. Rebuilding any of it in openbuild — even a "simple letter" renderer — would violate ADR-022 and the leaves-not-app-schemas rule. The right shape is an *attachment*, mirroring the procest sibling: the virtual app declares "objects of schema X can generate documents from template Y", openbuild triggers generation with an OR object reference and hands the result to the user, and every template concern stays in Docudesk.

The decisive contract detail: Docudesk's `correspondence/generate` already takes `dataRefs: [{register, schema, id}]` — it dereferences OR objects itself. OpenBuild therefore never serializes, maps, or flattens object data into the request; it passes a reference and Docudesk reads the object server-side under its own authorization.

The integration must survive the same three awkward realities as the sibling: Docudesk may be absent (degrade, don't break); generation may fail (never corrupt or block the object flows); and the user may lack rights on the template or the data (distinguish "no access" from "broken").

## Goals / Non-Goals

**Goals:**

- Declarative, manifest-carried attachment of Docudesk templates to virtual-app schemas, several per schema.
- One-click, user-invoked document generation from an object's detail surface, delivered as a browser download.
- Builder-side template preview before committing an attachment.
- Strict consumption of Docudesk's **existing public API surface**; anything missing (placeholder metadata, documented format enum) is a filed dependency, not an assumption.
- Graceful absence of Docudesk at design time and runtime.

**Non-Goals:**

- **Template authoring/editing in openbuild** — templates are created, versioned, locked, and huisstijl'd in Docudesk; the attach picker lists what exists.
- **Automatic triggers** — generation is user-invoked in v1; on-create/on-status auto-generation is OQ-2.
- **Batch generation** — OQ-1 (v2, via the existing batch + job-status endpoints).
- **Persisting generated documents** (Files folders, OR object attachment, dossiers) — OQ-3; v1 streams to download only.
- **Signing, anonymization, print jobs** — adjacent Docudesk surfaces deliberately out of scope.

## Decisions

### Decision 1 — Attachment lives in the manifest `runtime` block as `documents[]`, multiple per schema

`runtime.documents[] = { id, schema, templateId, templateName, label, format?, filenameTemplate? }`.

**Rationale**: same home and same reasoning as the sibling's `workflows[]` (app-composition state that versions, promotes, and exports with the app; rides `additionalProperties: true` until the canonical-schema follow-up). The deliberate divergence is cardinality: a case type is an exclusive handling regime (one per schema), but documents are plural by nature — one kapaanvraag yields a confirmation, a besluit, and a notice. Uniqueness is therefore enforced on `id` and on the *(schema, label)* pair (two same-labelled buttons would be indistinguishable), not on schema.

**Alternatives considered**:
- *Page-level action config instead of schema-level* — rejected: the same object is reachable from multiple detail/index surfaces; schema-level declaration keeps every surface consistent and mirrors the sibling.
- *Storing a full field-mapping in the manifest* — rejected: `dataRefs` delegates data resolution to Docudesk; a mapping layer would duplicate Docudesk's placeholder system and drift from it.

### Decision 2 — Frontend-direct calls; generation passes a reference, never a payload; no openbuild PHP

`useDocudeskDocument` POSTs `correspondence/generate` with `dataRefs: [{ register, schema, id }]` resolved from the runtime's active data context (the version-routed register the app is already reading).

**Rationale**: mirrors both siblings' Decision 2 (a PHP forwarder is a redundant pass-through and a second auth surface). Passing a reference instead of object data means: no stale-data race (Docudesk reads the current object), no data flattening logic in openbuild, and Docudesk's authorization governs *both* template use and data access in one place. Trade-off: the generating user needs read access to the object in Docudesk's eyes — true by construction, since they are viewing it.

### Decision 3 — Generation is user-invoked from a registered detail surface

`DocumentActions.vue` registers as `docudesk-document-actions` in the runtime component registry, referencable from `sidebarProps.tabs` or as a detail action group; each attachment renders one labelled button with its own busy/error state.

**Rationale**: reuses the detail page's existing extension point exactly like the sibling's status panel (ADR-036 kind-agnostic resolver accepts non-page registry components). User-invoked keeps v1's failure surface trivial — a failed generate is a toast and a retry click, with no reconciliation problem (contrast the sibling's case-start, which is a side effect of creation and needed `_zoek` repair). The attach dialog offers the same one-click "add to detail page" convenience as the sibling.

### Decision 4 — Filename is a template rendered client-side; format is a pinned pass-through

`filenameTemplate` interpolates `{{objectProperty}}` placeholders against the current object (safe interpolation, missing → empty, no eval), defaulting to `<label>-<objectUuid>.<ext>`; `format` is passed verbatim in `options.format`, with the dialog's picker limited to the value set pinned during apply (flagged Docudesk dependency for documentation).

**Rationale**: filename is presentation, cheap and safe client-side; format is Docudesk's contract, so openbuild pins rather than invents the enum — per the no-invented-API rule.

### Decision 5 — Builder preview rides Docudesk's preview endpoint

The attach dialog's "Preview" calls `POST api/templates/{id}/preview` and shows the result inline (or in a new tab for binary responses), before the attachment is saved.

**Rationale**: catches "wrong template picked" and placeholder mismatches at design time without any openbuild rendering code; the exact request/response shape (sample-data body vs. bare preview) is verified against the deployed Docudesk during apply and pinned in Newman.

## Risks / Trade-offs

- **No placeholder validation in v1**: a template expecting `{{aanvrager}}` attached to a schema without that property fails at preview/generate time, not at attach time. Mitigated by the in-dialog preview affordance + the flagged Docudesk issue for placeholder metadata; revisit once the metadata read exists.
- **Download-only delivery**: the generated letter is not archived next to the object (OQ-3). Acceptable for v1 — Docudesk's own views keep their archive behaviour; openbuild adds convenience, not the system of record.
- **Format enum drift**: `options.format` values are pinned by Newman, so a Docudesk-side change fails CI rather than producing broken buttons.
- **Large documents / slow rendering**: generation is synchronous on the generate route; the per-button busy state plus a generous client timeout covers normal letters. Batch/async (job queue) is the documented v2 path via the existing batch endpoints.
- **Authorization mismatch**: the viewing user may lack template rights in Docudesk. Surfaces as the distinct 403 "no access" toast; the attach dialog documents that generation runs *as the clicking user*.
