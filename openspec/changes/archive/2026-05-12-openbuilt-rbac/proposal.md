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
actions (publish, transfer, archive) that need a real authority
gradient.

This spec closes the gap. It introduces a per-virtual-app role
model — `owner`, `editor`, `viewer` — declaratively stored on the
Application schema as a `permissions` block keyed by Nextcloud group
IDs. Enforcement is layered: OR's existing organisation scoping
(ADR-022) remains the outer multi-tenant boundary; the new
`permissions` block discriminates **within** an organisation. The
manifest endpoint enforces the role check server-side (closing the
direct-URL bypass), the OpenBuilt shell filters the application list
client-side (so users only see what they have access to), and the
editor UIs gate destructive actions per role.

A global `openbuilt.use` Nextcloud-group permission (declared via the
existing `<navigations>/<permission>` mechanism in `info.xml`) gates
the OpenBuilt top-bar entry itself — answering OQ-2 from spec #1 with
"admin-grantable per-group, default = all authenticated users".

The whole layer is schema-declarative per ADR-031. There is no
`ApplicationAuthorizationService.php`, no `RbacService`, no role
state machine — `permissions` is metadata on the Application schema,
and enforcement is the single thin controller check that ADR-022
§Exceptions(1) admits when OR's authorization vocabulary can't yet
express "role from caller's group membership".

## What Changes

- **MODIFIED capability `openbuilt-application-register`** — extend
  the `Application` schema in `lib/Settings/openbuilt_register.json`
  with a `permissions` property:
  `{ owners: [groupId], editors: [groupId], viewers: [groupId] }`.
  Default on creation: caller's primary Nextcloud group goes into
  `owners`; `editors` and `viewers` default to empty arrays. The
  field is plain declarative metadata — no new schema, no new state
  machine.
- **MODIFIED capability `openbuilt-runtime`** — three changes:
  1. `ApplicationsController::getManifest` returns `403 Forbidden`
     when the caller is not in any of the Application's
     `permissions.owners | editors | viewers` groups (in addition to
     the existing organisation-scoping check). The check is a single
     `IGroupManager::isInGroup()` loop — thin glue per
     ADR-022 §Exceptions(1).
  2. The frontend Application list (rendered from OR REST) filters
     out unauthorised Applications. If OR exposes an
     `x-openregister-authorization` extension that can express the
     rule "caller group is in `permissions.owners ∪ editors ∪
     viewers`", the filter runs on the OR side and the frontend
     simply consumes the filtered list (preferred). Otherwise the
     frontend filters in `ApplicationEditor.vue`'s list view using
     `OC.getCurrentUser()` group membership echoed in
     `IInitialState`.
  3. The editor UIs (the textarea editor today; the visual editors
     in chain specs #5 / #6 when they land) gate role-restricted
     actions: viewer can browse and read; editor can save manifest
     drafts; only owner can Publish / Archive / transfer ownership
     / change `permissions` / delete the Application.
- **NEW capability `openbuilt-rbac`** — owns the role model itself:
  the `permissions` shape, the default-on-creation behaviour, the
  enforcement contract on the manifest endpoint, the role → action
  mapping table, the transfer-ownership flow (owner → owner change),
  the audit-trail contract for permission changes (rely on OR's
  existing per-object audit per ADR-022 — every save to
  `permissions` lands in the OR audit log automatically), and the
  global `openbuilt.use` Nextcloud-group permission that gates the
  top-bar entry (admin-grantable; default = all authenticated
  users — answering spec #1 OQ-2).

### Capabilities

#### New Capabilities

- `openbuilt-rbac`: The role model (`owner | editor | viewer`),
  default-on-creation, the enforcement contract on the manifest
  endpoint, the role → action mapping, the transfer-ownership flow,
  permission-change audit trail, and the `openbuilt.use`
  navigation-entry gate. Schema-declarative per ADR-031 — no
  authorization service class.

#### Modified Capabilities

- `openbuilt-application-register`: adds the `permissions` property
  to the Application schema and its default-on-creation behaviour.
- `openbuilt-runtime`: adds the 403 path on `getManifest`, the
  visibility filter on the Application list view, and the
  role-keyed action gating in the editor UIs.

## Impact

- **Schema change** — `Application.permissions` is a new optional
  object property in `lib/Settings/openbuilt_register.json`.
  Existing Applications seeded by spec #1's repair step (the
  `hello-world` Application) get a migration default during this
  spec's apply phase: `permissions.owners` is set to the system
  organisation's admin group, `editors` and `viewers` to empty.
  The repair step is idempotent and only patches Applications whose
  `permissions` field is missing.
- **Backend** — one new check in
  `ApplicationsController::getManifest`. ~10 LOC; carries the
  required SPDX + EUPL-1.2 docblock; `#[NoAdminRequired]` stays.
  No new service class. No new controller.
- **Frontend** — visibility filter on the Application list view, a
  small permissions-editor panel (group pickers for the three
  roles) in `ApplicationEditor.vue`, role-keyed `:disabled` /
  `v-if` guards on Publish / Archive / Delete / Transfer-ownership
  buttons. A `useRole(slug)` composable that derives the caller's
  role from the loaded Application's `permissions` + the user's
  groups (echoed via `loadState` per ADR-004 hard rule).
- **Nextcloud integration** — `appinfo/info.xml` adds a
  `<navigations>/<permission>` block keyed to a new
  `openbuilt.use` group permission. Default group: empty (which
  Nextcloud interprets as "all authenticated users"), preserving
  spec #1's auth-only posture for users who don't configure it.
- **OpenRegister** — no schema additions beyond the new
  `permissions` property. If OR's authorization extension is
  available, this spec wires it; otherwise the thin app-local
  filter ships as the ADR-022 §Exceptions(1) fallback.
- **No breaking changes** — Applications without a `permissions`
  block are treated as "owner = creator's primary group" during
  the migration; after the migration runs, every Application has a
  populated `permissions` field. The default keeps existing
  single-tenant installs working without admin action.
- **Foundational ADRs honoured** — ADR-005 (security baseline — no
  IDOR, no role elevation, deny-by-default on the manifest
  endpoint), ADR-022 (consume OR abstractions; the thin manifest
  check is the documented exception), ADR-031 (schema-declarative
  business logic — `permissions` is metadata, not a service),
  ADR-004 (initial-state for the user's groups, no DOM
  data-attribute reads).
