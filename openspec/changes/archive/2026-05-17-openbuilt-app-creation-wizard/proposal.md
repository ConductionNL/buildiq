---
kind: code
depends_on: ["openbuilt-versioning-model"]
---

## Why

`openbuilt-versioning-model` (spec C / ADR-002) splits the runtime model into
`Application` (logical) + `ApplicationVersion` (deployable runtime), with each version
owning its own per-version register. However, spec C ships no creation UX — the
"Add Application" button on the Virtual apps index page still opens a single-form
dialog that creates a flat `Application` row, which is now an incomplete act (no
`ApplicationVersion`, no per-version register, no `productionVersion` pointer set).
This spec replaces that dialog with a **multi-step creation wizard** that provisions
the full chain — one `Application` + N `ApplicationVersion` records + N per-version
registers — in a single atomic backend call, leaving the admin with a ready-to-use
app the moment the wizard closes.

The wizard is also the primary entry point for app seeding. `SeedHelloWorld`
(retired by spec C) is not reintroduced; there is no virtual app at install time
until the admin creates one via the wizard.

## What Changes

- **BREAKING** The "Add Application" click handler on the Virtual apps index (currently
  opens a single-form `NcModal` dialog) is rewired to open the new
  `CreateApplicationWizard` dialog. The legacy single-form dialog is removed; there
  is no escape-hatch or feature flag.
- **NEW** `src/dialogs/CreateApplicationWizard.vue` — four-step wizard shell
  (`NcModal`-based; step indicator; Back / Next / Create navigation).
- **NEW** `src/dialogs/CreateApplicationWizard/Step1Basics.vue` — name, slug
  (auto-derived, editable), description.
- **NEW** `src/dialogs/CreateApplicationWizard/Step2Preset.vue` — preset picker:
  `single`, `dev-prod`, `dev-staging-prod`, `custom`.
- **NEW** `src/dialogs/CreateApplicationWizard/Step3Custom.vue` — custom-chain
  composer: add-row list, drag-to-reorder, slug auto-derive + edit, min-1 validation.
  Shown only when `custom` preset is selected.
- **NEW** `src/dialogs/CreateApplicationWizard/Step4Review.vue` — summary of all
  settings before submission; displays the Application slug + each
  `ApplicationVersion` slug in chain order.
- **NEW** `lib/Controller/ApplicationCreationController.php` — receives
  `POST /apps/openbuilt/api/applications/wizard`; validates the payload; delegates to
  `ApplicationCreationService`; returns the created `Application` UUID or a structured
  error with rollback confirmation.
- **NEW** `lib/Service/ApplicationCreationService.php` — owns the atomic creation
  flow: validate → create Application → for each version (create
  `ApplicationVersion` + provision per-version register `openbuilt-{appSlug}-{versionSlug}`)
  → wire `promotesTo` chain → set `Application.productionVersion` to terminal
  version. Full rollback on any failure at any step.
- **Modified** `appinfo/routes.php` — register `POST /api/applications/wizard`.
- **NEW** `tests/Unit/Service/ApplicationCreationServiceTest.php` — unit tests
  including rollback-at-each-step simulations.
- **NEW** `tests/Unit/Controller/ApplicationCreationControllerTest.php` — controller
  unit tests.
- **NEW** `tests/e2e/createApplicationWizard.spec.ts` — Playwright tests covering each
  preset's happy path and custom-chain validation.

## Capabilities

### New Capabilities

- `application-creation-wizard`: Multi-step wizard that provisions an Application +
  N ApplicationVersions + N per-version OR registers in one atomic backend call, with
  full rollback on partial failure. Covers the four presets (`single`, `dev-prod`,
  `dev-staging-prod`, `custom`), client-side + server-side slug/name validation (kebab
  pattern, leading-underscore rejection, no duplicate slugs within chain), and the
  custom-chain composer (add-row, drag-reorder, slug auto-derive).

### Modified Capabilities

- `openbuilt-application-register`: The index-page "Add Application" entry point now
  opens the wizard instead of a single-form dialog. No schema-level requirement changes
  in the `application` OR schema itself — the modification is in the creation UX only.

## Impact

- **New PHP**:
  - `lib/Controller/ApplicationCreationController.php`
  - `lib/Service/ApplicationCreationService.php`
- **Modified PHP**:
  - `appinfo/routes.php` — register wizard endpoint
- **New Vue**:
  - `src/dialogs/CreateApplicationWizard.vue`
  - `src/dialogs/CreateApplicationWizard/Step1Basics.vue`
  - `src/dialogs/CreateApplicationWizard/Step2Preset.vue`
  - `src/dialogs/CreateApplicationWizard/Step3Custom.vue`
  - `src/dialogs/CreateApplicationWizard/Step4Review.vue`
- **Modified Vue**:
  - `src/views/VirtualApps.vue` (or the component that renders the index-page Add
    Application button — the exact entry point is confirmed at apply time; the manifest
    for the `VirtualApps` page uses `cardComponent: "ApplicationCard"` with an actions
    component; verify which file drives the top-level "Add Application" action and
    adapt accordingly)
- **New tests**:
  - `tests/Unit/Service/ApplicationCreationServiceTest.php`
  - `tests/Unit/Controller/ApplicationCreationControllerTest.php`
  - `tests/e2e/createApplicationWizard.spec.ts`
- **OpenRegister dependency** — uses `ObjectService` for Application + ApplicationVersion
  CRUD; uses OR's register-provisioning API for per-version register creation and
  deletion (rollback). Requires the `^v0.2.10` floor from spec C.
- **Depends on** `openbuilt-versioning-model` (spec C) — specifically the
  `ApplicationVersion` schema, the `promotesTo` relation, the `productionVersion`
  relation on `Application`, and the `ApplicationVersionService` cycle guard. This
  wizard spec cannot land before spec C is merged.
- **Out of scope** (covered by sibling specs):
  - Promotion flow — `openbuilt-version-promotion`
  - `?_version=` URL routing — `openbuilt-version-routing`
  - Detail-page version switcher — `openbuilt-app-detail-overview`
  - Template marketplace integration — future roadmap
  - Per-version register cloning across apps — each wizard-provisioned register starts
    empty (except for the seeded default schema set)
