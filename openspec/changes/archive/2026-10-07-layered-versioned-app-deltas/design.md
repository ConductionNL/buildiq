## Context

Buildiq apps carry manifest customization as a keyed `manifestDelta` on an
`ApplicationVersion` (per ADR-002 the version is the deployable runtime; per
`app-delta-override` / `unify-apps-with-app-type` the delta replaces a frozen
blob). For a HYBRID app the delta is layered over an installed Nextcloud fleet
app's bundled BASE manifest, served raw via `GET /api/app-overrides/{appId}` and
merged client-side with `mergeManifestDelta`. Today there are exactly two layers:

1. **BASE** — the installed app's bundled manifest (HYBRID) or a virtual app's
   own resolved base. Given, read-only.
2. **ADMIN (shared)** — one instance-wide delta on the hybrid `Application`'s
   production `ApplicationVersion`. Managed by admins; shared by every user.
   Owned today by `AppOverrideController` / `AppOverrideService` +
   `ApplicationVersion.manifestDelta` / `baseRef`.

There is no third per-user layer. A user cannot tailor their own view on top of
the admin delta, and the app-detail dashboard
(`ApplicationDetailDashboard.vue` + `widgets/`) shows structural widgets
(Schemas / Pages / Menu) rather than the customization layers and their version
history a maintainer reasons about. OpenRegister already provides versioning,
rollback, and time-travel on every object — including `ApplicationVersion` rows —
so version storage must be reused, not reimplemented.

This change adds the **USER (per-user)** layer and reworks the dashboard.

## Goals / Non-Goals

**Goals:**

- Add a third manifest layer: a per-user, per-app delta layered on top of the
  admin delta, applied as `base ⊕ admin-delta ⊕ user-delta`.
- Gate the user layer behind a per-app `allowUserOverrides` flag (default off).
- Keep version storage in OpenRegister — reuse OR versioning / rollback /
  time-travel for both admin and user deltas.
- Enforce fail-closed RBAC: a user manages only their own delta, never another's.
- Rework the app-detail dashboard to surface the delta layers + per-layer version
  state (Manifest widget), a register deep-link (Register widget), and a routed
  Manifest detail page with per-layer version history + rollback.
- Reuse the existing schemas, guards, resolver, and version-history Vue surfaces —
  introduce NO new OpenRegister schema and NO new version-storage code.

**Non-Goals:**

- NOT modelling a register-delta or rebuilding register versioning in Buildiq —
  the Register widget is deep-link only (current state + counts → OpenRegister).
- NOT building the admin/shared delta layer — that is `app-delta-override` +
  `buildiq-inline-edit-persistence`; this change layers on top of them.
- NOT introducing a new OpenRegister schema — the user layer reuses
  `ApplicationVersion`.
- NOT a server-side merge for HYBRID apps — Buildiq does not hold the fleet
  app's bundled base, so the base⊕admin⊕user merge for a HYBRID app stays
  client-side (the served payload is the resolved admin+user delta chain or the
  raw deltas; the bundled base is merged in the loader, per `buildiq-inline-edit-persistence`
  design D2). For VIRTUAL apps where Buildiq owns the base, server-side merge
  follows `app-delta-override`'s `ManifestResolverService`.
- NOT per-user quotas, sharing a user delta with another user, or org-scoped
  (group) deltas — single owner per user-scoped row only.

## Decisions

### Decision 1 — The USER layer is an `ApplicationVersion` row distinguished by `scope` + `owner` (no new schema)

Per the hard constraint, NO new OpenRegister schema is added. A user delta is a
plain `ApplicationVersion` row with `scope: "user"` and `owner: "<uid>"`, carrying
its own `manifestDelta` and chained via `baseRef` to the admin delta. The admin
delta is an `ApplicationVersion` with `scope: "admin"` (the default), exactly as
today. The two scopes share one row-set on the `applicationVersion` schema,
distinguished by the `scope` discriminator and (for user rows) the `owner` UID.
This keeps OR versioning / rollback / time-travel available per-layer for free.

### Decision 2 — Layer chain via the existing `baseRef`, resolved in order

Resolution order is `base ⊕ admin-delta ⊕ user-delta`. The chain is expressed
with the existing `baseRef`:

- The admin `ApplicationVersion.baseRef` points at the BASE (`kind: fleet-app`,
  `id: <appId>` for a hybrid app — unchanged from today).
- The user `ApplicationVersion.baseRef` points at the ADMIN delta
  (`kind: application-version`, `id: <admin-version-uuid>`), so resolution walks
  user → admin → base and applies the deltas BASE-first.

Resolution layers the deltas with the established `mergeManifestDelta` keyed-delta
contract (pages by `page.id`, widgets by `widget.id`, `{ "$op": "remove" }`,
`__order`). The caller's user delta is layered ONLY when `allowUserOverrides` is
`true` AND a user-scoped row owned by the caller exists; otherwise the result is
exactly today's `base ⊕ admin-delta`.

### Decision 3 — User-scope RBAC extends the existing guard + resolver (imperative exception)

User-scope ownership is a cross-row authorization rule (the version's `owner`
versus the caller's UID, plus the parent `Application.allowUserOverrides` gate),
which ADR-031 admits as an imperative exception (§Exceptions(1) cross-row). It is
added to the EXISTING `ApplicationVersionOwnerGuard` and `PermissionResolver`,
not a new service:

- `PermissionResolver` gains a user-scope match: for a `scope: user` row, the
  caller is authorised iff `caller.uid == version.owner` (or the audited NC-admin
  escape hatch). No group logic on a user-scoped row.
- `ApplicationVersionOwnerGuard` gains a branch: when the version under transition
  is `scope: user`, require `caller.uid == owner` (admin bypass audited) AND the
  parent `Application.allowUserOverrides == true`; otherwise it falls through to
  the existing owner-of-parent-Application rule for `scope: admin` rows. Default
  fail-closed: an unresolvable owner, a missing flag, or a foreign owner denies.

A user can never read or write another user's delta: list/read of user-scoped
rows is filtered to `owner == caller.uid`, and the write guard rejects a
`scope: user` payload whose `owner` is not the caller.

### Decision 4 — `allowUserOverrides` is a declarative schema property on `Application`

The per-app permission flag is a plain boolean property `allowUserOverrides`
(default `false`) on the `Application` schema — purely declarative, read directly
by the resolver, the guard, and the dashboard "create override" affordance. No
service. Default-secure: absent ⇒ `false`.

### Decision 5 — Version history / rollback / time-travel reuse OR versioning (no new code)

Each delta row's edit history, rollback, and time-travel are OR's native
object-version features. The Manifest detail page reuses the existing
`src/views/VersionHistory.vue`, `src/components/tabs/ApplicationVersionsTab.vue`,
and `src/modals/RollbackConfirmModal.vue` — pointed at the selected delta row's
uuid — for both admin and user deltas. No new version-storage table, endpoint, or
service is written.

### Decision 6 — Register widget is deep-link only

The Register widget shows the app's OpenRegister register(s) and current object
counts and deep-links into the OpenRegister app for any version / rollback /
time-travel of register DATA. Buildiq models NO register-delta and rebuilds NO
register versioning — this is purely a read + deep-link surface, consistent with
the existing `RegisterWidget.vue` "Open in OpenRegister" pattern.

### Decision 7 — "create override" affordance copies an EMPTY delta seeded from the admin chain's `baseRef`

When `allowUserOverrides` is on and the caller has no user delta, the dashboard
shows a "create override" affordance. Creating it writes a new
`ApplicationVersion` with `scope: user`, `owner: <caller-uid>`,
`manifestDelta: {}` (empty — a no-op that resolves to exactly the current
admin-resolved view), and `baseRef` pointing at the admin delta version. An empty
user delta is the valid "I have an override but haven't changed anything yet"
state, mirroring the existing empty-delta handling in `AppOverrideController`.

### Declarative-vs-imperative decision (ADR-031)

Default path is declarative (schema property / `x-openregister-validation`);
imperative pieces are justified per ADR-031 §Exceptions.

| Behaviour | Placement | Why |
|---|---|---|
| `scope` discriminator on `ApplicationVersion` (enum `admin`\|`user`, default `admin`) | **Declarative** — schema property in `openbuild_register.json` | Pure data field read directly by resolver/guard/UI; no logic. |
| `owner` field on `ApplicationVersion` (UID, set on `scope:user` only) | **Declarative** — schema property + `x-openregister-validation` rule tying `owner` to `scope == 'user'` (same-row assert) | Same-row presence rule is exactly what `x-openregister-validation` covers (cf. existing `hybrid-requires-baseRef` / `promotesTo-no-self-loop`). |
| `allowUserOverrides` flag on `Application` (boolean, default `false`) | **Declarative** — schema property | Pure data gate read directly; no service. |
| Layered manifest resolution `base ⊕ admin ⊕ user` | **Imperative (existing path)** — extend the established merge/serve path (`AppOverrideService` / `ManifestResolverService` for virtual apps; the served-delta chain for hybrid apps) | Stateful multi-row merge over a chain is outside the declarative calc vocabulary (ADR-031 §Exceptions(2)); reuses the existing PHP `mergeManifestDelta` port from `app-delta-override`, no new merge engine. |
| User-delta RBAC (owner-only, flag-gated, fail-closed) | **Imperative exception** — extend `ApplicationVersionOwnerGuard` + `PermissionResolver` | Cross-row authorization (version.owner vs caller, parent flag) — ADR-031 §Exceptions(1); MUST extend, not duplicate, the existing guard/resolver. |
| Version history / rollback / time-travel per layer | **No new code** — reuse OR object versioning + the existing `VersionHistory.vue` / `ApplicationVersionsTab.vue` / `RollbackConfirmModal.vue` | OR already provides this; reimplementing it is forbidden by the hard constraints. |

### Schema-extension shape (exact)

In `lib/Settings/openbuild_register.json`:

**`ApplicationVersion`** gains:

```jsonc
"scope": {
  "type": "string",
  "enum": ["admin", "user"],
  "default": "admin",
  "description": "Delta scope discriminator. `admin` (default) = the instance-wide shared delta managed by admins (today's behaviour). `user` = a per-user delta owned by a single user, layered over the admin delta and resolved only when the parent Application's allowUserOverrides is true. An ApplicationVersion with no scope reads as `admin` (legacy default)."
},
"owner": {
  "type": "string",
  "description": "Owning Nextcloud user UID. Set ONLY on a `scope: user` row; identifies the single user who may read/edit/rollback this user-scoped delta. Empty/absent on `scope: admin` rows. Read by ApplicationVersionOwnerGuard + PermissionResolver for the user-scope ownership check."
}
```

and an `x-openregister-validation` rule:

```jsonc
{
  "id": "user-scope-requires-owner",
  "description": "A user-scoped delta MUST name its owner UID. Same-row rule.",
  "when": "scope == 'user'",
  "assert": "owner != null",
  "message": "A user-scoped ApplicationVersion requires an owner UID."
}
```

**`Application`** gains:

```jsonc
"allowUserOverrides": {
  "type": "boolean",
  "default": false,
  "description": "Per-app permission flag. When true, users of this app MAY create/edit their own user-scoped manifest delta layered over the admin delta (base ⊕ admin ⊕ user). Default false (default-secure) — absent reads as false. Read directly by the resolver, ApplicationVersionOwnerGuard, and the dashboard 'create override' affordance; purely declarative."
}
```

**`baseRef` chaining / resolution order:** unchanged shape. The admin delta's
`baseRef` points at the BASE (`kind: fleet-app`, hybrid). The user delta's
`baseRef` points at the admin delta (`kind: application-version`, `id:
<admin-version-uuid>`). Resolution walks user → admin → base and applies deltas
BASE-first: `merge(merge(base, adminDelta), userDelta)`.

## Seed Data (ADR-001)

Realistic objects per modified schema using general-organization data, `@self`
envelope, SAFE placeholder UUIDs only (nil UUID `00000000-0000-0000-0000-000000000000`
and `<PLACEHOLDER-…>` forms). Seeded into the `buildiq` register on install.

**`Application` (hybrid, user-overrides enabled) — a municipality customizing the catalog app:**

```jsonc
{
  "@self": { "register": "buildiq", "schema": "application" },
  "slug": "opencatalogi",
  "name": "Open Catalogi",
  "appType": "hybrid",
  "allowUserOverrides": true,
  "baseRef": { "kind": "fleet-app", "id": "opencatalogi" },
  "productionVersion": "<PLACEHOLDER-ADMIN-VERSION-UUID>",
  "permissions": { "owners": ["group:gemeente-redactie"], "editors": [], "viewers": [] }
}
```

**`ApplicationVersion` (admin scope) — the shared delta the municipality's editors maintain:**

```jsonc
{
  "@self": { "register": "buildiq", "schema": "applicationVersion", "id": "<PLACEHOLDER-ADMIN-VERSION-UUID>" },
  "name": "Production",
  "slug": "production",
  "scope": "admin",
  "manifest": {},
  "manifestDelta": { "pages": { "dashboard": { "title": "Catalogusbeheer" } } },
  "baseRef": { "kind": "fleet-app", "id": "opencatalogi" },
  "register": "buildiq-opencatalogi",
  "semver": "0.1.0",
  "status": "published",
  "application": "<PLACEHOLDER-APP-UUID>"
}
```

**`ApplicationVersion` (user scope) — one editor's personal tweak layered on top:**

```jsonc
{
  "@self": { "register": "buildiq", "schema": "applicationVersion" },
  "name": "Mijn weergave",
  "slug": "user-jdvries",
  "scope": "user",
  "owner": "j.devries",
  "manifest": {},
  "manifestDelta": { "pages": { "dashboard": { "widgets": { "recent-uploads": { "$op": "remove" } } } } },
  "baseRef": { "kind": "application-version", "id": "<PLACEHOLDER-ADMIN-VERSION-UUID>" },
  "register": "buildiq-opencatalogi",
  "semver": "0.1.0",
  "status": "draft",
  "application": "<PLACEHOLDER-APP-UUID>"
}
```

**`Application` (overrides disabled) — a consultancy's internal intake app, default-secure:**

```jsonc
{
  "@self": { "register": "buildiq", "schema": "application" },
  "slug": "intake-tracker",
  "name": "Intake Tracker",
  "appType": "virtual",
  "allowUserOverrides": false,
  "permissions": { "owners": ["group:consultancy-ops"], "editors": [], "viewers": [] }
}
```

A travel-agency booking-board example follows the same shape (`allowUserOverrides:
true`, one admin delta + one user delta) and is seeded for the component tests.

## Risks / Trade-offs

### Risk 1: Cross-user delta leakage (IDOR) — Severity: High

A bug in the owner filter or guard could let user A read or roll back user B's
delta. **Mitigation:** the guard extension is fail-closed (unresolvable owner /
foreign owner / missing flag ⇒ deny); list/read of `scope: user` rows is filtered
to `owner == caller.uid` server-side; a dedicated no-admin-idor PHPUnit test
asserts a foreign user gets 403/empty. The check reuses the audited admin-bypass
already in `PermissionResolver`.

### Risk 2: Three-layer merge produces a blank/broken app — Severity: Medium

A user delta could resolve to an empty app over the admin layer. **Mitigation:**
reuse the existing `wouldBlankApp` non-blank guard from `AppOverrideService` on
the resolved user-layer result; fail-soft on orphaned delta paths (skip + surface)
per `app-delta-override`. An empty user delta is explicitly valid (Decision 7).

### Risk 3: Base / admin drift orphans a user delta — Severity: Medium

If the admin delta changes the page/widget a user delta targets, the user delta
patch is orphaned. **Mitigation:** reuse `app-delta-override`'s orphaned-delta
surfacing; the Manifest detail page shows the user delta's version history so the
user can roll back or re-author. `baseRef.manifestVersion` on the user row records
which admin version it was authored against for diagnosability.

### Risk 4: Dashboard rework regresses the existing structural widgets — Severity: Low

Replacing Schemas / Pages / Menu widgets with Manifest + Register widgets changes
the maintainer's cockpit. **Mitigation:** the Pages/Schemas/Menu information is
still reachable via the manifest layers + the builder; the Register widget keeps
the existing "Open in OpenRegister" deep-link; a Playwright visual-validation task
confirms the new dashboard renders on a seeded app.
