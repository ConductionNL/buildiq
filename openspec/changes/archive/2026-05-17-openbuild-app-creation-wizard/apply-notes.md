# Apply Notes — openbuild-app-creation-wizard (spec F)

Applied: 2026-05-16  
Completed: 2026-05-16  
Branch: `feature/openbuild-app-creation-wizard`  
Branched from: `feature/openbuild-test-harness`

---

## Files Added (this spec only)

### Backend
- `lib/Exception/WizardCreationException.php` — runtime exception carrying errorCode + failedAtStep + rollbackStatus + orphanedResources
- `lib/Service/SlugValidator.php` — single source of truth for `^(?!_)[a-z0-9][a-z0-9-]*[a-z0-9]$`; mirrors `src/utils/slugPattern.js`
- `lib/Service/ApplicationCreationService.php` — atomic creation orchestrator (validate → Application → N × ApplicationVersion + register → chain wire → productionVersion)
- `lib/Controller/ApplicationCreationController.php` — `POST /api/applications/wizard`; returns 201 / 422 / 500
- `lib/Resources/wizard/default-manifest.json` — seed manifest template with `{registerSlug}` token
- `lib/Resources/wizard/default-schemas.json` — seed schema list (`hello-message`)

### Frontend
- `src/utils/slugPattern.js` — `SLUG_PATTERN`, `toKebabCase()`, `validateSlug()`
- `src/dialogs/CreateApplicationWizard.vue` — four-step NcModal shell
- `src/dialogs/CreateApplicationWizard/Step1Basics.vue` — name / slug / description / icon inputs
- `src/dialogs/CreateApplicationWizard/Step2Preset.vue` — four preset radio cards
- `src/dialogs/CreateApplicationWizard/Step3Custom.vue` — add-row custom chain composer
- `src/dialogs/CreateApplicationWizard/Step4Review.vue` — read-only summary
- `src/components/VirtualAppsActions.vue` — index actions bar; opens the wizard, navigates on creation

### Configuration
- `appinfo/routes.php` — added `POST /api/applications/wizard` → `applicationCreation#wizard`
- `src/manifest.json` — VirtualApps page `config.actionsComponent: "VirtualAppsActions"` (moved from top-level into `config` to align with manifest-test `referencedComponents()` scanner)
- `src/customComponents.js` — added `VirtualAppsActions` import + registry entry

### Tests
- `tests/Unit/Service/SlugValidatorTest.php`
- `tests/Unit/Service/ApplicationCreationServiceTest.php`
- `tests/Unit/Controller/ApplicationCreationControllerTest.php`
- `tests/dialogs/CreateApplicationWizard.spec.js`
- `tests/dialogs/CreateApplicationWizard/Step1Basics.spec.js`
- `tests/dialogs/CreateApplicationWizard/Step2Preset.spec.js`
- `tests/dialogs/CreateApplicationWizard/Step3Custom.spec.js`
- `tests/dialogs/CreateApplicationWizard/Step4Review.spec.js`
- `tests/utils/slugPattern.spec.js`
- `tests/integration/openbuild-app-creation-wizard.postman_collection.json`
- `tests/e2e/createApplicationWizard.spec.ts`

---

## Files Modified

- `src/manifest.json` — moved `actionsComponent` inside `config` block (see §Manifest note)
- `src/customComponents.js` — added `VirtualAppsActions`
- `appinfo/routes.php` — added wizard route
- `src/dialogs/CreateApplicationWizard/Step3Custom.vue` — added `mounted()` hook to emit initial validity on first render (fixes test `emits _step3Valid and versions on mount`)

---

## Legacy Dialog Removal (REQ-OBWIZ-001)

The legacy single-form "Add Application" dialog (`AddApplicationDialog.vue` or equivalent) does **not** exist in the codebase — it was never present in this branch (the wizard replaces a placeholder / non-existent component). Confirmed via:

```
git grep -E "AddApplicationDialog|legacy.*single-form" src/
# → no output
```

The `VirtualAppsActions.vue` component is the sole entry point for app creation on the index page and it opens only the four-step `CreateApplicationWizard`.

---

## OR API Surface Assumptions

The `ApplicationCreationService` assumes the following OpenRegister contracts (verified against the spec C implementation in `ApplicationVersionService`):

1. **`ObjectService::saveObject(object, register, schema)`** — creates an object; returns an array or jsonSerializable object with `id` or `uuid`.
2. **`ObjectService::saveObject(object, register, schema, uuid)`** — patches an existing object (used for `promotesTo` chain wiring and `productionVersion` update).
3. **`ObjectService::deleteObject(uuid)`** — deletes an object by UUID (used in rollback).
4. **`RegisterMapper::createFromArray(array)`** — creates a new OR register (used for per-version register provisioning).
5. **`RegisterMapper::find(slug, _multitenancy: false)`** — resolves a register by slug.
6. **`RegisterService::delete(register)`** — deletes an OR register (used in rollback).
7. **`SchemaMapper::createFromArray(array)`** — creates a new OR schema (used for seed schema seeding).
8. **`SchemaMapper::find(slug, _multitenancy: false)`** — resolves a schema by slug.
9. **`ObjectService::searchObjects(query)`** — used for the `appSlugExists()` check.

If OR's `RegisterMapper::find()` or `RegisterService::delete()` signatures differ (e.g. named-arg changes), rollback calls may fail silently (they are already wrapped in try/catch and append to `orphanedResources`).

---

## Manifest Note

The manifest had `"actionsComponent": "VirtualAppsActions"` at the top level of the VirtualApps page object (not inside `config`). The Vitest manifest-spec test's `referencedComponents()` function scans only `page.config.actionsComponent`, so this caused the test `has no unused customComponents entries` to fail. The field was moved inside `config` — this is consistent with how `VirtualAppDetail` places its `actionsComponent`.

---

## Live-Environment Dependencies

The following tests require a running Docker dev environment:

### Newman (`tests/integration/openbuild-app-creation-wizard.postman_collection.json`)
- All 4 scenarios require `localhost:8080` with the OpenBuild app enabled.
- The `single` and `dev-staging-prod` creation scenarios leave state behind (the created applications are NOT cleaned up after the run). Re-running will hit `422 app_slug_conflict` for `newman-wizard-single` / `newman-wizard-dsp`. Clean up manually via the OpenRegister UI or delete the OR objects before re-running.
- The `GET /versions` chain verification request returns 200 only when the `openbuild` register and ApplicationVersion schema are seeded. In a fresh install, it may return 404 — the Newman test handles this gracefully (200 and 404 are both accepted for that specific assertion; chain-slug assertions only run on 200).

### Playwright (`tests/e2e/createApplicationWizard.spec.ts`)
- All 8 tests (4 happy paths + 4 validation) are guarded by `test.skip(!LIVE, ...)`.
- Set `OPENBUILD_E2E_LIVE=1` to activate them.
- Happy-path tests navigate to `/applications/<uuid>` — the router expects `VirtualAppDetail` to exist in the manifest, which it does.
- The slug-conflict test (`hello-world already in use`) requires `hello-world` to be seeded by `SeedHelloWorld`.

### Playwright list parsing
Running `npx playwright test --list` does NOT require a live environment; all tests are enumerable because the skip conditions do not throw.

---

## Decisions Made During Apply

1. **`mounted()` emit in Step3Custom** — the test spec expected the component to emit `update:payload` on initial render so the parent wizard shell knows whether step 3 is valid before the user makes any change. Added `mounted() { this.emit() }` to satisfy this.

2. **Manifest `actionsComponent` placement** — moved inside `config` to match the manifest-test scanner (see §Manifest note above).

3. **Newman slug uniqueness** — Newman test slugs were changed from `newman-single-app` to `newman-wizard-single` to avoid collision with any seeds. Document requirement: clean up between runs.

4. **Playwright skip guard** — All e2e tests use `test.skip(!LIVE, '...')` because the wizard requires an active OR backend. The `--list` command always succeeds (Playwright evaluates `test.skip()` lazily).

5. **No `GET /api/applications/{slug}` endpoint** — A direct `GET /api/applications/hello-world` for verifying the chain does not exist as a discrete route; the verification path is `GET /api/applications/hello-world/versions`. The Newman collection uses this endpoint. The slug-based endpoint at `/api/applications/{slug}/manifest` exists but returns the manifest, not the Application record.

6. **ESLint errors fixed (last-mile agent)** — Three lint errors were found in the files created by the prior agent and fixed:
   - `CreateApplicationWizard.vue` line 20: `:show.sync="show"` mutated the `show` prop directly — replaced with `:show="show"` + a new `onModalShowUpdate()` method that proxies NcModal's `update:show` event without mutating the prop.
   - `Step3Custom.vue` line 194: `newVal` argument in `isValid` watcher was unused — renamed to `_newVal`.
   - `Step3Custom.vue` line 282: `index` argument in `onDragOver()` was unused — renamed to `_index`.

7. **Quality gate final state** (last-mile run):
   - `composer check:strict` — ALL CHECKS PASSED
   - `npm run lint` — 0 errors, 13 warnings (warnings only; exit 0)
   - `npx vitest run` — 471 tests pass (39 test files, +133 from 338 baseline; 24 new Step4Review tests)
   - `npx playwright test --list` — 51 tests in 16 files (8 new wizard tests, all skip-guarded)
   - Hydra gates — ALL 14 GATES GREEN
   - `openspec validate` — Change 'openbuild-app-creation-wizard' is valid
