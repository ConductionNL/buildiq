## Context

OpenBuild has two directly reusable precedents for this change. First, the shipped delta-merge foundation (`unify-apps-with-app-type`) gives every `widgetEntry` and page a stable `id`, and the manifest fragment shape a keyed subtree can be extracted from cleanly. Second, `save-as-template`'s capture flow already solves "de-namespace a schema reference so it survives being moved to a different app" — its `deNamespaceSlug` capture-time logic and the clone-time REQ-OBTC-005 prefixing are exact mirror-image operations of what block insertion needs (a fragment captured under one app's schema slugs must be remapped when inserted into an app whose schemas are named differently).

Constraint: ADR-022 (no own DB tables) — a block is an OR object in the `openbuild` register. Constraint: ADR-031 — block save/insert is a structural manifest-fragment operation (cross-object, imperative), not declarative schema metadata.

## Goals / Non-Goals

**Goals:**
- Any configured widget or a selected contiguous page section can be captured as a named, reusable fragment.
- A block is insertable into any page/app the developer has editor rights on, with schema-dependency mismatches resolved by an explicit remap prompt (never a silent guess).
- Blocks are listable, previewable, exportable, importable, and appear in the template catalogue alongside full-app templates.
- Insert is always a deep copy — new `widgetEntry.id`s are minted for the inserted subtree so two insertions of the same block never collide on id.

**Non-Goals:**
- Live-sync / inheritance between a block and its insertions (explicit, documented — the proposal's non-goal). No "update all instances of this block" feature in v1.
- Nested blocks (a block containing another block reference) — v1 fragments are captured as plain manifest JSON, not block references; nesting is deferred.
- Versioning a block's own history — a block edit (via re-save) replaces its `fragment` in place; no version chain (unlike `ApplicationVersion`).

## Decisions

### D1 — A block is an OR object with a captured fragment, mirroring `ApplicationTemplate`'s shape
**Choice:** `ComponentBlock` schema: `uuid`, `slug`, `name`, `description`, `category`, `schemaDependencies` (array of the schema slugs the fragment's data bindings/pageConfig reference, de-namespaced exactly as `save-as-template`'s `deNamespaceSlug` does for companion schemas), `fragment` (the captured widget/section manifest subtree, keyed by its original `widgetEntry.id`/`page.id`), `sourceApplicationSlug` (provenance only — never a live reference), `createdBy`.
**Why:** Reuses a proven shape (`ApplicationTemplate`) rather than inventing a new capture/de-namespace algorithm; `deNamespaceSlug` is already unit-tested and its round-trip property (save→clone = clean rename, no prefix stacking) is exactly what save-block→insert needs.
**Alternative considered:** Store blocks as manifest page-config entries (like a draft `pages[]` array not attached to any real page). Rejected — blocks must be listable/searchable/org-shared independent of any one Application's manifest; an OR object with its own schema is the natural fit, matching `ApplicationTemplate`.

### D2 — Insert deep-copies the fragment and mints new ids; remap runs only when dependencies mismatch
**Choice:** `BlockInsertService` (frontend-only, pure function mirroring `captureTemplate`/`cloneTemplate`'s existing pure-function pattern) takes the target page's manifest + the block's `fragment`, generates fresh `widgetEntry.id`/`page.id` values for every node in the copied subtree, and rewrites `schemaDependencies` references to the target app's schema slugs. If a `schemaDependencies` entry has no exact-slug match in the target app, `BlockRemapDialog` opens and requires an explicit developer choice (map to an existing schema, or leave unresolved and the corresponding binding renders a "needs remap" placeholder — never silently drops the field).
**Why:** New ids prevent id collisions across two insertions of the same block on one page (a keyed-merge foundation problem if left unaddressed); explicit remap (not auto-guess) matches `save-as-template`'s existing pattern of listing/flagging ambiguity rather than silently mangling references (REQ-SAT-002's shared-schema flag is the direct precedent).
**Alternative considered:** Auto-remap by matching schema `title` similarity. Rejected — silent heuristic remapping is exactly the class of bug `save-as-template`'s hard-block-on-collision design deliberately avoids; an explicit prompt costs one extra click and eliminates a wrong-binding failure mode.

### D3 — Blocks are org-scoped, not app-scoped, via OR's standard `organisation` field
**Choice:** `ComponentBlock` uses OR's existing `organisation` scoping (ADR-022) exactly like `Application` and `ApplicationTemplate` — a block saved by any editor in an organisation is visible to every other editor in that organisation, across every app.
**Why:** "Usable across pages AND across apps" (proposal scope (c)) requires this by definition; reusing the existing organisation-scope mechanism needs no new RBAC concept.
**Alternative considered:** App-scoped blocks with an explicit "share to org" toggle. Rejected for v1 — adds a visibility state machine for a feature whose entire value proposition is frictionless reuse; org-scope-by-default matches how `ApplicationTemplate` already behaves.

### D4 — Export/import reuses the template JSON export shape
**Choice:** A block exports as `{ schemaVersion, kind: "component-block", block: {...ComponentBlock fields except uuid/createdBy} }`, downloaded via the browser exactly like the existing template/app export flow. Import validates the shape, strips any embedded `uuid`, and creates a new `ComponentBlock` (or opens the remap dialog immediately if the importing organisation's schemas don't match `schemaDependencies`).
**Why:** Reuses the existing export/import plumbing (`ExportService`/`ExportsController` download pattern) rather than inventing a second file format.
**Alternative considered:** Bundle blocks inside a full app export. Rejected — a block must be shareable independent of any one app export, per the proposal's explicit "export/import block as JSON" scope item.

### Declarative-vs-imperative decision (ADR-031)
The `ComponentBlock` schema fields are declarative OR properties. The fragment capture (extracting a keyed subtree), the deep-copy-with-new-ids insert, and the schema-dependency remap are imperative — justified under ADR-031's structural-transform exception, the same justification already accepted for `save-as-template`'s capture/clone logic (the direct precedent this change reuses).

## Risks / Trade-offs

- **A captured fragment references a widget type or config shape that changes upstream (nc-vue widget schema evolves)** → the fragment is validated against the current widget-config schema at insert time (not just at save time); an incompatible block surfaces a clear "this block is out of date" error rather than inserting broken config.
- **Org-wide visibility means any editor can see any other app's captured block (including its bindings' field names)** → acceptable per D3 (matches existing `ApplicationTemplate` visibility); no object *data* is ever captured (mirrors `save-as-template`'s "never captures rows" guarantee), only structure.
- **Two insertions of the same block on the same page must not collide on id** → D2's fresh-id-per-insert guarantees this by construction; unit-tested against the same id-collision property `save-as-template`'s clone flow already tests for companion schemas.
- **Remap dialog friction on every cross-app insert** → only triggers when `schemaDependencies` don't exact-match; a block inserted back into its own source app (the common case — reusing a section within one app) never sees the dialog.

## Migration Plan

1. Add the `ComponentBlock` schema to `lib/Settings/openbuild_register.json` (additive).
2. Implement `SaveBlockDialog.vue` (capture) reusing `SaveAsTemplateDialog`'s de-namespace logic against a single widget/section subtree instead of a whole manifest.
3. Implement the block-library panel in `PageDesigner.vue`/`PageDesignerHost.vue` (list, filter, preview).
4. Implement `BlockInsertService` (deep-copy + id-mint) and `BlockRemapDialog.vue`.
5. Wire export/import through the existing `ExportService`/`ExportsController` download pattern.
6. Add the "Blocks" tab/filter to `TemplateGallery.vue`.
7. No data migration — fully additive, zero impact until a developer saves their first block.

**Rollback:** Remove the block-library panel and "Blocks" gallery tab; existing `ComponentBlock` OR objects become inert (harmless orphan records, same rollback shape as `ShareToken` in `public-forms-runtime`).

## Open Questions

- Should a page-section capture (multiple widgets + layout) be scoped to v1, or should v1 ship single-widget blocks only and defer section capture? Lean: include both — the proposal explicitly scopes "any configured widget **or** page section," and the fragment/remap machinery is identical for either (a section is just a larger keyed subtree).
- Does the block library panel live inside `PageDesigner.vue` as a new tab, or as a separate `NcAppSidebar` panel? Lean: `NcAppSidebar` panel (consistent with how the schema/widget pickers already present in the page designer), decided at implementation time against the current designer layout.

## Seed Data

Example `ComponentBlock` (single-widget capture):

```json
{
  "uuid": "00000000-0000-0000-0000-000000000000",
  "slug": "status-badge-widget",
  "name": "Status badge",
  "description": "Colour-coded status badge widget bound to a record's status field.",
  "category": "display",
  "schemaDependencies": ["permit-application"],
  "sourceApplicationSlug": "vergunning-app",
  "createdBy": "YOUR_TOKEN_HERE",
  "fragment": {
    "type": "widget",
    "widgetType": "status-badge",
    "id": "00000000-0000-0000-0000-000000000000",
    "config": {
      "schema": "permit-application",
      "field": "status",
      "colorMap": { "submitted": "info", "approved": "success", "rejected": "error" }
    }
  }
}
```

Example page-section capture (multiple widgets):

```json
{
  "uuid": "00000000-0000-0000-0000-000000000000",
  "slug": "applicant-summary-section",
  "name": "Applicant summary section",
  "description": "Name, address and status badge grouped in a two-column layout.",
  "category": "layout",
  "schemaDependencies": ["permit-application"],
  "sourceApplicationSlug": "vergunning-app",
  "createdBy": "YOUR_TOKEN_HERE",
  "fragment": {
    "type": "section",
    "id": "00000000-0000-0000-0000-000000000000",
    "layout": "two-column",
    "widgets": [
      { "type": "widget", "widgetType": "field-display", "id": "00000000-0000-0000-0000-000000000000", "config": { "schema": "permit-application", "field": "applicantName" } },
      { "type": "widget", "widgetType": "status-badge", "id": "00000000-0000-0000-0000-000000000000", "config": { "schema": "permit-application", "field": "status" } }
    ]
  }
}
```
