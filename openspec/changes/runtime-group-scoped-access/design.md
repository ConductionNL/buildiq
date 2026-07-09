# Design — runtime group-scoped access

## Context
- `CnAppNav` already filters `manifest.menu[]` via `passesPermission(item)`
  against a `permissions` prop (list of permission strings the user holds);
  omitting the prop renders all items. `CnAppRoot` accepts/forwards
  `permissions`.
- `BuilderHost.vue` mounts the nested `CnAppRoot` for the virtual app. It does
  not currently compute or pass `permissions`.
- OpenRegister already enforces object-level RBAC from `schema.authorization`
  server-side (verified: `medicalRecord.authorization.read = ["vets"]` hides
  rows from non-vets; admin bypasses).

## Decisions

### 1. User-group source — server initial state, not a client call
Per ADR-004 (initial state, not DOM/API for bootstrap data), provide the
current user's group IDs via `IInitialState::provideInitialState('user-groups',
...)` from a controller, read with `loadState('openbuild','user-groups')` at
mount. Map to permission strings `group:<gid>` (plus `admin` when the user is in
the admin group, and an `owner` marker when the user owns the application).
Avoid a client round-trip to `/cloud/user/groups`.

### 2. Permission vocabulary
Permission strings on manifest `menu[]`/`pages[]`:
- `group:<gid>` — visible to members of that NC group.
- `admin` — admins only.
- `owner` — the application's owner(s).
Multiple permissions on an item = visible if the user holds ANY (OR semantics),
matching `CnAppNav.passesPermission`. Items with no `permission` are always
visible.

### 3. Pages + dashboards
`CnPageRenderer` filters routed pages the same way; a `permission`-gated page is
not reachable for users without it. For dashboards, the runtime picks the
landing dashboard as the highest-priority dashboard page whose `permission` the
user satisfies (vet dashboard for vets, else the default), keeping a single
default when no group-scoped dashboard matches.

### 4. Security boundary (explicit)
Menu/page hiding is UX only. The authoritative control is OpenRegister schema
RBAC on the objects each page reads. Document in author guidance: "to make data
vets-only, set `schema.authorization`; the `permission` field only hides
navigation." This prevents authors from shipping client-only 'security'.

## Risks
- Initial-state group list can grow; cap to the groups referenced by the
  manifest's `permission` fields to avoid leaking full membership.
- Admin bypass must match OR's (admins see all menus + all objects) for a
  consistent mental model.

## Seed Data
No new schemas. The Pet Store demo manifest gains `permission: "group:vets"` on
the medical menu item(s) and a vet dashboard page; `medicalRecord.authorization`
is set in OpenRegister (already done in the demo).
