## Context

`bootstrap-openbuilt` (spec #1 of the 9-spec OpenBuilt chain)
**explicitly deferred** per-virtual-app RBAC. Its design.md Open
Question OQ-2 — "Permission key for the OpenBuilt top-bar entry" —
landed with the provisional decision "auth-only; let admins narrow
via NC's group restrictions; per-built-app RBAC lands in chain spec
#7". This is that spec.

Without this layer, every authenticated user in an organisation
shares full access to every virtual app: list, open, edit the
manifest, publish, archive, and (with chain spec #6's versioning)
roll back. That is not a posture for production. Once
`openbuilt-page-editor` (chain spec #5),
`openbuilt-versioning` (chain spec #6),
`openbuilt-templates-marketplace` (chain spec #8), and
`openbuilt-export-to-real-app` (chain spec #9) land destructive
actions, the absence of a real authority gradient becomes an active
liability — both as a workflow hazard (someone publishes the wrong
draft) and as a security gap (cross-team manifest editing inside a
shared organisation, OWASP A01:2021).

OpenRegister already provides the **outer** authorization boundary:
organisation-scoped multi-tenancy (ADR-022). This spec adds the
**inner** boundary — within a single organisation, distinct teams
co-own distinct virtual apps. The model is the conventional
three-role split (`owner | editor | viewer`), keyed by Nextcloud
group ID, declared as metadata on the Application schema, and
enforced both on the read endpoint (server-side) and in the
frontend list / editor surfaces (client-side, with audited
admin-bypass).

## Goals / Non-Goals

**Goals**

- Add a `permissions` block to the `Application` schema in
  `lib/Settings/openbuilt_register.json` carrying three Nextcloud
  group-ID arrays (`owners`, `editors`, `viewers`). Default on
  creation: creator's primary group → `owners`; the other two
  empty. Falls back to `["admin"]` when the creator has no group
  memberships. Idempotent migration populates existing Applications
  (e.g. the `hello-world` seed) with `admin` as owner.
- Enforce the role check **server-side** on
  `ApplicationsController::getManifest`: deny-by-default with `403`
  when there is no role intersection; ordered before the manifest-
  body branch so no payload leaks. Single in-controller check — no
  authorization service class.
- Filter the frontend Application list view to show only
  Applications the caller has at least viewer role on. Prefer
  OR-side filtering via `x-openregister-authorization`; otherwise
  filter in JS using groups echoed via `loadState` per ADR-004.
- Gate destructive / write actions in the editor UIs via a shared
  `useRole(application)` composable, with the canonical role →
  action matrix documented in REQ-OBRBAC-004.
- Support a transfer-ownership flow that is just a
  `permissions.owners` PUT via OR REST — no dedicated endpoint or
  service class. Reject any PUT that would result in
  `permissions.owners = []`.
- Declare a global `openbuilt.use` group-permission on the
  `<navigations>` entry, default unrestricted, admin-grantable
  through Nextcloud's standard mechanism. Closes spec #1 OQ-2.
- Surface a "Permission history" panel to `owner`-role holders
  backed by OR's existing per-object audit trail (ADR-022) — no
  app-local audit duplication.

**Non-Goals**

- **Fine-grained per-field or per-page permissions inside a
  manifest.** Application-level access only. Per-page RBAC layers
  on top later.
- **A custom role system or role-renaming.** Three fixed roles —
  `owner`, `editor`, `viewer`. No admin UI for adding new roles.
- **A new `RbacService` / `AuthorizationService` class.** Forbidden
  by ADR-031; the enforcement check is a single in-controller method
  block per ADR-022 §Exceptions(1).
- **Authentication.** Nextcloud handles auth; this spec handles only
  authorization on top of an already-authenticated session.
- **Cross-organisation sharing.** OR's organisation boundary stays
  in force above this layer.
- **OR schema changes beyond the new `permissions` property.** No
  new schemas, no new relations, no new lifecycle states.

## Decisions

### Decision 1 — Enforcement layer: OR authorization extension vs thin app-local check

We prefer to express the role check as an
`x-openregister-authorization` rule on the `Application` schema so
that the rule travels with the schema, is enforceable at the OR REST
list endpoint (no leaks at all), and benefits from OR's existing
audit / caching / org-scope plumbing automatically. The rule shape
would be approximately:

```json
"x-openregister-authorization": {
  "read": {
    "anyOf": [
      { "groupIn": "permissions.owners" },
      { "groupIn": "permissions.editors" },
      { "groupIn": "permissions.viewers" }
    ]
  }
}
```

**If OR's authorization vocabulary already supports
`groupIn: <permissions-pointer>` semantics**, we declare the rule
in `lib/Settings/openbuilt_register.json` and the manifest
endpoint check becomes defence-in-depth (OR rejects the read
before reaching the controller). We still ship the in-controller
check per ADR-005.

**If OR's vocabulary does not yet express this rule**, the
manifest-endpoint check is the primary enforcement point — the
documented ADR-022 §Exceptions(1) thin glue. We file an OR-side
issue requesting the `groupIn-pointer` authorization extension. The
frontend list filter falls back to JS-side filtering using
`loadState('openbuilt', 'currentUserGroups')`.

The choice is **observed during apply**, not pre-decided here; both
paths satisfy REQ-OBRBAC-002 / REQ-OBR-006 / REQ-OBR-007 from the
user's perspective. The apply agent SHALL record the chosen path in
`hydra.json` under `decisions[]`.

**Alternatives considered**

- *Write `OpenBuiltAuthorizationService` and route all reads through
  it.* Rejected. ADR-031 forbids authorization service classes;
  ADR-022 forbids wrapping OR's REST endpoints. The thin
  in-controller check on the one PHP endpoint we already own is the
  only PHP we should ship.
- *Encode permissions inside the manifest blob itself.* Rejected.
  The manifest is the **content** of a virtual app; permissions are
  about the **container**. Conflating them breaks the "manifest is
  the rendered thing" mental model from ADR-024.

### Decision 2 — Default permissions on creation

When a new `Application` is created without an explicit `permissions`
value, the system populates:

- `permissions.owners` = `[creator's primary Nextcloud group]`
  (first group returned by `IGroupManager::getUserGroups($user)`)
- `permissions.editors` = `[]`
- `permissions.viewers` = `[]`

If the creator has no group memberships (e.g. a service account),
`permissions.owners` falls back to `["admin"]` so the Application is
never left in a "no owner" state where REQ-OBRBAC-005's orphan-check
would be the only guardrail.

The default is computed once, at creation time, using `IGroupManager`.
There is no `DefaultPermissionsService` — the default is computed
inline in the existing Application creation path.

**Alternatives considered**

- *Default to a single ad-hoc per-Application group created at
  creation time.* Rejected. Doubles the Nextcloud group-namespace
  pressure and loses the "use my team" mental model.
- *Default to "no owner" and force the creator to fill it in via a
  modal.* Rejected. Bad UX, race-prone, and a forgotten modal leaves
  the Application unreachable.

### Decision 3 — Group resolution uses IGroupManager, not an app-local abstraction

Permissions store Nextcloud `gid` strings directly. Resolution at
check time uses `IGroupManager::getUserGroups($user)` →
`array_intersect($userGids, $applicationAuthorisedGids)`. No
app-local group abstraction, no caching layer beyond what Nextcloud
already provides, no `OpenBuiltGroupService`.

The trade-off: the permissions block is tied to Nextcloud's group
model. If a future Conduction spec introduces a richer "team"
abstraction, a migration on the `gid` arrays may be needed.
Acceptable forward cost vs. building a parallel group model now.

**Alternatives considered**

- *Build an `OpenBuiltTeam` abstraction over Nextcloud groups for
  forward compatibility.* Rejected. YAGNI per ADR-031; adds a new
  schema (forbidden by this spec's non-goals).
- *Use Nextcloud Circles instead of Groups.* Rejected. Circles are
  not a baseline assumption across Conduction's target deployments;
  Groups are.

### Decision 4 — `openbuilt.use` mechanism: Nextcloud's existing navigation permission

We answer spec #1 OQ-2 by leaning on Nextcloud's existing
`<navigations>/<permission>` mechanism in `appinfo/info.xml`. No new
admin-settings page is shipped by this spec. An administrator
configures the entry via Nextcloud's standard "Admin → Apps →
OpenBuilt → Restrict to groups" UI.

Default: no restriction → entry visible to every authenticated user.
This preserves spec #1's auth-only posture for installs that never
touch the setting.

**Upstream schema gap note** — The `apps/info.xsd` schema in
Nextcloud may reject `<permission>openbuilt.use</permission>` as a
child of `<navigation>` (tracked as nextcloud/server#60310). Until
the upstream schema accepts the element, the navigation gate ships
in **fallback mode**: operators restrict top-bar visibility via
`occ app:enable openbuilt --groups <group>`, which is coarser but
available today. The per-Application server-side RBAC on
`ApplicationsController::getManifest` is the load-bearing security
boundary either way.

**Alternatives considered**

- *Ship a new `Settings/AdminSettings.php` and a Vue admin page.*
  Rejected. Net-new infrastructure for a setting Nextcloud already
  exposes through its standard apps panel.
- *Skip the navigation permission entirely.* Rejected. Useful as a
  coarse on/off for organisations that want to hide OpenBuilt from
  non-builder users cheaply.

### Decision 5 — Admin bypass: audited, narrow, controller-only

A user in the Nextcloud `admin` group bypasses the per-Application
`permissions` check on the manifest endpoint. The bypass:

- Runs **only** in `ApplicationsController::getManifest`. The
  frontend list filter does **not** auto-include admins (admins see
  the list filtered by their own group membership; if they want to
  see all Applications, they list via OR REST).
- Records a `rbac.admin_bypass` audit event in OR's audit trail
  every time it is exercised, naming the actor, the slug, the
  organisation, and the timestamp (REQ-OBRBAC-006).

The narrowness is deliberate: the bypass is an incident-response
escape hatch, not a general convenience. The audit trail makes every
exercise reviewable.

**Alternatives considered**

- *No admin bypass; admins must hold explicit roles.* Tempting and
  cleaner, but breaks the operational reality that an admin sometimes
  needs to read a virtual app's manifest when its owners are
  unreachable. Defer this stricter posture to a future spec.
- *Silent admin bypass with no audit.* Rejected. Indistinguishable
  from a backdoor.

### Decision 6 — Permission history visibility: owner-only

The "Permission history" panel — rendering OR's per-object audit
trail filtered to permission changes — is visible only to users with
`owner` role on the Application. Editors and viewers do not see the
panel, and any direct API call backing the panel is gated by the same
owner-only check.

Reasoning: permission history is itself sensitive (it reveals which
groups had which access at which time, and which admins exercised the
bypass). Surfacing it to editors and viewers leaks the org chart and
the incident response trail. Owners are the only role with a
legitimate need to audit who they have delegated to.

**Alternatives considered**

- *Visible to editors too.* Rejected. Editors are collaborators;
  they do not need the access-grant audit trail.
- *Visible to all roles.* Rejected for the same reasons above.

## Declarative-vs-imperative

The whole RBAC layer is **declarative metadata** plus **one thin-glue
PHP check** plus **one thin-glue Vue composable**:

| Behaviour | Path |
|---|---|
| `permissions` shape on Application | **Declarative** — JSON Schema in `lib/Settings/openbuilt_register.json` |
| Default-on-creation | **Inline** — computed once in the Application creation path using `IGroupManager`; no service class |
| Read enforcement (manifest endpoint) | **Thin glue** — single `if (!intersect) { return 403 }` in `ApplicationsController::getManifest`; ADR-022 §Exceptions(1). Promotes to OR-declarative if `x-openregister-authorization` supports `groupIn-pointer` |
| List filtering | **Declarative-preferred** — `x-openregister-authorization` if available; otherwise thin JS filter consuming `loadState` |
| Editor action gating | **Thin glue** — `useRole(application)` composable in `src/composables/useRole.js`, ~25 LOC, returns `'owner' \| 'editor' \| 'viewer' \| 'none'` |
| Transfer ownership | **Declarative** — it is a `permissions.owners` PUT; no dedicated endpoint |
| Audit trail | **Inherited** — OR's existing per-object audit per ADR-022; the panel is a read view, not a write |
| `openbuilt.use` navigation gate | **Declarative** — `<navigations><permission>openbuilt.use</permission></navigations>` in `appinfo/info.xml` |

**Anti-patterns explicitly avoided.** This spec ships **no**:

- `OpenBuiltAuthorizationService.php` / `RbacService.php` /
  `PermissionService.php`. The check lives in the controller.
- Custom role names or a role registry. Three fixed roles.
- Per-page or per-field permission engine.
- Parallel audit trail. OR's audit trail is the only audit trail.
- Frontend role state machine. `useRole(app)` is a pure derivation.

## Reuse Analysis

Per ADR-001 (data-layer) and ADR-022 (apps consume OR abstractions),
the following OR services are reused directly — no duplication:

| OR / Nextcloud abstraction | Consumed by this change |
|---|---|
| `IGroupManager::getUserGroups()` | Group resolution at check time and on creation |
| `IGroupManager::isInGroup()` | Membership check in `getManifest` |
| OR per-object audit trail (`AuditTrailService`) | Permission-change recording and Permission history panel |
| OR `ObjectService::saveObject()` | Repair step writes `permissions` defaults |
| `x-openregister-authorization` (conditional) | OR-side list filtering if vocabulary supports `groupIn-pointer` |
| `IInitialState::provideInitialState()` | Echo caller's group set to frontend |
| Nextcloud `<navigations>/<permission>` | `openbuilt.use` gate |

No new service class is introduced. The only net-new PHP is the
repair step and ~12 LOC in the existing controller.

## Risks / Trade-offs

- **Risk** — *Frontend list filter (fallback path) can race group
  membership.* If an admin removes a user from a group between page
  load and a click, the frontend may still display Applications the
  user no longer has access to; the click hits the manifest endpoint
  and gets a 403. Mitigation: REQ-OBR-006 ensures the 403 path is
  well-defined and surfaces a "your access was revoked" toast; the
  list refreshes on the next load. Acceptable for v1.
- **Risk** — *Group renames silently break permissions.* If a
  Nextcloud admin renames a group, every Application whose
  `permissions` array references the old `gid` loses or gains rows
  without an OpenBuilt-scoped audit signal. Mitigation: document
  the operational caveat in the admin guide; defer a group-rename
  listener to a follow-up spec if a customer reports breakage.
- **Risk** — *Admin-bypass volume hides genuine admin abuse.* If
  admins routinely use the bypass for non-incident work, the audit
  trail becomes noise. Mitigation: a MyDash dashboard widget (out of
  scope here) can surface bypass volume per admin per week.
- **Trade-off** — *No per-page permissions.* Acceptable for v1;
  Application-level RBAC is the right grain for the virtual app
  sizes in scope. A future spec can layer per-page gating on top.
- **Trade-off** — *Three roles, no custom roles.* Keeps the model
  legible; covers the cases real users actually need. The cost of
  adding a fourth role later (e.g. `approver` for page-editor review)
  is one schema migration plus one row in the role-action matrix.

## Migration Plan

This change extends the `Application` schema and adds enforcement to
the existing manifest endpoint. The migration runs as part of the
existing OpenBuilt repair-steps pipeline:

1. The schema-update repair step (already in place from spec #1 via
   `ConfigurationService::importFromApp()`) re-imports the register
   configuration and adds the new `permissions` property to the
   `Application` schema in OR.
2. A new repair step, `lib/Repair/PopulateApplicationPermissions.php`,
   scans every `Application` whose `permissions` is null or missing
   and patches it to
   `{ owners: ["admin"], editors: [], viewers: [] }`.
   The step is idempotent (skips Applications whose
   `permissions.owners` is already non-empty) and bulk — one OR REST
   round-trip per Application.
3. The repair step ordering is `<post-migration>` so it runs after
   the schema has been re-imported.
4. The `ApplicationsController::getManifest` enforcement code lands
   in the same deploy; admins should expect that, post-deploy,
   every previously-readable virtual app is readable only by `admin`
   group members until owners are explicitly reassigned.

**Communication to operators**: the release note SHALL include a
section titled "ACTION REQUIRED: re-grant access after upgrade" with
the OR REST command to bulk-update `permissions` for known cases.

**Rollback** — revert the deploy. The schema's `permissions` property
is optional so no data is lost; the `Application` schema silently
retains the property, but the controller no longer enforces it.
Pre-existing Applications with their `{ owners: ["admin"], ... }`
patches remain in place — harmless under spec #1's auth-only posture.

## Seed Data

This spec does not introduce new schemas, so no new seed data objects
are required per the ADR-001 data-layer exception for changes that
only modify an existing schema's permission/settings metadata. The
existing `hello-world` Application from spec #1 gains
`permissions = { owners: ["admin"], editors: [], viewers: [] }` via
the repair step in REQ-OBA-007. The migration is part of the repair
pipeline, not a separate seed file.

## Open Questions

- **OQ-1 — OR `groupIn-pointer` authorization vocabulary.** Does
  `x-openregister-authorization` already support a
  `{ groupIn: "<json-pointer-to-array-of-gids>" }` predicate? If
  yes, declare the read rule on the Application schema and the
  manifest check is defence-in-depth; if no, file the OR-side issue
  and the manifest check is primary. *Provisional decision*: ship
  the in-controller check unconditionally (~10 LOC; ADR-005 defence-
  in-depth posture); declare the OR rule additively if the vocabulary
  supports it.
- **OQ-2 — Group rename listener.** Does Nextcloud's `IGroupManager`
  emit a stable rename event we can hook? *Provisional decision*:
  punt. Document the operational caveat in `docs/openbuilt-rbac.md`
  and revisit if a customer reports renamed-group breakage.
- **OQ-3 — Admin-bypass scope.** Should the audited admin bypass
  also cover OR REST direct access to the Application object?
  *Provisional decision*: manifest endpoint only. OR REST admin
  access is already Nextcloud-admin-gated; a second bypass layer
  adds bypass-of-bypass complexity.
- **OQ-4 — Default for the `hello-world` Application post-migration.**
  Post-migration it lands with `owners = ["admin"]`. Should we also
  seed `viewers = ["users"]` so the demo remains visible to everyone?
  *Provisional decision*: no — leaving the demo admin-only is
  conservative and matches the "ACTION REQUIRED" deployment note.
  Operators who want the demo publicly visible can grant it
  explicitly.
- **OQ-5 — Per-page RBAC follow-up.** If `openbuilt-page-editor`
  (chain spec #5) introduces per-page review workflows, a `reviewer`
  role may be needed. *Provisional decision*: defer to chain spec #5.
  This spec's role table is the v1 contract.
- **OQ-6 — Permission-history retention.** Should OpenBuilt set a
  minimum retention on the `openbuilt` register so permission history
  is always queryable for the standard audit window?
  *Provisional decision*: defer to deployment guidance; Conduction's
  compliance baseline (ISO 27001) likely already pins this at the
  OR-register level.
