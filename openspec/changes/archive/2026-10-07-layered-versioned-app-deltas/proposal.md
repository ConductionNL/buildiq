---
kind: code
depends_on:
  - app-delta-override
  - buildiq-inline-edit-persistence
---

## Why

Buildiq apps — especially HYBRID apps that layer a delta over an installed
Nextcloud fleet app — today support exactly TWO layers of manifest
customization: the installed app's BASE manifest, and one instance-wide ADMIN
delta (the `manifestDelta` on a hybrid `Application`'s `ApplicationVersion`,
served raw via `/api/app-overrides/{appId}` and merged client-side). There is no
way for an individual user to tailor their own view of an app on top of the
admin's shared customization. A maker reordering a dashboard, hiding a widget, or
relabelling a page for *themselves only* has nowhere to store that, and the
app-detail page surfaces structural widgets (Schemas / Pages / Menu) rather than
the customization *layers* and their version history that the maintainer actually
needs to reason about. This change adds the missing USER layer — a per-user,
per-app delta layered on top of the admin delta, gated by a per-app permission
flag — and reworks the app-detail dashboard to surface the three delta layers and
their OpenRegister version history.

## What Changes

- **Extend `ApplicationVersion` with a `scope` discriminator (`admin` | `user`,
  default `admin`) and an `owner` field** (the owning user UID, set only on
  `scope: user` rows) in `lib/Settings/openbuild_register.json`. A delta record
  becomes either admin-scoped (shared, the existing behaviour) or user-scoped
  (owned by exactly one user). NO new OpenRegister schema is introduced — the
  USER layer reuses the existing `ApplicationVersion` delta record, distinguished
  by `scope`/`owner`, chained to the admin delta via the existing `baseRef`.
- **Extend `Application` with an `allowUserOverrides` boolean (default `false`)**
  in `lib/Settings/openbuild_register.json` — the per-app permission flag that
  gates whether users may create/edit their own delta. Default-secure: off unless
  an admin turns it on.
- **Layered manifest resolution** — at runtime the manifest is resolved as
  `base ⊕ admin-delta ⊕ (caller's own) user-delta`, applying each delta in order
  via the existing `mergeManifestDelta` / delta-merge path. The caller's user
  delta is layered ONLY when `allowUserOverrides` is `true` and a user-scoped row
  owned by the caller exists; otherwise the result is exactly today's
  `base ⊕ admin-delta`.
- **User-delta RBAC (no-admin-idor, fail-closed)** — admins manage the shared
  admin delta; a user may create/edit/rollback ONLY their own user delta, and
  ONLY when `allowUserOverrides` is on; a user can NEVER read or write another
  user's delta. The existing `ApplicationVersionOwnerGuard` (lib/Lifecycle/) and
  `PermissionResolver` (lib/Service/) are extended — not duplicated — to cover the
  user-scope ownership rule.
- **Reuse OpenRegister versioning for version history / rollback / time-travel**
  on each delta row — admin and user deltas alike. No new version-storage code.
- **App-detail dashboard rework** (replaces the current Schemas / Pages / Menu
  structural widgets in `src/components/applicationDetail/ApplicationDetailDashboard.vue`
  + `widgets/`):
  - **Manifest widget** — shows the delta LAYERS + version state (Base read-only;
    Admin delta with current version + count; Your delta with current version +
    count OR a "create override" affordance when allowed and none exists). Does
    NOT render raw manifest JSON. Opens the Manifest detail page on click.
  - **Manifest detail page** (new routed page) — all VERSIONS of a selected delta
    (admin or the caller's user delta) via OpenRegister's version history, reusing
    `src/views/VersionHistory.vue`, `src/components/tabs/ApplicationVersionsTab.vue`,
    and `src/modals/RollbackConfirmModal.vue` for view + rollback/time-travel.
  - **Register widget** — DEEP-LINK ONLY: the app's OpenRegister register(s) +
    current counts, deep-linking into the OpenRegister app for
    version/rollback/time-travel. No register-delta is modelled; register
    versioning is NOT rebuilt in Buildiq.
  - Create/edit/rollback modals for a delta (modals in `src/modals/`, dialogs in
    `src/dialogs/` per ADR-004).
- **No BREAKING changes.** Every new field is optional with a today-equivalent
  default: an `ApplicationVersion` with no `scope` reads as `admin`; an
  `Application` with no `allowUserOverrides` reads as `false`. An app with no
  user-scoped delta resolves exactly as it does today.

### Overlap with active changes

This change BUILDS ON two active changes and must not duplicate their work:
`app-delta-override` (the `baseRef` + `manifestDelta` storage model and PHP
merge port for OpenBuilt apps) and `buildiq-inline-edit-persistence` (the
admin/shared `/api/app-overrides/{appId}` store-and-serve for fleet apps). The
ADMIN delta layer is THEIRS. This change adds ONLY the USER layer on top, the
per-app `allowUserOverrides` flag, the user-scope RBAC, and the dashboard
manifest/register/version-history UI. Terminology (`baseRef`, `manifestDelta`,
keyed-delta merge contract, "hybrid app") is kept identical.

## Capabilities

### New Capabilities

- `layered-app-deltas`: The `scope`/`owner` extension on `ApplicationVersion` and
  the `allowUserOverrides` flag on `Application`; the `base ⊕ admin-delta ⊕
  user-delta` layered resolution order chained via `baseRef`; the user-scope RBAC
  guard (fail-closed, no cross-user access) extending `ApplicationVersionOwnerGuard`
  + `PermissionResolver`; the "create override" affordance copy semantics; and the
  reuse of OpenRegister versioning for per-layer version history / rollback /
  time-travel.
- `application-delta-layers-ui`: The app-detail dashboard Manifest widget (delta
  layers + per-layer version state, no raw JSON), the Register widget (counts +
  OpenRegister deep-link, no register-delta), the new routed Manifest detail page
  (per-layer version history + rollback reusing `VersionHistory.vue` /
  `ApplicationVersionsTab.vue` / `RollbackConfirmModal.vue`), and the
  create/edit/rollback modals.

### Modified Capabilities

- `buildiq-rbac`: The per-Application role model gains a user-scope ownership
  rule — a `scope: user` `ApplicationVersion` is writable/readable only by its
  `owner` UID (and an audited admin), and only when the parent `Application`'s
  `allowUserOverrides` is `true`. Enforced fail-closed by the extended
  `ApplicationVersionOwnerGuard`.
- `application-versions`: The `ApplicationVersion` schema gains the optional
  `scope` (enum `admin | user`, default `admin`) and `owner` properties; the
  lifecycle/CRUD contract is unchanged except that user-scoped rows are filtered
  to the owning caller.
- `app-override-persistence`: The store-and-serve contract is extended so the
  served manifest layers the caller's user delta over the shared admin delta when
  `allowUserOverrides` is on; the existing admin-only behaviour is the default.

## Impact

- **Schema:** `lib/Settings/openbuild_register.json` — `ApplicationVersion` gains
  `scope` + `owner` (+ `x-openregister-validation` tying `owner` to `scope:user`);
  `Application` gains `allowUserOverrides`; register/schema `version` bumps.
- **Backend (imperative exceptions only):** extend
  `lib/Lifecycle/ApplicationVersionOwnerGuard.php` for user-scope ownership;
  extend `lib/Service/PermissionResolver.php` with a user-scope match;
  layered-resolution + user-delta resolution in the existing manifest/override
  serve path (`AppOverrideService` / `ManifestResolverService`); a thin
  user-delta endpoint family or scope-aware extension of the existing
  `/api/app-overrides/{appId}` shim.
- **Frontend:** `src/components/applicationDetail/ApplicationDetailDashboard.vue`
  + `widgets/` rework (Manifest widget, Register widget); a new Manifest detail
  page + route + manifest-registry page entry reusing `src/views/VersionHistory.vue`,
  `src/components/tabs/ApplicationVersionsTab.vue`,
  `src/modals/RollbackConfirmModal.vue`; create/edit/rollback modals in
  `src/modals/` / `src/dialogs/`.
- **Hard dependency:** `app-delta-override` + `buildiq-inline-edit-persistence`
  (admin layer) must land first — this change layers on top of them.
- **RBAC / Hydra gates:** no-admin-idor (user-scope rows owner-gated),
  route-auth, modal-isolation, spec-coverage, spec/e2e traceability.
- **Theming / i18n:** no new colours; new user-facing strings (Dutch + English,
  `t()`) for the dashboard widgets, the detail page, and the create/rollback
  modals, per ADR-007.
