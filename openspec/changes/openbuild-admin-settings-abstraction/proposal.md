---
kind: code
depends_on: []
---

## Why

Organisation credentials (the OpenRegister credential-broker's `scope=organisation` pane) were wired directly into `CnAppRoot`'s admin dialog and gated on `OC.isUserAdmin()`. Two things are wrong with that: (1) an app's admin surface must gate on the **app's owner group**, not the Nextcloud super-admin flag — a municipality's Buildiq owner is rarely an NC super-admin, and an NC super-admin is not necessarily entitled to a given app's org secrets; and (2) admin settings should be a **reusable, manifest-driven part of the Buildiq abstraction** that any app declares — not a bespoke org-credentials special case baked into the shell. This change makes admin settings first-class in the manifest and gates them on owner-group membership using the primitives Buildiq already ships.

## What Changes

- **Manifest `adminSettings` capability** — the `app-manifest-v2` schema (in nextcloud-vue at `src/schemas/app-manifest-v2.schema.json`) gains an `adminSettings` array, parallel to `pages`/`menu`. Each entry declares an admin-only settings section: either a built-in `type` (first built-in: `organisation-credentials`) or a custom section (`component` from the registry). The existing `action` enum value `admin-settings` (a menu entry that opens the admin dialog), the per-item `permission` field, and the `runtime.user` role object are **reused** — no parallel permission model is introduced.
- **Generic admin-settings surface in `CnAppRoot` + `CnAppNav`** — `CnAppRoot` renders a second, generic `NcAppSettingsDialog` populated from `manifest.adminSettings[]` (parallel to the existing personal user-settings dialog). The built-in `organisation-credentials` type renders `CnCredentials scope="organisation"`; custom types resolve a component from the registry. `CnAppNav` auto-includes an "Admin settings" entry (or honours a manifest entry with `action: "admin-settings"`) that opens this dialog.
- **Owner-group gating (replaces `OC.isUserAdmin()`)** — the auto-included admin nav entry and the admin dialog are shown only when the caller is an **owner** of the app. Ownership is resolved on the frontend as the intersection of the already-published `buildiq.currentUserGroups` initial state with the app's `permissions.owners` (the `group:<gid>` grammar), and/or an owner/role signal on the manifest's `runtime.user` context. `OC.isUserAdmin()` is no longer the gate.
- **Backend owner-status exposure using EXISTING primitives** — Buildiq already publishes `buildiq.currentUserGroups` (DashboardController) and already populates `Application.permissions.owners` (default group `admin`, via `PopulateApplicationPermissions`) with `PermissionResolver`'s `group:<gid>` grammar and `matchesCaller()`. The owner signal surfaced to the frontend is derived from these — no new permission model, no change to `PermissionResolver`'s grammar.
- **No BREAKING changes.** An app with no `adminSettings` shows **no** admin surface (the auto nav entry and dialog do not render). The personal user-settings surface (Credentials + Notifications) is untouched. The OpenRegister credential-broker backend (org-scope already implemented) is untouched.

## Capabilities

### New Capabilities
- `manifest-admin-settings`: The `adminSettings` array in `app-manifest-v2` (built-in `type` incl. `organisation-credentials`, or a custom `component` section; reuse of the `admin-settings` action value and per-item `permission`), and the generic `NcAppSettingsDialog` that `CnAppRoot`/`CnAppNav` render from it — including the built-in `organisation-credentials` → `CnCredentials scope="organisation"` mapping and the backward-compat rule (no `adminSettings` ⇒ no admin surface).
- `admin-settings-owner-gating`: The owner-group resolution that gates the admin nav entry + dialog — intersection of `buildiq.currentUserGroups` with the app's `permissions.owners` (and/or the manifest `runtime.user` owner/role signal) — replacing `OC.isUserAdmin()`, reusing Buildiq's `PermissionResolver` grammar and `PopulateApplicationPermissions` owner default without inventing a parallel model.

### Modified Capabilities
_None._ No existing Buildiq capability's REQUIREMENTS change. The json-manifest-renderer spec that `CnAppNav` references (REQ-JMR-004) lives in the nextcloud-vue repo, not here; the manifest-schema edit is thin declarative glue to the renderer and ships coupled with it in this change (see design.md "Kind decision").

## Impact

- **Manifest schema (nextcloud-vue):** `src/schemas/app-manifest-v2.schema.json` — add the `adminSettings` array + its `$defs` (built-in `organisation-credentials`, custom-section shape). Additive; existing manifests validate unchanged.
- **Frontend (nextcloud-vue):** `CnAppRoot.vue` — a second generic `NcAppSettingsDialog` driven by `manifest.adminSettings[]`, owner-gated; `CnAppNav.vue` — auto-included owner-gated "Admin settings" entry / `action: "admin-settings"` handler (REQ-JMR-004). The org-credentials pane moves from a hardcoded child to the built-in `organisation-credentials` renderer (`CnCredentials scope="organisation"`).
- **Backend (buildiq):** no new permission model. Reuse `DashboardController`'s `buildiq.currentUserGroups`, `Application.permissions.owners` (`PopulateApplicationPermissions`, default `admin`), and `PermissionResolver::matchesCaller()`/`group:<gid>` grammar. Any owner-status field added to `runtime.user` is derived from these existing primitives.
- **Non-goals:** no change to the personal user-settings surface; no change to the OpenRegister credential-broker backend; no change to `PermissionResolver`'s grammar.
- **Foundational ADRs:** ADR-024 (manifest is the app contract) — `adminSettings` is a manifest capability; ADR-022 (consume OR abstractions) — owner data comes from OR-backed `Application.permissions`; org-wide ADR-031 (declarative-vs-imperative) — N/A, no OR declarative behaviour (UI + permission plumbing only); ADR-032 (spec sizing) — single `code` spec, thin-glue manifest edit, see design.md.
- **Hydra gates:** initial-state (owner data via `loadState`, not DOM attrs), nc-input-labels (any new selects), modal-isolation (dialog composition), spec-coverage + e2e traceability on the new scenarios.
