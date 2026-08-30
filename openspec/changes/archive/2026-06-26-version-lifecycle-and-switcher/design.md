## Context

ADR-002 splits the runtime model into a logical `Application` (single-valued
`productionVersion` pointer + `status`) and N deployable `ApplicationVersion` rows
(`register`, `semver`, `status` draft/published/archived, `promotesTo` chain, `scope`).
The backend for this is already shipped: `ApplicationVersionsController` (index/create/
update), the per-version lifecycle (`x-openregister-lifecycle` on `ApplicationVersion`),
`ManifestResolverService` + `?_version=` routing, `ApplicationPublishController`
(owner-only), and the `Application.productionVersion` back-reference guard
(`ApplicationVersionService::guardProductionVersionOwnership`).

What is missing is the maintainer-facing UI to drive any of it, plus one broken view:
`src/views/VersionHistory.vue` queries `/apps/openregister/api/objects/openbuild/
application-version` and filters rows by `applicationUuid` — but that OR endpoint
returns nothing for this register shape and the real parent field is `application`, so
the list is permanently empty. The working endpoint is `GET /apps/openbuild/api/
applications/{slug}/versions` (returns `name`, `slug`, `semver`, `status`,
`application`, `register`, `manifest`). Its only caller, `ManifestLayersDetail.vue`,
passes `applicationUuid` and never resolves the slug, even though it loads the
`Application` object (which carries `slug`).

The live shell `/apps/openbuild/builder/{slug}` always serves
`Application.productionVersion`'s manifest; non-production versions are reachable only
via `?_version={slug}` and only to editors+, all already enforced server-side.

## Goals / Non-Goals

**Goals:**
- Make the version list work (correct endpoint + fields) and reachable from the app
  slug the parent already has.
- Click a version → open the builder at `?_version={slug}`; a per-row Edit → open the
  designer at `?_version={slug}`.
- A "New draft" action that clones production's manifest and **shares production's
  register**.
- A "Release" action: set `productionVersion` + publish (`draft → published`) + demote
  the previous production. Owner-only.
- An "Open app" split button: primary opens production; chevron lists all versions with
  View/Use + Edit.
- EN + NL i18n.

**Non-Goals:**
- Per-user deltas (owned by the separate `layered-versioned-app-deltas` change).
- Promotion-with-data-strategies (owned by `version-promotion` / `PromoteVersionDialog`).
  Release here is set-production + publish, **not** a chain promotion with data copy.
- New OR schemas. No per-version data migration.
- Branching/DAG chains, auto-promotion (ADR-002 roadmap).

## Decisions

### Decision 1 — INVARIANT: exactly one production manifest version per app, always

There is at most and at least one production version per `Application` at any time,
enforced structurally by the **single-valued** `Application.productionVersion` relation.
The canonical URL `/builder/{slug}` resolves only that pointer; drafts are never served
there (they require `?_version=` + editor RBAC). "Releasing" a version **transfers** the
production role by reassigning the single pointer — it can never produce two productions
because the field holds one value. The previous holder loses the role the instant the
pointer moves.

**How enforced:**
- The pointer is single-valued in `lib/Settings/openbuild_register.json`
  (`Application.productionVersion` is one relation, not an array) — already the case.
- `ManifestResolverService` serves only `productionVersion` at the canonical URL.
- The release operation is a single atomic-intent sequence (reassign pointer → publish
  new → demote old); see Decision 3. `guardProductionVersionOwnership` already rejects a
  `productionVersion` whose `application` back-reference does not match.

*Alternative considered:* a boolean `isProduction` on each version — rejected: it can be
set on two rows at once, reintroducing the very ambiguity the single-valued pointer
removes.

### Decision 2 — Manifest-only versioning: a new draft SHARES production's register (deviation from ADR-002)

ADR-002 §"Per-version register for data isolation" and `application-versions`
REQ-OBV-101 describe a per-version register named `openbuild-{appSlug}-{versionSlug}`,
provisioned by the creation wizard, so beta tinkering cannot contaminate production
**data**. This change makes a deliberate, scoped departure for the **New-draft** action:

> When a new draft is created via this UI, `ApplicationVersion.register` is set to the
> **same** register as the current production version. No `openbuild-{slug}-{versionSlug}`
> register is minted. Data is not duplicated; only the **manifest/layout** is versioned.

**Why the deviation:** the user decision is manifest-only versioning — a maintainer who
spins up a draft to retouch pages/layout wants the live data, not an empty or copied
register. Per-version data isolation is valuable for schema-shape experiments (the
ADR-002 promotion case) but actively harmful for the common "tweak the manifest, keep
the data" flow this UI targets. The wizard-driven, register-per-version path is **not
removed** — it remains the model for `version-promotion` and for app-creation presets;
this UI's New-draft simply opts into a shared register.

**How reconciled with the existing convention:**
- `application-versions` REQ-OBV-101 already declares `register` as a plain required
  string with the convention noted as "out of scope for this spec and owned by the
  creation-wizard capability" — i.e. the schema does not enforce the per-version naming.
  This change adds a requirement that the **create path** inherits production's register
  when none is supplied (small backend glue in `ApplicationVersionsController::create`),
  rather than failing or minting one.
- The deletion guard (`assertNotProductionVersion`) and the delete strategies
  (`delete-now` / `orphan-grace` / `keep-register`) operate on a register that may now be
  **shared** with production. A shared-register draft MUST be deleted with
  `keep-register` semantics (drop the version row only) — dropping a register shared by
  production would destroy production data. This is a constraint the delete flow must
  honour; releasing/deleting drafts in this UI never uses `delete-now` on a
  production-shared register.

*Alternative considered:* always mint a per-version register and copy production rows on
draft create (ADR-002's `start-with-source-data`) — rejected by the user decision: it
duplicates data, drifts from production, and is the wrong default for manifest tweaks.

### Decision 3 — Release = set-as-production + publish + demote (declarative-vs-imperative)

The **lifecycle** of an `ApplicationVersion` (`draft → published → archived`) is already
**declarative** via `x-openregister-lifecycle` on the schema (per ADR-031). Publishing a
version (the `draft → published` transition) is a declarative lifecycle action with the
`on_transition` BuiltAppRoute upsert (REQ-OBV-106) — that stays declarative and is
reused unchanged.

The **release glue** — reassigning the single `Application.productionVersion` pointer and
**demoting** the previous production version — is **imperative controller wiring**, and
that is the correct altitude per ADR-031 §Exceptions: it is a cross-row, RBAC-guarded
action that the declarative vocabulary does not express (one row's lifecycle transition
plus another object's pointer field plus a second row's demotion, atomically intended).
The Release operation therefore:

1. (owner-only RBAC gate) verifies the caller is an owner of the parent Application
   (reuse `ApplicationPublishController`'s owner gate / `ApplicationVersionOwnerGuard`).
2. transitions the chosen version `draft → published` via the existing per-version
   lifecycle (declarative; fires the BuiltAppRoute upsert).
3. sets `Application.productionVersion = chosenVersion.uuid`
   (`guardProductionVersionOwnership` validates the back-reference).
4. **demotes** the previous production version — it loses the production role by virtue of
   step 3 (the pointer moved). Its `status` is set to `archived` (provisional decision —
   see Open Questions), satisfying the single-production invariant.

This lands as one owner-only controller method (on `ApplicationPublishController` or a
`release` method on `ApplicationVersionsController`) registered in `appinfo/routes.php`,
NOT a new service class — the steps reuse existing lifecycle + guard primitives.

*Alternative considered:* expressing release entirely as an `x-openregister-lifecycle`
transition with an `on_transition` that mutates the Application — rejected: the
transition target lives on a different object (the Application pointer + the other
version's status), which is the cross-object/RBAC exception ADR-031 carves out for
imperative service/controller code.

### Decision 4 — Seed Data: N/A

This change introduces **no new OpenRegister schemas** — it reuses the existing
`Application` and `ApplicationVersion` schemas in `lib/Settings/openbuild_register.json`.
There is therefore no seed-data section: no schema fixtures, no demo rows. Any schema
touch is a tweak to existing definitions (e.g. confirming `productionVersion` stays
single-valued, or the `register`-default behaviour expressed as create-path glue, not as
schema seed). Stated explicitly to satisfy the design checklist.

### Decision 5 — RBAC: owner-only for create*/release; reuse existing guards

- **Release / set-production / demote:** owner-only, enforced server-side. Reuse the
  owner gate already on `ApplicationPublishController` and the
  `ApplicationVersionOwnerGuard`. NC admins are NOT auto-granted (consistent with
  `version-routing` / `version-promotion`).
- **Create draft:** the existing create endpoint allows `WRITE_ROLES` (owners + editors).
  This UI keeps that — editors may create drafts; only owners may release (provisional —
  see Open Questions). View/Use of a non-production version requires editor+ via the
  existing `ManifestResolverService` 404 gate.
- **Open-app split button:** the chevron lists versions; selecting a non-production
  version navigates to `?_version=` which is RBAC-gated server-side (viewers get the
  version-not-found state). The UI marks production and shows other versions only to
  editors+ where the caller role is known (`obAppRole`).

### Decision 6 — Reuse `buildVersionedRoute` and `useApplicationVersion`

Row click → builder uses `generateUrl('/apps/openbuild/builder/{slug}') + ?_version=`;
per-row Edit and the chevron Edit use `buildVersionedRoute('PageDesigner', { slug },
versionSlug)` so `?_version=` survives in-app navigation (REQ-OBVR-006). Version
resolution for highlighting the active version reuses the `useApplicationVersion`
composable rather than a bespoke lookup.

## Risks / Trade-offs

- **[Shared register + delete-now destroys production data]** → The delete flow MUST use
  `keep-register` semantics for any draft whose `register` equals production's. Document
  the constraint in tasks; never expose `delete-now` for a production-shared draft.
- **[Demoting previous production to `archived` could surprise a maintainer who wanted to
  keep editing it]** → Provisional: archive-on-release (reversible via the existing
  `archived → draft` transition). Recorded as an Open Question; the alternative (leave it
  `published`) risks two `published` versions, though only one is ever production.
- **[Release is not atomic across three objects]** → If step 3 (pointer move) fails after
  step 2 (publish), the new version is published but not production. Mitigation: order
  pointer-move before demotion, surface a clear error, and make the operation re-runnable
  (idempotent at the user-visible level — re-release converges).
- **[`VersionHistory` is reused by `layered-versioned-app-deltas` for delta rows]** →
  Repointing its data source must not break the deltas use. Mitigation: the slug-based
  versions endpoint returns the same `ApplicationVersion` rows; keep the existing
  `application-uuid`-based call path available where the deltas view needs it, or pass the
  slug through from both callers. Re-verify the deltas surface after the change.
- **[Editors creating drafts but not releasing]** → acceptable and intended; release is
  the owner gate. Surface the Release button only to owners.

## Migration Plan

No data migration. No schema migration beyond confirming `productionVersion` is
single-valued (already the case) and adding the create-path register-inheritance glue.
Rollback is reverting the frontend + the thin controller method; the existing backend is
unchanged in behaviour for callers that supply a `register` explicitly. Cache-bust via
`info.xml` `<version>` bump per the immutable-asset rule.

## Open Questions

- **Draft naming/slug scheme** — provisional: `"Draft N" / draft-n` where N is one past
  the highest existing `draft-*` index (fallback to a short timestamp suffix on
  collision). Affects: `version-lifecycle-ui` spec + tasks.
- **Previous-production status after release** — provisional: `archived` (reversible via
  `archived → draft`). Affects: `application-versions` delta + `version-lifecycle-ui`.
- **May editors (not just owners) create drafts?** — provisional: yes (matches today's
  `WRITE_ROLES` on create); release stays owner-only. Affects: `version-lifecycle-ui`.
- **Does the chevron list archived versions?** — provisional: no by default; show
  draft + published (production marked), hide archived behind a "show archived" toggle.
  Affects: `version-lifecycle-ui`.
