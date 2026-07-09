## Context

OpenBuild apps are rendered by mounting `CnAppRoot` (nextcloud-vue) with the app's `app-manifest-v2` manifest (ADR-024). `CnAppRoot` already hosts a **personal** user-settings surface: a single `NcAppSettingsDialog` that any descendant opens via the `cnOpenUserSettings` inject, wired by `CnAppNav` to manifest menu entries carrying `action: "user-settings"`. Its default content is `CnNotificationPreferences` plus (soon) a personal Credentials pane.

Separately, the OpenRegister credential broker exposes an **organisation-scope** credentials pane (`CnCredentials scope="organisation"`) for secrets shared across an organisation. That pane was wired directly into `CnAppRoot`'s admin dialog and shown when `OC.isUserAdmin()` returned true. That is wrong on two axes:

1. **Wrong gate.** It gates on the Nextcloud super-admin flag. The entitled principal is the **app's owner group**, which OpenBuild already models as `Application.permissions.owners` (default group `admin`, populated by `PopulateApplicationPermissions`, matched by `PermissionResolver` using the `group:<gid>` grammar). OpenBuild also already publishes the caller's groups as the `openbuild.currentUserGroups` initial state (DashboardController). NC super-admin and app-owner are different sets.
2. **Wrong abstraction.** Org credentials were a bespoke special-case in the shell. Admin settings should be a **manifest capability** any app declares — parallel to `pages`/`menu` — with `organisation-credentials` merely the first built-in section type.

The `app-manifest-v2` schema already carries the raw materials to reuse: an `action` enum that includes `admin-settings`, a per-item `permission` string on menu leaves, and a `runtime.user` role context object populated by the backend at serve time.

## Goals / Non-Goals

**Goals:**
- Add an `adminSettings` array to `app-manifest-v2` so any app declares admin-only settings sections declaratively (built-in `type` or custom `component`).
- Render those sections in a generic admin `NcAppSettingsDialog` in `CnAppRoot`, with `organisation-credentials` mapping to `CnCredentials scope="organisation"` as the first built-in.
- Gate the admin nav entry + dialog on **owner-group membership** (`currentUserGroups` ∩ `permissions.owners`, and/or the `runtime.user` owner signal), replacing `OC.isUserAdmin()`.
- Reuse existing OpenBuild permission primitives end-to-end; invent nothing parallel.

**Non-Goals:**
- No change to the personal user-settings surface (Credentials + Notifications stay exactly as they are).
- No change to the OpenRegister credential-broker backend (org-scope is already implemented server-side).
- No change to `PermissionResolver`'s grammar or the `permissions` block shape.
- No admin-settings **persistence** surface of its own — a built-in section persists through its own component (org credentials persist via the broker; a custom section owns its own writes).

## Decisions

### D0 — Kind: single `code` spec (thin-glue manifest edit), not a chain

This change touches three surfaces: a JSON manifest-schema field (config), nextcloud-vue Vue components (code), and OpenBuild PHP (code). Per ADR-032, `mixed` is an anti-pattern, so the choice is (a) one `code` spec treating the schema edit as thin declarative glue, or (b) a 2–3 link chain (`…-manifest` config → `…-renderer` code → `…-owner-signal` code) with `depends_on`.

**Decision: one `code` spec.** Rationale:
- The `adminSettings` schema field is inert without the renderer that reads it and does nothing on its own — it is **thin glue coupled to the code**, not an independently valuable config artifact. A config-only change here would ship a schema field no code consumes.
- The backend part is almost entirely **reuse**: `currentUserGroups` is already published, `permissions.owners` is already populated, `PermissionResolver` already exists. The net-new backend surface is at most a derived owner flag on `runtime.user` — too small to be its own chain link.
- All three parts must land together to be testable (a manifest field with no renderer renders nothing; a renderer with no owner signal can't gate). Splitting adds coordination cost disproportionate to the surface.

This is a judgment call — see DEFERRED_QUESTIONS (1). If the human prefers strict config/code separation, the fallback is the chain in (b).

### D1 — `adminSettings` manifest shape

Add a top-level `adminSettings` array to `app-manifest-v2`, sibling to `pages`/`menu`. Concrete shape:

```json
{
  "adminSettings": [
    {
      "id": "org-credentials",
      "type": "organisation-credentials",
      "label": "myapp.admin.orgCredentials",
      "order": 10,
      "permission": "group:owners"
    },
    {
      "id": "billing",
      "component": "AdminBillingSection",
      "label": "myapp.admin.billing",
      "order": 20
    }
  ]
}
```

Field rules (enforced by the `$defs/adminSettingsEntry` schema, `additionalProperties: false`):
- `id` (required, string) — unique within the array; also the `NcAppSettingsSection` id.
- Exactly one of `type` or `component` (a section is either built-in or custom). `type` is a **closed enum** whose only member in this change is `organisation-credentials`; new built-ins extend the enum. `component` names a key in the `CnAppRoot` `registry`/`customComponents` map.
- `label` (required, string) — i18n key / text for the section header (English source key per fleet i18n rule).
- `order` (optional, integer) — sort order within the admin dialog; defaults to array order.
- `permission` (optional, string) — reuses the existing per-item `permission` grammar (`group:<gid>` / `user:<uid>` / bare). When present it further narrows visibility of that section **within** the already owner-gated dialog; when absent the section inherits the dialog's owner gate. `permission` never *widens* access beyond owner.
- `props` (optional, object) — forwarded to a custom `component` (ignored for built-ins).

No `adminSettings` key (or an empty array) ⇒ no admin surface at all (see D4).

### D2 — Generic admin dialog in `CnAppRoot`

`CnAppRoot` mounts a **second** `NcAppSettingsDialog` (distinct from the personal one), opened via a new `cnOpenAdminSettings` inject. It iterates `manifest.adminSettings[]` (sorted by `order`), rendering one `NcAppSettingsSection` per entry:
- `type: "organisation-credentials"` ⇒ `CnCredentials scope="organisation"` (the built-in mapping — the org pane is no longer hardcoded; it is one built-in among the declared sections).
- `component: "<key>"` ⇒ the component resolved from the registry, receiving `props`.
- A per-section `visibleIf`/`permission` narrows a section within the dialog (D1).

The whole dialog — and its trigger — mount only when the caller is an owner (D3) **and** `adminSettings` is non-empty (D4). This follows the existing personal-dialog composition pattern (modal-isolation gate satisfied: the built-in section components live in their own files).

### D3 — Owner-group gating (replaces `OC.isUserAdmin()`)

Resolution, frontend-first using already-published state:

1. Load `openbuild.currentUserGroups` via `loadState('openbuild', 'currentUserGroups', [])` (initial-state, not DOM attributes — hydra initial-state gate).
2. Resolve the app's owner principals from `Application.permissions.owners` (the `group:<gid>` / bare-GID grammar). For an OpenBuilt virtual app these travel with the app record; for the manifest case they are surfaced on `runtime.user` (see D5).
3. **isOwner** = `currentUserGroups` ∩ (GIDs parsed from `permissions.owners`) is non-empty, **or** the manifest `runtime.user` carries an owner/role signal (e.g. `runtime.user.isOwner === true` or a role in `runtime.user.roles` matching an owner bucket).
4. The admin nav entry and the admin dialog render iff **isOwner**.

This mirrors `PermissionResolver::matchesCaller(permissions, caller, userGroups, …, ['owners'])` on the backend — same grammar, same buckets — so the FE gate and any BE guard agree. `OC.isUserAdmin()` is removed from the gate. Whether NC super-admins retain a fallback view is DEFERRED_QUESTIONS (3); provisional decision: **strictly owner-group-only** (no super-admin fallback), because the entire motivation is that super-admin ≠ app owner. Whether ownership may also be declared by a **manifest-level admin-group override** (beyond `permissions.owners`) is DEFERRED_QUESTIONS (2); provisional: **owners only**, no override.

### D4 — Backward compatibility

- An app with **no** `adminSettings` (every current manifest) renders no admin nav entry and no admin dialog — byte-for-byte current behaviour for apps that never had org credentials.
- The personal user-settings dialog, its `action: "user-settings"` wiring, `CnNotificationPreferences`, and the personal Credentials pane are untouched.
- Apps that previously showed the hardcoded org-credentials pane now declare it explicitly as one `adminSettings` entry of `type: "organisation-credentials"`; the render output is equivalent, but now owner-gated instead of super-admin-gated. This is the one intentional behaviour change and is documented as such (an NC super-admin who is not an app owner will no longer see the org pane; an app owner who is not a super-admin now will).

### D5 — Backend owner signal via existing primitives

No new permission model, no `PermissionResolver` grammar change. The frontend gate (D3) needs two inputs, both already produced by OpenBuild:
- `openbuild.currentUserGroups` — already published by `DashboardController::publishCurrentUserGroups()`.
- The app's `permissions.owners` — already populated by `PopulateApplicationPermissions` (default group `admin`) and read via `PermissionResolver`.

For the manifest-render path where `permissions.owners` isn't otherwise in the client, the backend surfaces a **derived** owner flag/role on the manifest `runtime.user` object at serve time, computed with `PermissionResolver::matchesCaller(...['owners'])`. This is a read-only projection of existing state — not a parallel model. `runtime.user` is already the schema's documented home for "user-specific flags and role information."

### Declarative-vs-imperative decision (ADR-031)

**N/A.** ADR-031's declarative triggers are OR object behaviours — lifecycle/state-machines, aggregations, calculations, notifications, relations, widgets. This change is **UI composition + a read-only permission projection**. It introduces no OR schema behaviour, no state machine, no calculation/aggregation, no notification dialect. There is nothing to declare as schema metadata; the work is manifest-schema shape + Vue rendering + reuse of an existing PHP resolver.

### Seed data

**None.** This change introduces or modifies **no** OpenRegister schemas. `adminSettings` is a field on an existing JSON manifest schema (nextcloud-vue), not an OR register/schema; owner data reuses the existing `Application.permissions` shape. No register version bump, no seed rows.

## Risks / Trade-offs

- **Behaviour change for super-admins who aren't app owners** → Documented in D4 as intentional; the whole point is that the gate moves from super-admin to owner. Mitigation: DEFERRED_QUESTIONS (3) lets the human opt into a belt-and-suspenders super-admin fallback before code.
- **Two `NcAppSettingsDialog`s in one shell (personal + admin) could confuse users** → Distinct titles and distinct triggers ("Settings" vs "Admin settings"); admin dialog only appears for owners, so most users never see two.
- **`permission` on a section could be read as *widening* access** → Spec pins it as narrow-only within the owner gate (D1); it can never grant a non-owner the admin surface.
- **Manifest `runtime.user` owner signal must agree with backend `matchesCaller`** → Both use the same `permissions.owners` bucket + `group:<gid>` grammar; the FE intersection is the same computation. A drift test asserts FE-visible == BE-authorised.
- **Empty `adminSettings` vs absent** → Both resolve to "no admin surface"; schema allows empty array, renderer treats empty and absent identically.

## Migration Plan

1. Land the `adminSettings` `$defs` + top-level field in `app-manifest-v2.schema.json` (additive; existing manifests still validate).
2. Land the generic admin dialog + owner gate in `CnAppRoot`/`CnAppNav`, replacing the hardcoded org pane's `OC.isUserAdmin()` gate with the owner resolution.
3. Any app that wants the org-credentials pane adds one `adminSettings` entry of `type: "organisation-credentials"`.
4. **Rollback:** revert the two nextcloud-vue commits; the schema field is additive so reverting it leaves no orphaned data (manifests that declared `adminSettings` simply fail validation until re-reverted or the field is re-added). No backend/data migration to undo — the backend change is a read-only projection.

## Open Questions

See the DEFERRED_QUESTIONS returned with this change: (1) single `code` spec vs config/code chain; (2) owners-only vs manifest-level admin-group override; (3) strictly owner-group-only vs NC super-admin belt-and-suspenders fallback.
