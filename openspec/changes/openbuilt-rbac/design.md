## Context

`bootstrap-openbuilt` (spec #1 of the 9-spec OpenBuilt chain)
**explicitly deferred** per-virtual-app RBAC. Its design.md Open
Question OQ-2 — "Permission key for the OpenBuilt top-bar entry" —
landed with the provisional decision "auth-only; let admins narrow
via NC's group restrictions; per-built-app RBAC lands in chain spec
#7". This is that spec.

Without this layer, every authenticated user in an organisation
shares full access to every virtual app: list, open, edit the
manifest, publish, archive, and (when chain spec #6's versioning
arrives) roll back. That is not a posture you ship past the
single-integrator phase. Once
`openbuilt-page-editor` (chain spec #5),
`openbuilt-versioning` (chain spec #6),
`openbuilt-templates-marketplace` (chain spec #8), and
`openbuilt-export-to-real-app` (chain spec #9) start chaining
destructive actions, the absence of a real authority gradient
becomes an active liability — both as a workflow hazard (someone
publishes the wrong draft) and as a security gap (cross-team
manifest editing inside a shared organisation, OWASP A01:2021).

OpenRegister already provides the **outer** authorization boundary:
organisation-scoped multi-tenancy (ADR-022). What this spec adds is
the **inner** boundary — within a single organisation, distinct
teams co-own distinct virtual apps. The model is the conventional
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
  empty. Idempotent migration populates existing
  Applications (the `hello-world` seed) with `admin` as owner.
- Enforce the role check **server-side** on
  `ApplicationsController::getManifest`: deny-by-default with
  `403` when no role intersection; ordered before the manifest-body
  branch so no payload leaks. Single in-controller check — no
  authorization service class.
- Filter the frontend Application list view to only show
  Applications the caller has at least viewer on. Prefer OR-side
  filtering via `x-openregister-authorization` if available;
  otherwise filter in JS using groups echoed via `loadState` per
  ADR-004.
- Gate destructive / write actions in the editor UIs via a shared
  `useRole(application)` composable, with the canonical role →
  action matrix documented in REQ-OBRBAC-004.
- Support a transfer-ownership flow that is just an
  `permissions.owners` write — no dedicated endpoint or service.
- Declare a global `openbuilt.use` group-permission on the
  `<navigations>` entry, default unrestricted, admin-grantable
  through Nextcloud's standard mechanism. Closes spec #1's OQ-2.
- Surface a "Permission history" panel to `owner`-role holders
  backed by OR's existing per-object audit trail (ADR-022) — no
  app-local audit duplication.

**Non-Goals**

- **Fine-grained per-field or per-page permissions inside a
  manifest.** This spec gates Application-level access only. If a
  later spec needs per-page RBAC, it layers on top of this one.
- **A custom role system or role-renaming.** Three fixed roles —
  `owner`, `editor`, `viewer`. No admin UI for adding new roles.
- **A new `RbacService` / `AuthorizationService` class.** Forbidden
  by ADR-031; the enforcement check is a single in-controller method
  block that ADR-022 §Exceptions(1) permits.
- **Authentication.** Nextcloud handles auth; this spec only
  handles authorization on top of an already-authenticated session.
- **Cross-organisation sharing.** Out of scope; OR's organisation
  boundary stays in force above this layer.
- **OR schema changes beyond the new `permissions` property.** No
  new schemas. No new relations engine. No new lifecycle states.

## Decisions

### Decision 1 — Enforcement layer: OR authorization extension vs thin app-local check

We prefer to express the role check as an
`x-openregister-authorization` rule on the `Application` schema so
that the rule travels with the schema, is enforceable at the OR
REST list endpoint (no leaks at all), and benefits from OR's
existing audit / caching / org-scope plumbing automatically. The
rule shape would be approximately:

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
endpoint's check becomes redundant (OR rejects the read before we
get to the controller). We still ship the in-controller check as a
**defence-in-depth belt-and-braces**, per ADR-005.

**If OR's vocabulary does not yet express this rule**, the
manifest-endpoint check is the primary enforcement point and is
the documented ADR-022 §Exceptions(1) thin glue. We file an
OR-side issue requesting the
`groupIn-pointer` authorization extension and link it from this
spec's tasks.md. The frontend list filter falls back to JS-side
filtering using `loadState('openbuilt', 'currentUserGroups')`.

The choice is **observed during apply**, not pre-decided here; both
paths satisfy every requirement REQ-OBRBAC-002 / REQ-OBR-006 /
REQ-OBR-007 from the user's perspective. The apply agent SHALL
record the chosen path in `hydra.json` under `decisions[]` for
self-learning.

**Alternatives considered**

- *Write `OpenBuiltAuthorizationService` and route all reads
  through it*. Rejected. ADR-031 forbids authorization service
  classes; ADR-022 forbids wrapping OR's REST endpoints. The thin
  in-controller check on the one PHP endpoint we already own
  (`getManifest`) is the only PHP we should ship.
- *Encode permissions inside the manifest blob itself*. Rejected.
  The manifest is the **content** of a virtual app; permissions
  are about the **container**. Conflating them makes versioning
  (chain spec #6) clumsy and breaks the "manifest is the rendered
  thing" mental model from ADR-024.

### Decision 2 — Default permissions on creation

When a new `Application` is created without an explicit
`permissions` value, the system populates:

- `permissions.owners` = `[creator's primary Nextcloud group]`
- `permissions.editors` = `[]`
- `permissions.viewers` = `[]`

If the creator has no group memberships at all (unusual but
possible — e.g. a service account), `permissions.owners` falls back
to `["admin"]` so the Application is never orphaned in a "no
owner" state where REQ-OBRBAC-005's orphan-check would be the only
guardrail.

The default is computed in one place — the existing seed / write
path on the Application object — using `IGroupManager`. There is no
"DefaultPermissionsService"; the default is computed inline.

**Alternatives considered**

- *Default to a single ad-hoc per-Application group created at
  Application-creation time*. Rejected. Doubles the Nextcloud
  group-namespace pressure (every virtual app gets a new group);
  loses the "use my team" mental model; users who already
  collaborate via a Nextcloud group expect to inherit that group's
  membership.
- *Default to "no owner" and force the creator to fill it in via a
  modal*. Rejected. Bad UX, race-prone, and a forgotten modal
  leaves the Application unreachable.

### Decision 3 — Group resolution uses Nextcloud's IGroupManager, not an app-local abstraction

Permissions store Nextcloud `gid` strings directly. Resolution at
check time uses `IGroupManager::getUserGroups($user)` →
`array_intersect($userGids, $applicationAuthorisedGids)`. No
app-local group abstraction, no caching layer beyond what Nextcloud
already provides, no `OpenBuiltGroupService`.

The trade-off: the permissions block is tied to whatever
Nextcloud's group model expresses today. If a future Conduction
spec introduces a richer "team" or "organisation unit" abstraction,
this spec's `gid` arrays may need a migration. That's an
acceptable forward cost compared to building a parallel group
model now.

**Alternatives considered**

- *Build an `OpenBuiltTeam` abstraction over Nextcloud groups for
  forward compatibility*. Rejected. YAGNI per ADR-031, and adds a
  new schema (forbidden by this spec's non-goals).
- *Use Nextcloud Circles instead of Groups*. Rejected. Circles
  aren't a baseline assumption across Conduction's target deploys;
  Groups are.

### Decision 4 — `openbuilt.use` mechanism: Nextcloud's existing navigation permission

We answer spec #1's OQ-2 by leaning on Nextcloud's
**already-existing**
`<navigations>/<permission>` mechanism in `appinfo/info.xml`. No
new admin-settings page is shipped by this spec. An administrator
configures the entry via Nextcloud's standard "Admin → Apps →
OpenBuilt → Restrict to groups" UI.

Default: no restriction → entry visible to every authenticated
user. This preserves spec #1's auth-only posture for installs that
never touch the setting.

Important: this permission gates **only the navigation entry**. It
does not (and must not) replace the per-Application `permissions`
enforced server-side. A user who has `openbuilt.use` but no role on
any Application sees an empty list, not an error — REQ-OBR-007's
empty-state UI handles this.

**Upstream schema gap (logged 2026-05-11)** — We tried shipping
`<permission>openbuilt.use</permission>` as a child of `<navigation>`
and verified via `occ app:enable openbuilt --force` (Nextcloud 32-dev)
that the upstream `apps/info.xsd` schema rejects the element
("appinfo file cannot be read"). Tracking issue filed at
[nextcloud/server#60310](https://github.com/nextcloud/server/issues/60310).

Until the upstream schema accepts the element, REQ-OBRBAC-006's
navigation gate ships in **fallback mode** only: operators restrict
top-bar visibility via the app-level group restriction
(`occ app:enable openbuilt --groups <group>`), which is coarser
(restricts the whole app, not just the entry) but available today.
The per-Application server-side RBAC enforced by
`ApplicationsController::getManifest` + `::listMine` is the
load-bearing security boundary either way; the navigation gate is
coarse top-bar visibility only. When upstream #60310 lands, we
re-add the `<permission>` element and amend this decision.

**Alternatives considered**

- *Ship a new `Settings/AdminSettings.php` and a Vue admin page
  to manage `openbuilt.use`*. Rejected. Net-new infrastructure for
  a setting Nextcloud already exposes through its standard apps
  panel. Violates "ride the OS" — ADR-022 in spirit.
- *Skip the navigation permission entirely; rely only on
  per-Application `permissions`*. Rejected. Useful as a coarse
  on/off for organisations that want to disable OpenBuilt
  visibility for non-builder users without revoking individual
  Application roles. Cheap to ship via the existing mechanism.

### Decision 5 — Admin bypass: audited, narrow, controller-only

A user in the Nextcloud `admin` group bypasses the
per-Application `permissions` check on the manifest endpoint. The
bypass:

- Runs **only** in `ApplicationsController::getManifest`. The
  frontend list filter does **not** include admins automatically
  (admins see the list filtered by their own group membership; if
  they want to see all Applications, they list via OR REST).
- Records a `rbac.admin_bypass` audit event in OR's audit trail
  every time it is exercised, naming the actor, the slug, the
  organisation, and the timestamp (REQ-OBRBAC-006 / REQ-OBR-006).

The narrowness is deliberate: the bypass is an incident-response
escape hatch, not a general convenience. The audit trail makes
every exercise of it reviewable, which is the only thing that
keeps the escape hatch from becoming a hidden parallel auth
pathway. If the audit volume reveals admins routinely using the
bypass for non-incident work, that's a signal to grant them
explicit per-Application roles instead.

**Alternatives considered**

- *No admin bypass; admins must be granted explicit roles like
  anyone else*. Tempting and cleaner, but breaks the operational
  reality that an admin sometimes needs to read a virtual app's
  manifest to diagnose a problem when its owners are unreachable.
  Reject for v1; revisit if the audit trail shows the bypass
  isn't being used.
- *Silent admin bypass with no audit*. Rejected. Indistinguishable
  from a backdoor.

### Decision 6 — Permission history visibility: owner-only

The "Permission history" panel — which renders OR's per-object
audit trail filtered to permission changes — is visible only to
users with `owner` role on the Application. Editors and viewers do
not see the panel. The same gate applies to any direct API call
that backs the panel; the frontend cannot fetch and silently hide
data.

The reasoning: permission history is itself sensitive
(it shows which groups had which access at which time, and which
admins have exercised bypass). Surfacing it to editors and viewers
leaks the org chart and the incident response trail. Owners are
the only role with a legitimate need to audit who they've
delegated to.

**Alternatives considered**

- *Make permission history visible to editors too*. Rejected.
  Editors are functionally collaborators; they don't need to see
  the access-grant audit trail.
- *Make it visible to all roles*. Rejected. Worse for the reasons
  above.

## Declarative-vs-imperative

The whole RBAC layer is **declarative metadata** plus **one
thin-glue PHP check** plus **one thin-glue Vue composable**:

| Behaviour | Path |
|---|---|
| `permissions` shape on Application | **Declarative** — JSON Schema in `lib/Settings/openbuilt_register.json` |
| Default-on-creation | **Inline** — computed once in the seed / write path using `IGroupManager`; no service class |
| Read enforcement (manifest endpoint) | **Thin glue** — single `if (!intersect) { return 403 }` in `ApplicationsController::getManifest`; ADR-022 §Exceptions(1). Promotes to OR-declarative if `x-openregister-authorization` supports `groupIn-pointer` semantics. |
| List filtering | **Declarative-preferred** — `x-openregister-authorization` if available; otherwise thin JS filter consuming `loadState` |
| Editor action gating | **Thin glue** — `useRole(application)` composable in `src/composables/useRole.js`, ~15 LOC, returns `'owner' | 'editor' | 'viewer' | 'none'` |
| Transfer ownership | **Declarative** — it's a `permissions.owners` PUT; no dedicated endpoint |
| Audit trail | **Inherited** — OR's existing per-object audit per ADR-022; the panel is a read view, not a write |
| `openbuilt.use` navigation gate | **Declarative** — `<navigations><permission>openbuilt.use</permission></navigations>` in `appinfo/info.xml` |

**Anti-patterns explicitly avoided.** This spec ships **no**:
- `OpenBuiltAuthorizationService.php` / `RbacService.php` /
  `PermissionService.php`. The check is in the controller.
- Custom role names or a role registry. Three fixed roles.
- Per-page or per-field permission engine.
- Parallel audit trail. OR's audit trail is the only audit trail.
- Frontend role state machine. `useRole(app)` is a pure derivation.

## Risks / Trade-offs

- **Risk** — *Frontend list filter (fallback path) can race the
  user's group membership.* If an admin removes a user from a group
  between page load and a click, the frontend may still display
  Applications the user no longer has access to; the click then
  hits the manifest endpoint and gets a 403. → Mitigation:
  REQ-OBR-006 ensures the 403 path is well-defined and surfaces a
  "your access was revoked" toast; the frontend cache invalidates
  on next list-refresh. Acceptable for v1.
- **Risk** — *Group renames silently break permissions.* If a
  Nextcloud admin renames a group, every Application whose
  `permissions` array references the old `gid` loses or gains
  rows without an audit signal scoped to OpenBuilt. → Mitigation:
  Nextcloud's group rename emits an `IGroupManager` event we
  consume in a thin one-method listener (only if needed during
  apply; provisional decision is to not ship the listener in this
  spec and instead document the operational caveat in the admin
  guide).
- **Risk** — *Admin-bypass volume hides genuine admin abuse.* If
  admins routinely use the bypass for non-incident work, the audit
  trail becomes noise. → Mitigation: a dashboard widget in
  MyDash (out of scope here, tracked as a roadmap item) can
  surface bypass volume per admin per week.
- **Trade-off** — *No per-page permissions.* Acceptable for v1;
  every virtual app is small enough that Application-level RBAC is
  the right grain. If a virtual app grows to the point that
  per-page gating matters, that's a signal it should be split into
  multiple virtual apps.
- **Trade-off** — *Three roles, no custom roles.* Keeps the model
  legible; covers the cases real users actually need (read,
  collaborate, govern). The cost of adding a fourth role later
  (e.g. `approver` for the page-editor's review flow) is one
  schema migration plus one row in the role-action matrix.

## Migration Plan

This change extends the `Application` schema and adds enforcement to
the existing manifest endpoint. The migration runs as part of the
existing OpenBuilt repair-steps pipeline:

1. The schema-update repair step (already in place from spec #1 via
   `ConfigurationService::importFromApp()`) re-imports the register
   configuration and adds the new `permissions` property to the
   `Application` schema in OR.
2. A new repair step,
   `lib/Repair/PopulateApplicationPermissions.php`, scans every
   `Application` whose `permissions` is null or missing and patches
   it to `{ owners: ["admin"], editors: [], viewers: [] }`. The
   step is idempotent (skips already-populated rows) and bulk —
   one OR REST round-trip per Application.
3. The repair step ordering is `<post-migration>` so it runs after
   the schema has been re-imported.
4. The `ApplicationsController::getManifest` enforcement code lands
   in the same deploy; admins should expect that, post-deploy,
   every previously-readable virtual app is now readable only by
   `admin` group members until owners are reassigned.

**Communication to operators**: the release note for this change
SHALL include a section titled "ACTION REQUIRED: re-grant access
after upgrade" with the OR REST command to bulk-update
`permissions` for known cases.

**Rollback** — revert the deploy. The schema's `permissions`
property is optional so no data is lost; the `Application` schema
silently retains the property, but the controller no longer
enforces it. Pre-existing Application rows with their
`{ owners: ["admin"], ... }` patches remain in place, which is
harmless under spec #1's auth-only posture.

## Seed Data

This spec does not introduce new schemas, so no new seed data per
ADR-001 beyond the migration of the existing `hello-world`
Application from spec #1, which gains
`permissions = { owners: ["admin"], editors: [], viewers: [] }` via
REQ-OBA-007's migration step. The migration is part of the repair
pipeline, not a separate seed.

## Open Questions

- **OQ-1 — OR `groupIn-pointer` authorization vocabulary.** Does
  `x-openregister-authorization` already support a
  `{ groupIn: "<json-pointer-to-array-of-gids>" }` predicate? If
  yes, declare the read rule on the Application schema and the
  manifest-endpoint check is defence-in-depth; if no, file the
  OR-side issue and the manifest check is the primary
  enforcement. *Provisional decision*: ship the in-controller
  check unconditionally (it's ~10 LOC, and defence-in-depth is the
  right ADR-005 posture); declare the OR rule additively if the
  vocabulary supports it.
- **OQ-2 — Group rename listener.** Does Nextcloud's
  `IGroupManager` emit a stable rename event we can hook? If
  yes, do we ship a one-method listener in this spec or punt to a
  follow-up? *Provisional decision*: punt. Document the operational
  caveat in `docs/openbuilt-rbac.md` and revisit if a customer
  reports renamed-group breakage.
- **OQ-3 — Admin-bypass scope.** Should the audited admin bypass
  also cover OR REST direct access to the Application object, or
  only the manifest endpoint? *Provisional decision*: only the
  manifest endpoint. OR REST access for admins is already a
  Nextcloud-admin-only path; layering another bypass under it is
  redundant and risks bypass-of-bypass complexity.
- **OQ-4 — Default for the `hello-world` Application post-migration.**
  Spec #1 seeded `hello-world` as a published demo. Post-migration
  it lands with `owners = ["admin"]`. Should we additionally
  seed `viewers = ["users"]` so the demo remains visible to
  everyone? *Provisional decision*: no — leaving the demo
  admin-only after the upgrade is conservative and matches the
  "ACTION REQUIRED" deployment note. Operators who want the demo
  publicly visible can grant it explicitly.
- **OQ-5 — Per-page RBAC follow-up.** If `openbuilt-page-editor`
  (chain spec #5) introduces per-page review workflows, those
  workflows may need a `reviewer` role. *Provisional decision*:
  defer to chain spec #5. This spec's role table is the v1
  contract; spec #5 extends it if needed.
- **OQ-6 — Permission-history retention.** OR's audit trail
  retention is configurable per register. Should OpenBuilt set a
  minimum retention (e.g. 1 year) for the `openbuilt` register so
  permission history is always queryable for the standard audit
  window? *Provisional decision*: defer to deployment guidance;
  Conduction's compliance baseline (ISO 27001) likely already
  pins this at the OR-register level.
