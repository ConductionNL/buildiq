## Context

Spec `openbuilt-versioning-model` (ADR-002) restructures a virtual app into a logical `Application` plus N `ApplicationVersion` rows linked in a linear chain, each with its own per-version OR register. This spec ships the **only** user-facing path that produces a complete, valid app in that model: a four-step wizard replacing the legacy "Add Application" single-form dialog. There is no install-time seed and no fallback flow — every new virtual app is created here.

## Goals / Non-Goals

**Goals:**

- Replace the existing index-page "Add Application" entry point with a guided four-step wizard.
- Provision the full chain (Application + N versions + N registers + linear `promotesTo` chain + `productionVersion` pointer) in one atomic backend call.
- Support four presets — `single`, `dev-prod`, `dev-staging-prod`, `custom` — covering the common shapes and a fully admin-defined option.
- Enforce slug discipline: kebab-case pattern, no leading underscore (system-reserved per `openbuilt-version-routing`), no duplicate version slugs within the same app's chain.
- Roll back cleanly when any step of the atomic creation fails — no orphaned Application rows, no orphaned ApplicationVersion rows, no orphaned per-version registers.

**Non-Goals:**

- Install-time seeding of a "Hello World" virtual app. Spec C green-fields existing data; this spec does not re-introduce auto-seeding. A fresh install lands the admin on an empty Virtual apps index where they invoke the wizard manually.
- Template marketplace integration. A wizard step "Start from template" is a roadmap follow-up.
- Cloning data from one app's register into a newly-provisioned app's register. Every wizard-provisioned register starts empty of objects (but seeded with the default schema set per Decision 8).
- Editing or evolving an existing app's chain. Adding / removing / reordering versions on an already-created app is owned by a follow-up flow on the detail page; this wizard is creation-only.
- Per-version icon assignment at creation time. The Application icon (per `openbuilt-nextcloud-nav` / ADR-001) lives on the Application record and applies across all versions; the wizard collects an optional icon upload in step 1, but icon editing post-creation is the icon-section flow from spec A.

## Decisions

### Decision 1 — Surface: replaces the index-page "Add Application" dialog

The wizard is opened from the same button that currently opens the single-form Add dialog. There is no separate `/new` route, no menu item, no escape-hatch. Reasoning: the wizard is the only way to create an app under the new model; preserving the muscle memory of the existing button is more important than adding a second entry point.

### Decision 2 — Four-step shape

- **Step 1 — Basics.** App name, slug (auto-derived from name, editable via an Advanced toggle), description, optional icon upload (light + dark, per `openbuilt-nextcloud-nav`). All fields validated client-side as the admin types.
- **Step 2 — Preset.** Four radio-card options: `Single`, `Development + Production`, `Development + Staging + Production`, `Custom`. Selecting `Custom` reveals step 3; selecting any of the first three skips straight to step 4.
- **Step 3 — Custom chain.** Add-row list with name + auto-derived slug. Drag-to-reorder. Top-to-bottom = upstream-to-downstream in the chain. Minimum 1 row; no maximum. Default content when entering: one row named `Production`.
- **Step 4 — Review.** Read-only summary: app name + slug + description, the version chain in arrow form (`development → staging → production`), confirmation of which version becomes the production pointer (always the terminal/bottom-most). A single `Create` button triggers the backend call.

### Decision 3 — Default values per preset

The preset selection in step 2 fully pre-populates the version chain. The wizard then proceeds directly to step 4 (review). The admin can still hit `Back` from step 4 to re-pick a preset or switch to custom, but they never see the custom composer for the canned presets.

| Preset | Chain | Production pointer |
|--------|-------|--------------------|
| `single` | `production` | `production` |
| `dev-prod` | `development → production` | `production` |
| `dev-staging-prod` | `development → staging → production` | `production` |
| `custom` | Admin-defined in step 3 | Terminal (bottom) row |

### Decision 4 — Slug derivation rules

Slug for the **app** (step 1): auto-derived from name via `toKebabCase` — lowercase, spaces → `-`, strip non-`[a-z0-9-]` chars, collapse double `--`, trim leading/trailing `-`. Editable via an "Advanced" toggle that reveals the slug input.

Slug for each **version** (step 3 custom rows; preset rows have hardcoded slugs from Decision 3): same `toKebabCase` rule, same Advanced toggle per row.

Pattern enforced both client- and server-side: `^(?!_)[a-z0-9][a-z0-9-]*[a-z0-9]$`. The negative lookahead `(?!_)` rejects leading underscores; the rest is the existing openbuilt slug pattern from `lib/Settings/openbuilt_register.json`.

### Decision 5 — Underscore-leading rejection

Slugs starting with `_` are rejected. Reason: the `?_version=` underscore convention from `openbuilt-version-routing` reserves the `_*` namespace for openbuilt system use. Letting an admin name a version `_foo` would let them collide with future system identifiers. Documented in the user-facing error message: "Version slugs cannot start with `_` (reserved for openbuilt system use)."

### Decision 6 — No-duplicate-slugs-within-chain rule

Within a single app's chain, two versions cannot share a slug. Enforced client-side as the admin types (error inline on the duplicating row) and server-side at the wizard endpoint (rejects the whole payload with a 422). Different apps can each have a `production` version — duplicate-rejection is scoped to one app's chain only.

### Decision 7 — Atomic creation + full rollback on failure

The wizard endpoint runs:

1. **Validate the whole payload** (all names, slugs, chain shape, app slug uniqueness across openbuilt). If any check fails, return `422` before creating anything.
2. **Create the Application record** with its name + slug + description + permissions (caller becomes owner). Capture its UUID.
3. **For each version in chain order:**
   - Create the `ApplicationVersion` record with `application` set to the Application's UUID, status `draft`, semver `0.1.0`, manifest set to the default Hello-World manifest (Decision 8).
   - Provision the per-version OR register `openbuilt-{appSlug}-{versionSlug}` and seed its schema set (Decision 8).
4. **Wire the chain** — for each non-terminal version, set `promotesTo` to the next downstream version's UUID.
5. **Set `Application.productionVersion`** to the terminal version's UUID.

On **any** failure at any step (validation, create, register-provision, wiring): roll back in reverse creation order — delete the per-version registers, delete the ApplicationVersion rows, delete the Application row. Return `500` with a JSON body `{ "code": "wizard_rollback", "failedAtStep": "<step>", "message": "<original error>" }`. Rollback is best-effort itself: if a register deletion fails during rollback, log it and continue — the failure is reported as `"rollback_partial"` and operations escalate to a manual cleanup.

This is *atomic-ish* — the OR layer doesn't expose a transaction surface that spans register creation, so we lean on careful sequencing + reverse-order delete. Document the limitation in the spec's Risks section.

### Decision 8 — Initial manifest + schema set per version

Every wizard-provisioned `ApplicationVersion` starts with the **same** default manifest and the **same** seed schema set:

- **Default manifest** — the existing Hello-World minimal manifest: one Dashboard page (Stats block widget over the per-version register), one Index page over the seeded schema. Used as the seed payload for every version's `manifest` field. Lives as a static template at `lib/Resources/wizard/default-manifest.json`.
- **Default schema set** — one schema named `hello-message` (a minimal example with `id` + `body` properties), installed into every freshly-provisioned per-version register. Same content as the existing seed schema from the retired `SeedHelloWorld` repair step (spec C). Lives at `lib/Resources/wizard/default-schemas.json`.

All N versions in the chain start from this identical baseline. The admin's first action post-creation is typically to edit one version (the upstream one) via the schema/page designers — divergence between versions accumulates over time.

### Decision 9 — Caller becomes owner

Whoever submits the wizard payload becomes the sole `owner` in the new Application's `permissions.owners` array. No editors, no viewers, no `group:*` — strict private-to-creator default. The admin can grant additional roles via the permissions editor after the wizard closes.

### Decision 10 — No install-time auto-seed

Spec C green-fields existing virtual apps + retires `SeedHelloWorld`. This spec does NOT reintroduce a repair step that auto-creates a Hello World app. A fresh install lands the admin on an empty `/applications` index with an "Add Application" button that opens this wizard. Reason: the wizard model fundamentally requires admin input (preset choice at minimum) — auto-seeding would have to pick a preset, name, and slug, all of which are admin-side decisions.

A consequence: documentation, screenshots, and onboarding guides need to walk the admin through their first wizard run. The journeydoc tutorial scaffold should reflect this.

## Seed Data

The wizard IS the seeding entry point — every virtual app in the system starts here. Two static fixture files live in the repo as the default content the wizard injects into every freshly-provisioned version:

- **`lib/Resources/wizard/default-manifest.json`** — the Hello-World minimal manifest used as the initial `manifest` blob for every wizard-provisioned `ApplicationVersion`. Shape:
  ```json
  {
    "version": "1.0.0",
    "menu": [
      { "id": "dashboard", "label": "Dashboard", "icon": "icon-category-dashboard", "route": "Dashboard", "order": 10 },
      { "id": "messages", "label": "Messages", "icon": "icon-comment", "route": "MessagesIndex", "order": 20 }
    ],
    "pages": [
      { "id": "Dashboard", "route": "/", "type": "dashboard", "title": "Dashboard", "config": { "widgets": [], "layout": [] } },
      { "id": "MessagesIndex", "route": "/messages", "type": "index", "title": "Messages", "config": { "register": "<computed-per-version>", "schema": "hello-message", "columns": ["body"] } }
    ]
  }
  ```
  The `register` field at `pages[1].config.register` is computed per-version at wizard-creation time as `openbuilt-{appSlug}-{versionSlug}`.

- **`lib/Resources/wizard/default-schemas.json`** — the seed schema set installed into each freshly-provisioned per-version register. Single schema `hello-message` with `id` + `body` (string) properties. Conduction cobalt `#4376FC` color usage is reserved for the icon (see `openbuilt-nextcloud-nav`); the manifest itself carries no color.

These two fixtures are the only "data" this spec ships. They are part of `Impact` and tracked by `tasks.md`.

## Declarative-vs-imperative decisions (ADR-031)

| Concern | Declarative? | Imperative? | Decision |
|---------|--------------|-------------|----------|
| Atomic creation + reverse-order rollback | OR doesn't expose a multi-row + multi-register transaction surface | `ApplicationCreationService` | **Imperative** under §Exceptions (cross-system glue + multi-step orchestration). The rollback logic is a pure-function ordered list of reverse-delete calls. |
| Slug auto-derivation from name (toKebabCase) | n/a | Pure function, both client-side (Vue) and server-side (PHP) | **Imperative** in both (small pure function, no metadata vocabulary applies). |
| Slug pattern + leading-underscore rejection | Extends the existing JSON-schema `pattern` field on `ApplicationVersion.slug` to `^(?!_)[a-z0-9][a-z0-9-]*[a-z0-9]$` | n/a | **Declarative** — patch the schema in `lib/Settings/openbuilt_register.json` (already touched by spec C). Server-side enforcement comes for free from OR's schema validation. |
| No-duplicate-slugs-within-chain | OR's per-row validation cannot reach across rows | `ApplicationCreationService` pre-save guard + client-side reactive computed | **Imperative** under §Exceptions (cross-row guard). |
| Caller-becomes-owner | n/a | `ApplicationCreationService` reads `IUserSession` and writes `permissions.owners = [caller-uid]` | **Imperative** (cross-system glue: identity → schema field). |

All §Exceptions are pre-existing carve-outs in ADR-031 (cross-row guards, cross-system glue, multi-step orchestration); this spec introduces no new exception class.

## Risks / Trade-offs

- **Rollback partial failure.** If the wizard fails at step 3.b (register-provision) and the rollback then fails to delete the previously-created register, the admin sees a `wizard_rollback` error with `rollback_partial`. The orphaned register lingers until manual cleanup via OR's register admin UI. Mitigation: the failure message tells the admin exactly which register was orphaned (`"openbuilt-{appSlug}-{versionSlug}"`); a follow-up `occ openbuilt:cleanup-orphans` command (spec C tasks already note an orphan-prune background job) catches stragglers.
- **No transaction across OR registers.** OR's storage layer commits per-row; spanning N rows + N register provisions in a single transaction is not exposed. Mitigation: the wizard sequences operations carefully (Application first, versions in order, registers per version) so partial state on failure has a deterministic shape that the rollback understands.
- **Client-side validation drift.** Slug pattern enforcement happens twice (Vue and PHP). If one is updated without the other (e.g. a future change relaxes the pattern), users can hit confusing errors. Mitigation: keep the pattern as a single string constant exported from `src/utils/slugPattern.js` and mirrored in `lib/Service/SlugValidator.php`; a CI gate (already wired by existing PHPCS / ESLint config) catches drift.
- **Drag-to-reorder accessibility.** The custom-chain composer relies on drag handles. Keyboard-only users need an alternative. Mitigation: each row exposes `↑` / `↓` buttons for keyboard reorder; the drag handle is purely additive.
- **Race condition on app slug uniqueness.** Two admins create apps with the same slug in the same second. The server-side validator queries OR for an existing app with the slug, but the slot between "check" and "create" is non-atomic. Mitigation: rely on OR's unique-index enforcement on `application.slug` (existing in `lib/Settings/openbuilt_register.json`) to reject the duplicate; the wizard endpoint catches the OR constraint violation and returns 409.

## Migration Plan

This spec lands after spec C (which green-fields all existing virtual apps and retires the legacy single-form dialog's backend). At apply time:

1. Spec C has already executed, so the install has zero virtual apps.
2. The existing "Add Application" click handler is rewired to open the new wizard.
3. The wizard's `POST /api/applications/wizard` endpoint is registered.
4. Admins use the wizard for every subsequent app creation.

No data migration. No rollback path needed — the wizard is forward-only, and the legacy single-form dialog is removed in the same PR.

## Open Questions

None at spec-write time. All design decisions are locked in the conversation that produced ADR-002 + the questions answered for this spec.
