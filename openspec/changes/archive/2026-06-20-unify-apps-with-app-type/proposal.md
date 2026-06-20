---
kind: split
deferred_question: single-change-vs-chain — see "Change shape" below
supersedes_partial:
  - openspec/architecture/adr-002-versioned-app-deployment-model.md
  - openspec/changes/app-delta-override
  - openspec/changes/openbuild-inline-edit-persistence
---

## Why

OpenBuild today speaks **two vocabularies for the same idea**. A *virtual app* is an `Application` + `ApplicationVersion` pair whose manifest OpenBuild owns and server-merges (the `app-delta-override` `baseRef`+`manifestDelta` model). A *fleet/hybrid app* — a customization layered over an already-installed Nextcloud app (opencatalogi, pipelinq, decidesk, …) — is a wholly separate `AppOverride` record whose delta is merged client-side (`openbuild-inline-edit-persistence`). The two paths have separate schemas, separate endpoints, separate detail UIs, and separate mental models, even though both are "an app the maker shapes by editing a manifest delta." A maker who edits opencatalogi and a maker who builds a from-scratch municipal app are doing the same job through two unrelated surfaces. The split also means the Apps list, the creation wizard, and the detail page only ever know about virtual apps — hybrid apps are invisible in the maker's home.

## What Changes

- **Add an `appType` discriminator (`virtual` | `hybrid`, default `virtual`) and a `baseRef` field to the `Application` schema** (`lib/Settings/openbuild_register.json`). A *virtual* app is the existing from-scratch model. A *hybrid* app is a real `Application` record (`appType: hybrid`, `baseRef` = the installed NC app's appId) whose `ApplicationVersion` carries a **delta-only** manifest, folding the old `AppOverride.manifestDelta` into the existing `baseRef`+`manifestDelta` delta model that `app-delta-override` already implements in JS + PHP.
- **Migrate existing `AppOverride` records → `Application(appType:hybrid)` + `ApplicationVersion(delta-only)`**, idempotently (re-runnable, find-by-baseRef-appId before create). The migration is the one genuinely imperative step (a one-time data transform per ADR-031 §Exceptions).
- **Keep `GET /api/app-overrides/{appId}` as a compatibility shim** that now sources the delta from the hybrid `Application`'s production `ApplicationVersion`, so live fleet apps that fetch + merge the delta client-side (`mergeStrategy:'delta'`) keep working byte-for-byte with no client change. The `PUT`/`DELETE` shims likewise write through to the hybrid Application's version.
- **Metadata-lock invariant for hybrid apps.** App-level identity metadata (id/slug/name) mirrors the underlying NC app and is **read-only**; everything else (pages, widgets, menus, schemas-as-delta) stays editable. The backend SHALL REJECT name/slug edits on a hybrid `Application` (a lifecycle/validation guard, ADR-031 §Exceptions — not a Service).
- **UI: rename user-facing "Virtual apps" → "Apps".** Menu label, route id `VirtualApps`→`Apps` (and `VirtualAppDetail`→`AppDetail`), page titles, `VirtualAppsActions` copy, `BuilderHost` copy. The OR entity stays internally named `Application` (lowest churn) — only user-facing copy becomes "App".
- **UI: a Virtual/Hybrid badge** on `ApplicationCard` and the `ApplicationDetailHeader`; an **all/virtual/hybrid filter** on the Apps list.
- **UI: the Add-app wizard (`CreateApplicationWizard`) gains a branch** — create a *virtual* app (scratch/template, today's flow) OR a *hybrid* app (pick an installed NC app to override, seeding `appType:hybrid` + `baseRef`). Hybrid detail renders the identity-metadata fields read-only.
- **Remove the standalone `AppOverride` schema** in this change (clean break, user-confirmed). The migration copies each row into a hybrid `Application` then deletes the source row; the schema is dropped from the register as the last backend step. There is no legacy read path. See design D-RETIRE.
- **No BREAKING changes for clients.** Existing virtual apps are untouched (`appType` defaults to `virtual`). Fleet apps keep their `/api/app-overrides/{appId}` contract via the shim. The migration is additive + idempotent.

## Change shape (ADR-032)

This change touches the schema register (config) AND backend PHP (code) AND Vue (code) — a mixed shape, which ADR-032 flags as an anti-pattern. The two clean ways to split are (a) **one change** with phased tasks, or (b) **a chain**: `…-schema-declaration` (config) → `…-backend` (code: migration, metadata-lock, shim) → `…-ui` (code). **Decision (user-confirmed): keep it as ONE change** because the three slices are tightly coupled around a single invariant — the `appType`/`baseRef` schema fields are meaningless without the migration that populates them, and the UI rename is meaningless without the unified read model behind it; splitting would create three half-features that cannot ship or be verified independently. The proposal frontmatter declares `kind: split` to record that the change has both a declarative (config) slice and an imperative (code) slice; the tasks file sequences them as phases (schema → backend → UI → seed → tests) so the config slice can land and be reviewed first. If a reviewer prefers a hard chain, the phase boundaries in `tasks.md` are the natural cut lines.

## Capabilities

### New Capabilities

- `unified-app-model`: The `appType` (`virtual`|`hybrid`) discriminator + `baseRef` on the `Application` schema; the rule that a hybrid app is an `Application`+delta-only-`ApplicationVersion`; the metadata-lock invariant (id/slug/name read-only for hybrid, everything-else editable); and the `AppOverride`→`Application(hybrid)` idempotent migration.

### Modified Capabilities

- `app-override-persistence`: The `PUT`/`GET`/`DELETE /api/app-overrides/{appId}` endpoints become **compatibility shims** sourcing/writing the delta on the hybrid `Application`'s production `ApplicationVersion` instead of a standalone `AppOverride` record. The HTTP contract (raw delta, empty-on-none, login+CSRF, OpenBuild-access guard) is preserved exactly. (Delta spec at `specs/app-override-persistence/spec.md`.)
- `openbuild-application-register`: The `Application` schema gains `appType` + `baseRef`; name/slug become conditionally read-only (hybrid). (Delta spec at `specs/openbuild-application-register/spec.md`.)

## Impact

- **Schema:** `lib/Settings/openbuild_register.json` — `Application` gains `appType` + `baseRef`; `AppOverride` schema is removed (after the migration empties it); register `version` bump.
- **Backend:** a repair-step / migration (e.g. `ConfigurationService` repair or a one-shot migration class) converting `AppOverride`→`Application(hybrid)`; a metadata-lock guard rejecting name/slug edits on hybrid apps (lifecycle `requires` guard or save-time validation); the existing `AppOverride` controller/service methods repointed to resolve the delta from the hybrid Application's version (the shim).
- **Manifest resolution:** `ManifestResolverService` already merges `baseRef`+`manifestDelta` for virtual apps (app-delta-override); a hybrid app's `baseRef.kind: fleet-app` resolves the same way, except the public manifest endpoint still hands the **raw delta** to the fleet app's client loader (OpenBuild holds no fleet base) — i.e. the shim returns the delta, not a merged manifest.
- **Frontend:** `src/manifest.json` (menu label + route ids + page titles), `src/components/ApplicationCard.vue` (badge), `src/components/applicationDetail/ApplicationDetailHeader.vue` (badge + read-only metadata for hybrid), `src/components/VirtualAppsActions.vue` (copy + filter), `src/dialogs/CreateApplicationWizard.vue` (virtual/hybrid branch), `src/registry.js` (registered component names), `BuilderHostView` copy. Route-id renames must keep deep-links working (alias or redirect).
- **Seed data:** the seeded example set gains one hybrid example so the unified model is testable out of the box (ADR-001).
- **Hydra gates:** route-auth + no-admin-idor (shim routes keep their login+CSRF+OpenBuild-access posture), route-reachability (renamed routes still resolve), spec-coverage + e2e traceability (changed methods carry `@spec`), modal-isolation (wizard branch stays in `src/dialogs/`).
- **i18n:** new user-facing strings ("Apps", "Virtual", "Hybrid", filter labels, wizard branch copy, read-only hint) follow the standard l10n flow with English source keys.
- **Theming:** the Virtual/Hybrid badge uses existing NC/nldesign status-pill variants — no new hardcoded colours.
