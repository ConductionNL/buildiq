---
kind: code
---

## Why

Row-level security is a competitive baseline OpenBuild currently has no
authoring surface for. Market analysis (2026-07-11): Saltcorn ships row
ownership + inheritance + user groups for free; NocoBase ships Data Scope
(all / own / condition) for free; Budibase and Appsmith lack it; ToolJet
paywalls it at $3000/mo; NocoDB and Baserow paywall it. The enforcement
layer largely exists in our stack — OpenRegister evaluates per-operation
group ACLs from the schema-level `authorization` block (verified live on
the Pet Store demo: `medicalRecord.authorization.read = ["vets"]` hides
rows from non-vets, admin bypasses), and the in-flight
`runtime-group-scoped-access` change wires group-scoped menu/page
visibility on top of it, explicitly naming `schema.authorization` as the
authoritative object-level control.

What is missing is the **authoring surface**. Today an author can only get
row-level scoping by hand-editing schema JSON outside the Schema Designer.
Worse, the Schema Designer actively fights them: `composeSchemaBody()` in
`src/views/SchemaDesigner.vue` rebuilds the schema body from the staged
editor model and does **not** carry the `authorization` block through
(verified against HEAD: `bodyToStaged()` never reads it,
`composeSchemaBody()` never writes it, and the only `authorization`
mentions in `src/` are unrelated) — so saving any schema in the designer
silently **strips** an authorization block that was set elsewhere. This is
a pre-existing data-loss bug this change also fixes.

## What Changes

- **New "Access" sub-editor in the Schema Designer.**
  `src/components/schema-editor/AccessEditor.vue`, mounted by
  `src/views/SchemaDesigner.vue` alongside `FieldEditor` /
  `LifecycleEditor` / `RelationEditor`, following the same pattern: staged
  copy owned by the view, sub-editor emits `update:access`, exported
  `accessToEditor` / `editorToAccess` converter helpers.
- **Per-operation scopes.** For each of read / create / update / delete
  the author picks one scope kind:
  - *Everyone with app access* — the operation key is omitted from
    `authorization` (OR default).
  - *Specific groups* — NC group list, compiled to
    `authorization.<op> = ["<gid>", ...]` (supported by current OR,
    verified).
  - *Own records (creator)* — compiled to the `@creator` sentinel inside
    the operation list (`authorization.<op> = ["@creator"]`). **Upstream
    OR primitive — capability-gated, see below.**
  - *Condition* — field-value match against user context (e.g.
    `assignee` equals current user id), compiled to
    `authorization.conditions.<op>` (see design.md for the shape).
    **Upstream OR primitive — capability-gated, see below.**
- **Capability detection.** A new composable
  `src/composables/useOrAccessCapabilities.js` reads OR's advertised
  authorization scope kinds via `@nextcloud/capabilities`
  (`openregister.authorization.scopes`). When absent (every current OR
  release), the baseline is `['group']` and the editor offers only
  *Everyone* and *Specific groups* — so this change is shippable against
  current OR with the own/condition options hidden, not broken.
- **Lossless round-trip + strip-bug fix.** `bodyToStaged()` /
  `composeSchemaBody()` carry the `authorization` block through. Entries
  the editor cannot represent (e.g. a hand-authored `@creator` on an
  instance whose OR does not advertise it) are preserved verbatim and
  shown read-only — never dropped on save.
- **Author lock-out warning.** When the staged read scope would make the
  schema's records invisible to the author themself (group scope not
  intersecting the author's own groups, and the author is not an NC
  admin), the designer shows a non-blocking warning before save.
- **Scope summary badges.** `SchemaListPanel.vue` rows show a compact
  "Restricted" badge (with per-operation detail) for schemas carrying an
  `authorization` block, so scoped schemas are visible at a glance.
- **Version-scoped like all schema edits.** Scopes live in the schema
  body inside the per-app/per-version register (`registerSlugForApp`),
  edited under `?_version=` exactly like fields/lifecycle/relations —
  no new persistence surface.
- **Owner-only on the production version.** When the active version is
  the Application's `productionVersion`, the Access sub-editor is
  read-only for editors; only owners (and NC admins) may change live
  enforcement, mirroring the owner-only release rule (REQ-OBRBAC-004 /
  `ApplicationVersionOwnerGuard`).

## Upstream leaf requirements (openregister repo — NOT part of this change)

This change is authoring-only and shippable against current OR. The
following primitives are **explicit upstream dependencies** to be filed as
leaf requirements on the openregister repo; the editor exposes them only
after OR advertises them:

1. **`@creator` sentinel** in `authorization.<op>` lists — "own records"
   enforcement (row visible/writable only to the object's creator).
2. **Condition-based scopes** — `authorization.conditions.<op>` with
   user-context tokens (`@user.uid`, `@user.groups`) evaluated
   server-side per row.
3. **Capability advertisement** —
   `openregister.authorization.scopes: ["group", "creator", "condition"]`
   in OR's Nextcloud capabilities document, so leaf apps can
   feature-detect instead of version-sniffing.

## Capabilities

### New Capabilities
- **data-scopes-authoring** — the Access sub-editor, compilation to OR
  `authorization` metadata, capability-gated scope kinds, lock-out
  warning, list badges, version scoping, and production owner gating.

### Referenced (no change here)
- OpenRegister schema RBAC (`schema.authorization`) — the authoritative
  enforcement layer (per-operation NC group lists, admin bypass).
- `runtime-group-scoped-access` — consumes the same `authorization`
  metadata for its security boundary; this change is the surface that
  authors it. Vocabulary (`group:<gid>` permission strings, "menu hiding
  is UX, `schema.authorization` is authoritative") is shared, not
  duplicated: that change gates *navigation*, this change authors the
  *object ACL*.

## Impact

- Files touched: `src/views/SchemaDesigner.vue`,
  `src/components/schema-editor/AccessEditor.vue` (new),
  `src/components/schema-editor/SchemaListPanel.vue`,
  `src/composables/useOrAccessCapabilities.js` (new), vitest specs,
  one new Playwright spec, `docs/`.
- No PHP/backend changes: scopes compile into the schema body saved
  through the existing `useSchemasStore` PUT path; enforcement is OR's.
- Schemas without an `authorization` block render and save unchanged;
  the only behavioural delta for existing users is that the designer
  stops stripping `authorization` on save (bug fix).
- Closes the free-tier gap against Saltcorn/NocoBase row-level security.
