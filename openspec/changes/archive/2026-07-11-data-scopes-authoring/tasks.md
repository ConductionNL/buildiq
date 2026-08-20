# Tasks — data scopes authoring

> Apply notes: verify every claim below against HEAD before editing — do
> not trust these descriptions over the code. All OR CRUD stays on
> `useSchemasStore`; no direct axios to OR. No sed/awk/scripted edits.

## 1. Capability detection composable

- [x] 1.1 Create `src/composables/useOrAccessCapabilities.js`: read
      `getCapabilities()?.openregister?.authorization?.scopes` via
      `@nextcloud/capabilities`; return `{ scopes }` with baseline
      `['group']` when the key is absent or malformed. Pure + synchronous
      (capabilities are preloaded), mirroring `useAppStatus.js` style.
- [x] 1.2 Vitest `src/composables/useOrAccessCapabilities.spec.js`:
      absent key → `['group']`; advertised
      `['group','creator','condition']` passes through; non-array value
      → baseline.

## 2. AccessEditor sub-editor

- [x] 2.1 Create `src/components/schema-editor/AccessEditor.vue`
      following the `LifecycleEditor.vue` pattern: props `access`
      (staged model) + `readOnly`; emits `update:access`; one row per
      operation (`read`/`create`/`update`/`delete`) with a scope-kind
      `NcSelect` (with `inputLabel` — gate `nc-input-labels`) and, for
      the groups kind, a group tag input seeded from the Application
      `permissions` groups. Offer *own records* / *condition* kinds only
      when `useOrAccessCapabilities().scopes` includes `creator` /
      `condition` (REQ-OBDSA-003).
- [x] 2.2 In the same file export pure converters
      `accessToEditor(authorization)` and
      `editorToAccess(rows, rawAuthorization)`: compile everyone → key
      omitted; groups → `authorization.<op> = [gids]`; own →
      `["@creator"]`; condition → `authorization.conditions.<op>` shape
      from design.md Decision 1. `editorToAccess` merges edited rows over
      the preserved raw block so unrepresentable entries (`_note`,
      unknown sentinels, conditions without the capability) survive
      byte-identical (REQ-OBDSA-002).
- [x] 2.3 Render unrepresentable preserved entries as a read-only
      "managed outside the designer" list inside `AccessEditor.vue`.
- [x] 2.4 Condition rows: field `NcSelect` fed from a `fieldNames` prop
      (staged FieldEditor field names), operator fixed to `equals` for
      v1, value input offering the `@user.uid` token or a literal.
- [x] 2.5 Vitest `src/components/schema-editor/AccessEditor.spec.js`:
      converter round-trips (each scope kind both directions); raw-block
      preservation property (arbitrary extra keys survive
      `editorToAccess`); capability gating hides own/condition options;
      `readOnly` disables all controls.

## 3. SchemaDesigner wiring

- [x] 3.1 `src/views/SchemaDesigner.vue`: add
      `access: accessToEditor(body.authorization)` plus a preserved
      `rawAuthorization` to `bodyToStaged()`; write the compiled block in
      `composeSchemaBody()` via
      `editorToAccess(staged.access, staged.rawAuthorization)` (omit the
      `authorization` key entirely when the result is empty). This also
      fixes the pre-existing strip-on-save bug — a body with an
      `authorization` block set outside the designer must survive an
      unrelated save (REQ-OBDSA-002).
- [x] 3.2 Mount `<AccessEditor>` after `<RelationEditor>` with
      `:access`, `:field-names`, `:read-only`, and `@update:access` →
      `onAccessChange` (same staged-spread pattern as
      `onRelationsChange`).
- [x] 3.3 Lock-out warning: computed `authorLockedOut` — staged `read`
      scope is group-kind, groups do not intersect
      `getCurrentUserGroups()` (from `src/composables/useRole.js`), and
      caller is not NC admin; render an `NcNoteCard type="warning"` above
      the sub-editor; Save stays enabled (REQ-OBDSA-004).
- [x] 3.4 Production owner gating: computed `accessReadOnly` — active
      `applicationVersion` is the Application's `productionVersion`
      (already fetched by `useApplicationVersion`) and
      `useRole(applicationRecord)` is `editor`; pass as `read-only` to
      `AccessEditor` with the i18n note from REQ-OBDSA-007.
- [x] 3.5 Vitest `src/views/SchemaDesigner.access.spec.js`:
      `bodyToStaged`/`composeSchemaBody` round-trip with and without
      `authorization`; unrelated-edit save preserves the block; lock-out
      computed truth table (member / non-member / admin); read-only
      computed truth table (draft vs production × owner vs editor).

## 4. SchemaListPanel badges

- [x] 4.1 `src/components/schema-editor/SchemaListPanel.vue`: export a
      pure helper `scopeSummary(schema)` returning `null` for no
      `authorization` block, else `{ label: 'Restricted', title }` with
      the per-operation summary (e.g. `read: vets; delete: admin`);
      render the badge in the row meta span (REQ-OBDSA-005).
- [x] 4.2 Vitest `src/components/schema-editor/SchemaListPanel.spec.js`
      (extend if it exists — check HEAD): `scopeSummary` cases (no
      block, single op, multiple ops, `@creator`, conditions) and badge
      presence/absence in a mounted list.

## 5. Playwright e2e (tests/e2e)

- [x] 5.1 `tests/e2e/schema-access-scopes.spec.ts` — authoring flow:
      set group `read` scope → save → reload → persisted
      (REQ-OBDSA-001 scenario 1); independent per-op scopes
      (REQ-OBDSA-001 scenario 2); unrelated-edit save preserves an
      API-seeded `authorization` block (REQ-OBDSA-002 scenario 1);
      API-seed an `@creator` entry and assert read-only rendering +
      byte-identical persistence after save (REQ-OBDSA-002 scenario 2);
      baseline scope-kind picker offers exactly everyone + groups
      (REQ-OBDSA-003 scenario 1); badge shows for the scoped schema and
      not the unscoped one, title includes `read: vets`
      (REQ-OBDSA-005).
- [x] 5.2 `tests/e2e/schema-access-scopes-rbac.spec.ts` — multi-user
      flow (reuse the `rbac-403.spec.ts` / `versionRouting.spec.ts`
      fixtures — verify their user/version setup against HEAD):
      non-admin non-member sets a `vets`-only read scope → lock-out
      warning visible, Save enabled; member sets the same scope → no
      warning (REQ-OBDSA-004); draft-version scope save leaves the
      production register's schema unchanged (REQ-OBDSA-006); editor on
      the production version sees disabled Access controls + note, owner
      sees them enabled (REQ-OBDSA-007).

## 6. Docs

- [x] 6.1 Add a "Data scopes (row-level access)" section to the schema
      designer tutorial page under `docs/tutorials/` (design-schema page
      — locate exact file at HEAD) covering the four scope kinds,
      capability gating, and the lock-out warning.
- [x] 6.2 `docs/openbuild-rbac.md`: add the boundary paragraph required
      by REQ-OBDSA-008 — manifest `permission` fields hide navigation
      (runtime-group-scoped-access); Access scopes compile to OR
      `schema.authorization` and are the enforced row-level control;
      production scope changes are owner-only.
