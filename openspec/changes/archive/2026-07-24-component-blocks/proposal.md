---
kind: code
---

## Why

Every widget and page section a citizen developer configures in OpenBuild today is one-shot — closing the editor throws away the specific configuration (bindings, filters, layout) unless it is rebuilt by hand in every page and every app that needs it. Reusable blocks/templates are the third-most-demanded builder feature market-wide (Appsmith#1911 28↑, Budibase#18726/#18727, ToolJet#4850) and every vendor ships some form of it. OpenBuild already has the two primitives a block needs — stable `widgetEntry.id` and manifest-fragment shape (from the shipped delta-merge foundation) and the `ApplicationTemplate` de-namespacing pattern from `save-as-template` (the exact inverse operation this change needs on insert instead of capture) — so this is composition, not new architecture.

## What Changes

- **Save as block**: from the page designer, any configured widget or a selected page section can be saved as a named `ComponentBlock` — a manifest fragment (the widget/section subtree, keyed by its stable `widgetEntry.id`) plus metadata (`name`, `description`, `category`, `schemaDependencies` — the schema slugs the fragment's data bindings reference).
- **Block library browser**: a panel in the page designer listing saved blocks (own + org-shared), filterable by category, with a preview. Insert deep-copies the fragment into the target page.
- **Schema-reference remap on insert**: when a block's `schemaDependencies` do not match the target app's schema slugs 1:1 (different app, or same app but the schema was renamed), the insert flow prompts the developer to remap each dependency to a schema in the target app — reusing the same de-namespace/rename mapping logic `save-as-template`'s clone flow already applies to companion schemas, run in reverse (block insert, not app clone).
- **Cross-page and cross-app reuse**: blocks are stored org-scoped (not app-scoped), so any block is insertable into any app/page the developer has editor rights on.
- **Export/import as JSON**: a block can be downloaded as a standalone JSON file and re-imported (into the same or a different Nextcloud instance), following the existing template export/import shape.
- **Template-catalogue surface**: the existing template gallery gains a "Blocks" listing alongside full-app templates — list + preview, reusing `openbuild-template-catalogue`'s gallery UI patterns.
- **No live-sync in v1 (explicit non-goal)**: inserting a block is always a deep copy. Editing the source block after insertion never propagates to already-inserted copies, and editing an inserted copy never writes back to the source block. This is documented, not implied.

## Capabilities

### New Capabilities
- `component-blocks`: the `ComponentBlock` schema and save/list/insert/delete lifecycle, the schema-reference remap-on-insert flow, export/import as JSON, and the block-library browser panel in the page designer.

### Modified Capabilities
- `openbuild-template-catalogue`: the gallery view gains a "Blocks" tab/filter alongside the existing full-app template listing, reusing the existing category-filtered gallery UI. (Delta spec at `specs/openbuild-template-catalogue/spec.md`.)

## Impact

- **Schema:** `lib/Settings/openbuild_register.json` — new `ComponentBlock` schema (openbuild register namespace): `uuid`, `slug`, `name`, `description`, `category`, `schemaDependencies[]`, `fragment` (the manifest subtree), `sourceApplicationSlug` (provenance, not a live link), `createdBy`.
- **Backend:** minimal — blocks are OR objects, saved/listed/deleted via the standard authenticated objects API (ADR-022, no new CRUD controller). Export/import reuse the existing template export/import JSON shape and validation.
- **Frontend:** new `src/dialogs/SaveBlockDialog.vue` (capture: name/description/category, dependency summary — mirrors `SaveAsTemplateDialog`), new block-library panel in `PageDesigner.vue`/`PageDesignerHost.vue`, new `src/dialogs/BlockRemapDialog.vue` (schema-dependency remap on insert, mirrors the clone-time namespace-remap logic).
- **RBAC:** a block is saved/deleted by its creator or an Application owner/editor on the source app; insert requires editor rights on the target app (existing `openbuild-rbac` posture, no new role).
- **Non-goal:** live-sync/inheritance between a block and its insertions — explicitly out of scope for v1.
