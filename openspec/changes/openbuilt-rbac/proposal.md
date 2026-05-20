---
kind: mixed
depends_on: [bootstrap-openbuilt]
chain:
  - bootstrap-openbuilt
  - openbuilt-rbac   # THIS spec (#7 of 9)
---

## Why

Spec #1 (`bootstrap-openbuilt`) **explicitly deferred** per-built-app
RBAC. Per its design.md Open Question OQ-2 ("Permission key for the
OpenBuilt top-bar entry"), the foundational bootstrap shipped with
`auth-only` access: any authenticated user in an organisation can
list, open, edit, publish, archive, **and delete** every virtual app
in that organisation. That posture is acceptable for the
"first-install / single-integrator" shape that spec #1 validated, but
is unacceptable for production multi-tenant deployments where
distinct teams co-own the OpenBuilt shell and where the
`openbuilt-page-editor` (chain spec #5) and
`openbuilt-versioning` (chain spec #6) introduce destructive
actions — publish, transfer, archive — that need a real authority
gradient.

This spec closes the gap. It introduces a per-virtual-app role
model — `owner`, `editor`, `viewer` — declaratively stored on the
Application schema as a `permissions` block keyed by Nextcloud group
IDs. Enforcement is layered: OR's existing organisation scoping
(ADR-022) remains the outer multi-tenant boundary; the new
`permissions` block discriminates **within** an organisation.

The manifest endpoint enforces the role check server-side (closing
the direct-URL bypass), the OpenBuilt shell filters the application
list so users only see what they have access to, and the editor UIs
gate destructive actions per role. A global `openbuilt.use`
Nextcloud-group permission (declared via `<navigations>/<permission>`
in `info.xml`) gates the top-bar entry — answering spec #1 OQ-2
with "admin-grantable per-group, default = all authenticated users".

The whole layer is schema-declarative per ADR-031. There is no
`ApplicationAuthorizationService.php`, no `RbacService`, no role
state machine — `permissions` is metadata on the Application schema,
and enforcement is a single thin controller check that ADR-022
§Exceptions(1) admits when OR's authorization vocabulary cannot yet
express "role from caller's group membership".

## What Changes

- **MODIFIED capability `openbuilt-application-register`** — extend
  the `Application` schema in `lib/Settings/openbuilt_register.json`
  with a `permissions` property:
  `{ owners: [groupId], editors: [groupId], viewers: [groupId] }`.
  Default on creation: caller's primary Nextcloud group goes into
  `owners`; `editors` and `viewers` default to empty arrays. An
  idempotent repair step populates `permissions.owners = ["admin"]`
  for pre-existing Applications that have no `permissions` field.

- **MODIFIED capability `openbuilt-runtime`** — three changes:
  1. `ApplicationsController::getManifest` returns `403 Forbidden`
     when the caller is not in any of the Application's
     `permissions.owners | editors | viewers` groups (after the
     existing organisation-scope check passes). The Nextcloud admin
     group bypasses the check with a mandatory `rbac.admin_bypass`
     audit entry.
  2. The frontend Application list filters out Applications the
     caller has no role on. Preferred path: `x-openregister-
     authorization` expression on the schema so OR itself omits
     unauthorised rows. Fallback: JS-side filter consuming the
     caller's group set echoed via `IInitialState` (ADR-004 hard
     rule — no DOM data-attribute reads).
  3. The editor UIs gate role-restricted actions via a shared
     `useRole(application)` composable: viewer = read-only; editor =
     can save drafts; owner = full controls including Publish /
     Archive / Delete / Transfer-ownership / edit `permissions`.

- **NEW capability `openbuilt-rbac`** — owns the role model itself:
  the `permissions` shape, the default-on-creation behaviour, the
  manifest-endpoint enforcement contract, the role → action mapping
  table, the transfer-ownership flow (a plain `permissions.owners`
  PUT — no dedicated endpoint), the audit-trail contract for
  permission changes (OR's existing per-object audit per ADR-022 —
  every `permissions` save lands in the OR audit log automatically),
  and the global `openbuilt.use` Nextcloud-group permission that
  gates the top-bar entry.

### Capabilities

#### New Capabilities

- `openbuilt-rbac`: The role model (`owner | editor | viewer`),
  default-on-creation (creator's primary group → `owners`; fallback
  to `admin` when groupless), the manifest-endpoint 403 path, the
  role → action mapping table, the transfer-ownership flow, the
  permission-change audit trail, the "Permission history" panel
  (owner-only; backed by OR's existing per-object audit trail), and
  the `openbuilt.use` navigation-entry gate. Schema-declarative per
  ADR-031 — no authorization service class.

#### Modified Capabilities

- `openbuilt-application-register`: adds the `permissions` property
  to the Application schema and the idempotent migration repair step
  that populates it for pre-existing Applications.

- `openbuilt-runtime`: adds the 403 path on `getManifest`, the
  role-filtered Application list view, the `IInitialState` echo of
  the caller's group set, and the role-keyed action gating in the
  editor UIs via `useRole(application)`.

## Impact

- **Schema change** — `Application.permissions` is a new optional
  object property in `lib/Settings/openbuilt_register.json`.
  Existing Applications are backfilled to
  `permissions = { owners: ["admin"], editors: [], viewers: [] }`
  via an idempotent `<post-migration>` repair step. No data is lost
  on rollback — the property is optional and the controller check
  is the deploy-gated enforcement point.
- **Backend** — one new check in
  `ApplicationsController::getManifest` (~12 LOC). Carries the
  existing SPDX + EUPL-1.2 docblock; `#[NoAdminRequired]` attribute
  preserved. One new repair step
  `lib/Repair/PopulateApplicationPermissions.php`. No new service
  class, no new controller.
- **Frontend** — `useRole(application)` composable (~25 LOC); role
  guards (`v-if`, `:disabled`, `readonly`) on editor controls; a
  Permissions panel (owner-only) with `<NcSelect>` group pickers
  in `src/modals/PermissionsModal.vue`; a Permission history panel
  (owner-only) rendering OR's per-object audit trail; initial-state
  consumption of `currentUserGroups` from `loadState`.
- **Nextcloud integration** — `appinfo/info.xml` adds a
  `<navigations>/<permission>` block keyed to `openbuilt.use`.
  Default: all authenticated users. Admin-narrowable via Nextcloud's
  standard group restriction UI. Note: upstream `apps/info.xsd` may
  not yet accept the element (tracked as nextcloud/server#60310);
  fallback is `occ app:enable openbuilt --groups <group>`.
- **OpenRegister** — no new schemas. If OR's
  `x-openregister-authorization` vocabulary supports
  `groupIn-pointer` semantics, the read rule is declared additively
  on the Application schema and the list filter runs server-side.
  Otherwise the JS-side fallback ships as the ADR-022 §Exceptions(1)
  documented path.
- **No breaking changes** — Applications without a `permissions`
  block are backfilled; the migration is idempotent and runs before
  the new controller enforcement becomes active.
- **Foundational ADRs honoured** — ADR-005 (deny-by-default; no
  IDOR; no PII in audit events), ADR-022 (consume OR abstractions;
  thin in-controller check is the documented exception), ADR-031
  (permissions is metadata, not a service class), ADR-004 (groups
  echoed via `IInitialState` / `loadState`; no DOM data-attributes).
