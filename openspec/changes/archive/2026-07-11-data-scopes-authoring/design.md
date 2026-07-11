# Design — data scopes authoring

## Context

- `src/views/SchemaDesigner.vue` owns a staged copy of the schema being
  edited; sub-editors (`FieldEditor`, `LifecycleEditor`, `RelationEditor`,
  `WidgetEditor`) emit `update:*` events; `composeSchemaBody()` composes
  the canonical JSON Schema body (including the `x-openregister-*`
  blocks) and Save PUTs via `useSchemasStore(appSlug, versionSlug)` into
  the per-app/per-version register (`registerSlugForApp`).
- Verified against HEAD: the designer neither reads nor writes the
  schema-level `authorization` block — `bodyToStaged()` drops it and
  `composeSchemaBody()` rebuilds the body without it, so a designer save
  strips any externally-set authorization (pre-existing bug, fixed here).
- The OR `authorization` contract as openbuild uses it today (verified in
  `lib/Settings/openbuild_register.json` seeds and the live Pet Store
  check documented in `openspec/changes/runtime-group-scoped-access/`):
  a top-level schema key with per-operation NC group ID lists —
  `{"read": ["vets"], "create": ["admin"], ...}` — enforced server-side
  by OR, admin bypasses, omitted key = everyone with app access. OR does
  NOT enforce per-object role rows through this block (documented in
  `lib/Lifecycle/ApplicationVersionOwnerGuard.php`), and has no
  creator/condition primitive today.
- `src/composables/useRole.js` is the single source of truth for
  role-keyed UI gating (`owner` / `editor` / `viewer` / `none`) and
  exposes `getCurrentUserGroups()` (initial state, ADR-004).
- `src/composables/useApplicationVersion.js` resolves the active
  ApplicationVersion and the Application's `productionVersion` UUID;
  `?_version=` routing is preserved via `buildVersionedRoute`.
- Capability precedent: OpenBuild contributes its own capability in
  `lib/Capabilities.php` and fleet apps read it via
  `@nextcloud/capabilities`; `useAppStatus.js` is the soft-check
  precedent. `useLivePreview.js` is the precedent for degrading a feature
  when a dependency is not detected.

## Decisions

### 1. Compile to OR `authorization` — no parallel openbuild block
Scopes compile directly into the schema-level `authorization` key that OR
already enforces. We deliberately do NOT introduce an
`x-openbuild-access` shadow block: a shadow block could describe scopes
the server does not enforce, which is exactly the fail-open trap the
runtime-group-scoped-access boundary rule ("menu hiding is UX,
`schema.authorization` is authoritative") exists to prevent. Because the
editor only ever *offers* scope kinds the connected OR advertises
(Decision 3), everything it writes is enforceable.

Compiled shapes per operation (`read` / `create` / `update` / `delete`):

- everyone → key omitted.
- groups → `authorization.<op> = ["<gid>", ...]` (current OR, verified).
- own records → `authorization.<op> = ["@creator"]` (upstream sentinel;
  `@`-prefixed to be un-collidable with NC group IDs).
- condition → `authorization.conditions.<op> = { "field": "<name>",
  "operator": "equals", "value": "@user.uid" | <literal> }` alongside an
  empty-list `<op>` entry, so a pre-condition OR fails closed rather than
  open. Field names are drawn from the staged `FieldEditor` fields.

The `@creator` and `conditions` shapes are the openbuild-side contract;
their enforcement + capability advertisement are the upstream leaf
requirements listed in proposal.md.

### 2. Sub-editor follows the LifecycleEditor pattern exactly
`AccessEditor.vue` lives in `src/components/schema-editor/`, receives
`:access` from the staged model, emits `update:access`, and exports pure
converters `accessToEditor(authorization)` / `editorToAccess(rows)` used
by `bodyToStaged()` / `composeSchemaBody()`. Group pickers use `NcSelect`
with `inputLabel` (gate `nc-input-labels`); no inline modals (gate
`modal-isolation`). Available NC groups come from the groups already
referenced by the Application `permissions` block plus a free-entry tag
input — no new endpoint and no full group-directory listing (mirrors the
runtime-group-scoped-access risk note about not leaking full membership).

### 3. Capability detection, fail-degraded not fail-broken
`useOrAccessCapabilities()` reads
`getCapabilities()?.openregister?.authorization?.scopes` from
`@nextcloud/capabilities`. Missing/absent → baseline `['group']`. The
editor renders a scope-kind option only when its key is advertised
(`group` is always offered; `creator` → own-records; `condition` →
condition scope). This makes the change shippable against current OR:
own/condition simply never appear until OR ships them and advertises the
capability. No OR version sniffing.

### 4. Lossless round-trip; unrepresentable entries preserved read-only
`bodyToStaged()` keeps the raw persisted `authorization` object; the
editor maps what it understands into rows and surfaces anything else
(unknown sentinels, conditions on an instance without the capability,
extra keys such as `_note`) as a read-only "managed outside the designer"
entry. `editorToAccess` merges edited rows back over the preserved raw
block, so a designer save can never strip or corrupt hand-authored
authorization. This is simultaneously the fix for the pre-existing
strip bug.

### 5. Lock-out warning is advisory, not blocking
Warning condition: staged `read` scope is group-based, the group list
does not intersect `getCurrentUserGroups()`, and the caller is not an NC
admin (admin bypasses OR enforcement, so admins are never locked out).
Rendered as an `NcNoteCard type="warning"` above the sub-editor; Save
stays enabled because locking yourself out is a legitimate authoring act
(e.g. an admin-assisted handover). Own-records and condition scopes do
not trigger the warning (the author's own records remain visible).

### 6. Production version = owner-only, UI-gated, server-backstopped
When `applicationVersion` is the Application's `productionVersion` and
`useRole` resolves `editor`, `AccessEditor` renders read-only with an
i18n note ("Access scopes on the production version can only be changed
by an owner"). This mirrors the owner-only release rule
(REQ-OBRBAC-004): an editor's draft-version scope edits only reach
production through an owner-driven publish, which
`ApplicationVersionOwnerGuard` already enforces server-side — so the UI
gate is a consistency surface, not the security boundary, and the
authoritative write gate remains OR's register manage-permission plus
the publish guard.

### 7. Scope badges are summaries, not editors
`SchemaListPanel.vue` derives a badge from each schema's `authorization`
block: no block → no badge; any block → a "Restricted" pill whose title
attribute lists per-operation scopes (e.g. "read: vets; delete: admin").
Derivation is a pure exported helper (`scopeSummary(schema)`) so it is
unit-testable without mounting.

## Risks

- Divergence from OR's eventual creator/condition shapes: mitigated by
  capability gating (nothing unenforceable is ever offered) and by
  keeping the compiled shapes in this spec as the contract the upstream
  leaf requirements must implement or renegotiate before the capability
  flag ships.
- Authors mistaking navigation gating (runtime-group-scoped-access
  `permission` fields) for data scoping: docs task states the split
  explicitly — `permission` hides navigation, the Access sub-editor
  scopes rows, and only the latter is enforcement.
- The preserved-raw-block merge (Decision 4) must be property-based
  tested against odd hand-authored blocks so the merge never reorders or
  drops keys.

## Seed Data

No new schemas and no seed changes. The `openbuild_register.json`
system-schema `authorization` blocks (`create/update/delete = ["admin"]`)
are exactly what the new editor renders as group scopes — they serve as
the read-only verification fixture for round-trip tests.
