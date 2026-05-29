# OpenBuilt RBAC — Per-Virtual-App Permissions

OpenBuilt's per-virtual-app role-based access control (RBAC) layers on
top of OpenRegister's organisation-scoping (ADR-022). Within an
organisation, three roles partition who can do what with each
Application: `owner`, `editor`, `viewer`.

## Roles

| Action                                | viewer | editor | owner |
| ------------------------------------- | :----: | :----: | :---: |
| Read manifest / browse Application    |  yes   |  yes   |  yes  |
| Save manifest draft                   |   no   |  yes   |  yes  |
| Publish (`draft → published`)         |   no   |   no   |  yes  |
| Archive (`published → archived`)      |   no   |   no   |  yes  |
| Re-open (`archived → draft`)          |   no   |   no   |  yes  |
| Edit `permissions`                    |   no   |   no   |  yes  |
| Transfer ownership                    |   no   |   no   |  yes  |
| Delete Application                    |   no   |   no   |  yes  |

Roles are keyed by Nextcloud group ID, stored declaratively on the
Application schema:

```json
{
  "permissions": {
    "owners":  ["team-alpha"],
    "editors": ["team-alpha", "qa-shared"],
    "viewers": ["everyone"]
  }
}
```

A caller's effective role is computed by intersecting their Nextcloud
group membership with the three buckets, taking the highest-privilege
match.

## Default on creation

New Applications default `permissions.owners` to the **creator's
primary Nextcloud group**; `editors` and `viewers` start empty. If the
creator has no group memberships, `owners` falls back to `['admin']`
so the Application is never orphaned (REQ-OBRBAC-001).

## Manifest endpoint enforcement

`GET /index.php/apps/openbuilt/api/applications/{slug}/manifest`
deny-by-defaults to `403 Forbidden` when the caller has no group in
the union of the three buckets (REQ-OBR-006, REQ-OBRBAC-002). The
check runs before the manifest payload is emitted — the 403 body
never leaks Application metadata.

Error envelope:

```json
{ "error": "forbidden", "code": "openbuilt.rbac.no_role" }
```

## Admin bypass (audited)

A user in the Nextcloud `admin` group can read any Application's
manifest as an incident-response escape hatch. Every exercise of the
bypass writes an audit-trail event with shape:

```
event:  rbac.admin_bypass
actor:  <admin uid>
slug:   <application slug>
ts:     <ISO 8601 timestamp>
```

The bypass:

- Runs **only** in `ApplicationsController::getManifest`. The frontend
  list filter does **not** include admins automatically.
- Is logged at `info` level on the OpenBuilt PSR logger (where OR's
  audit-trail will pick it up via Nextcloud's logging pipeline).
- Is narrow by design — sustained bypass volume from one admin is a
  signal to grant them an explicit role on the affected Applications.

## List filter

The OpenBuilt shell's Application list (`ApplicationEditor.vue`)
filters out Applications on which the caller has no role
(REQ-OBR-007). The filter runs client-side using
`loadState('openbuilt', 'currentUserGroups')` (no DOM data-attribute
reads — ADR-004 hard rule `gate-initial-state`).

A future enhancement (DQ-1, see below) will move the filter to
OR-side once `x-openregister-authorization` supports group-membership
predicates.

## Permissions modal

Owner-only modal at `src/modals/PermissionsModal.vue` (per ADR-004
`gate-modal-isolation`). Three NcSelect group pickers, all carrying
the required `input-label` prop (ADR-004 `gate-nc-input-labels`).
Frontend rejects an `owners = []` save before sending — orphan-check
guard per REQ-OBRBAC-005.

## Transfer ownership

A transfer is a single PUT to the Application's `permissions.owners`
array. No dedicated endpoint, no `TransferOwnershipService`. OR's
per-object audit trail records the before / after values
automatically.

## openbuilt.use navigation gate

Nextcloud's per-app group restriction (Apps → OpenBuilt → Restrict to
groups) gates visibility of the OpenBuilt top-bar entry. Default is
no restriction (all authenticated users see it); admins can narrow it
via the standard Apps panel or OCC:

```bash
occ app:enable openbuilt --groups digital-team
```

This is coarse on/off visibility; the load-bearing security boundary
is the per-Application `permissions` enforced server-side.

## Operational caveats

### Group renames

If a Nextcloud admin renames a group, every Application whose
`permissions` array references the old `gid` loses or gains rows
without a permission-history audit event scoped to OpenBuilt. We do
not (currently) ship a group-rename listener. If a rename breaks
access:

1. Edit the affected Application's `permissions` via the Permissions
   modal (as owner).
2. Replace the stale group references with the new GID.

Tracked as design.md OQ-2; revisit if customers report breakage.

### Post-deploy "ACTION REQUIRED: re-grant access"

After upgrading to this version every pre-existing Application is
patched to `permissions.owners = ['admin']` so it is only readable by
the `admin` group. Operators MUST re-grant access for non-admin teams
via the Permissions modal:

1. Sign in as an admin user.
2. Navigate to OpenBuilt → Applications.
3. For each Application that should be broadly accessible, open the
   Permissions modal and add the relevant Nextcloud groups to
   `owners`, `editors`, or `viewers`.

The `hello-world` demo follows the same default (admin-only). Grant
`viewers = ['users']` to restore broad visibility for the demo.

## Deferred Questions

- **DQ-1 — OR `x-openregister-authorization` group-membership predicate.**
  Investigated 2026-05-11; the current OpenRegister REST API does
  **not** expose a `groupIn` / `groupMember` predicate that can be
  parameterised by a pointer into the object's own `permissions`
  block. Until OR ships it, the frontend list filter remains the
  fallback path; the controller's 403 check is the load-bearing
  enforcement. Tracked as design.md OQ-1.
- **DQ-2 — Group rename listener.** Punted; documented above.
- **DQ-3 — Permission-history retention.** Defer to OR-register-level
  retention configuration (Conduction's compliance baseline pins this
  at the OR-register level).
