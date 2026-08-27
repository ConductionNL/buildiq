## 1. Manifest schema (nextcloud-vue)

- [x] 1.1 Add the top-level `adminSettings` array + `$defs/adminSettingsEntry` to `src/schemas/app-manifest-v2.schema.json`: required `id`/`label`, optional `order`/`permission`/`props`, exactly-one-of `type`|`component`, `type` closed enum `["organisation-credentials"]`, `additionalProperties:false`. Sibling to `pages`/`menu`.
- [x] 1.2 Add manifest fixtures + `validateManifest` unit tests: built-in section validates, custom section validates, neither-type-nor-component rejected, both rejected, unknown `type` rejected, absent `adminSettings` still validates.

## 2. Generic admin dialog (CnAppRoot)

- [x] 2.1 Mount a second `NcAppSettingsDialog` (distinct from the personal one) in `CnAppRoot`, opened via a new `cnOpenAdminSettings` inject; iterate `manifest.adminSettings[]` sorted by `order` into one `NcAppSettingsSection` per entry.
- [x] 2.2 Map built-in `type: "organisation-credentials"` → `CnCredentials scope="organisation"`; resolve `component` entries from the custom-components registry forwarding `props`; keep each built-in section component in its own file (modal-isolation).
- [x] 2.3 Verify the personal user-settings dialog, its `action: "user-settings"` wiring, `CnNotificationPreferences`, and the personal Credentials pane are unchanged.

## 3. Owner gating (CnAppRoot / CnAppNav)

- [x] 3.1 Resolve `isOwner` on the frontend: `loadState('buildiq','currentUserGroups',[])` ∩ GIDs parsed from `permissions.owners` (`group:<gid>`/bare grammar), OR a `runtime.user` owner/role signal; read via initial-state, never DOM attributes.
- [x] 3.2 Auto-include an owner-gated "Admin settings" nav entry in `CnAppNav` and honour a manifest `action: "admin-settings"` entry (REQ-JMR-004); open the admin dialog on click.
- [x] 3.3 Remove `OC.isUserAdmin()` as the gate for the admin surface; gate both the nav entry and the dialog (and per-section `permission` narrowing) on `isOwner`, with `permission` narrow-only.

## 4. Backend owner signal (buildiq)

- [x] 4.1 Surface a read-only owner flag/role on the manifest `runtime.user` context at serve time, computed via `PermissionResolver::matchesCaller(...['owners'])` over the app's `permissions`; reuse the already-published `buildiq.currentUserGroups` and `PopulateApplicationPermissions` owner default. No grammar/model change.
- [x] 4.2 SPDX headers + full PHPDoc + `@spec` tags on any touched PHP; pass `composer check:strict`.

## 5. Tests

- [x] 5.1 nextcloud-vue vitest: admin dialog renders org-credentials + custom sections in `order`; absent/empty `adminSettings` mounts no admin surface.
- [x] 5.2 nextcloud-vue vitest: owner sees admin surface; non-owner does not; `runtime.user` owner signal path; NC super-admin flag alone does not unlock; section `permission` narrows only.
- [ ] 5.3 Playwright e2e (owner + non-owner): open a virtual app, assert admin nav entry + org-credentials pane visibility per owner-group membership; tag the new scenarios `@spec` + e2e.

## 6. Verify

- [x] 6.1 Run `openspec validate buildiq-admin-settings-abstraction --strict` and the relevant hydra gates (initial-state, modal-isolation, nc-input-labels, spec-coverage) green on the diff.
- [x] 6.2 Backend owner projection matches `matchesCaller` for owner/non-owner/super-admin; confirm no change to `PermissionResolver` grammar or `permissions` block shape.

## Acceptance criteria

- An app declaring `adminSettings: [{type:"organisation-credentials", ...}]` shows the org-credentials pane in an admin dialog to app owners only.
- An app with no `adminSettings` shows no admin nav entry and no admin dialog (byte-for-byte prior behaviour).
- The admin surface gate is owner-group membership, not `OC.isUserAdmin()`.
- The personal user-settings surface (Credentials + Notifications) is unchanged.
- No new permission model: owner status derives from `currentUserGroups`, `permissions.owners`, and `PermissionResolver`.

## Quality checklist

- Manifest edit is additive; every existing fleet manifest still validates.
- Owner data read via `loadState` (initial-state), never DOM data-attributes.
- Built-in section components live in their own files (modal-isolation); any new `NcSelect` carries an `inputLabel`.
- i18n keys are English source.
- No OpenRegister schema introduced/modified → no seed data, no register version bump.
- `composer check:strict` (PHP) and nextcloud-vue `npm test` + `check:docs`/`check:jsdoc` pass.
