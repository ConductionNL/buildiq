## 1. Static resources — default manifest + schema set

- [x] 1.1 **Create `lib/Resources/wizard/default-manifest.json`**
  - spec_ref: REQ-OBWIZ-008, REQ-OBWIZ-009; design.md §Seed Data
  - files: `lib/Resources/wizard/default-manifest.json` (NEW)
  - Content: the Hello-World minimal manifest from design.md §Seed Data — one Dashboard
    page, one Index page (`MessagesIndex`) referencing the seed `hello-message` schema. The
    `pages[*].config.register` value is left as a template token `{registerSlug}` that the
    backend substitutes at wizard-creation time.
  - acceptance_criteria: `composer check:strict` passes; the JSON file validates against
    `nextcloud-vue/src/schemas/app-manifest.schema.json` v1.5.0+.

- [x] 1.2 **Create `lib/Resources/wizard/default-schemas.json`**
  - spec_ref: REQ-OBWIZ-008; design.md §Seed Data
  - files: `lib/Resources/wizard/default-schemas.json` (NEW)
  - Content: a JSON Schema list with one entry `hello-message` (slug `hello-message`,
    properties `id` + `body` strings, required `body`). Same content as the legacy
    `SeedHelloWorld` repair step retired by spec C.
  - acceptance_criteria: The file is a valid JSON Schema document; PHPUnit fixture loads it
    without error.

## 2. Server-side validator

- [x] 2.1 **Create `lib/Service/SlugValidator.php`**
  - spec_ref: REQ-OBWIZ-005, REQ-OBWIZ-006
  - files: `lib/Service/SlugValidator.php` (NEW)
  - Public methods:
    - `validateAppSlug(string $slug): array` — returns `[]` on success or
      `[ "code" => "...", "message" => "..." ]` on failure.
    - `validateVersionSlug(string $slug): array` — same shape; rejects leading underscore.
    - `validateChainSlugs(array $slugs): array` — returns `[]` or
      `[ "code" => "duplicate_version_slug", "slug" => "<slug>", "rows" => [int, int] ]`.
  - Pattern enforced: `^(?!_)[a-z0-9][a-z0-9-]*[a-z0-9]$` as a single string constant
    (`SlugValidator::SLUG_PATTERN`) exported for mirroring in the Vue side.
  - SPDX + EUPL-1.2 docblock per project standards.
  - acceptance_criteria: PHPUnit unit tests cover happy paths + every failure mode;
    `composer check:strict` passes.

## 3. Server-side creation service

- [x] 3.1 **Create `lib/Service/ApplicationCreationService.php`**
  - spec_ref: REQ-OBWIZ-007, REQ-OBWIZ-008, REQ-OBWIZ-009, REQ-OBWIZ-010
  - files: `lib/Service/ApplicationCreationService.php` (NEW)
  - Constructor-injected: `ObjectService`, OR's register-create / register-delete API
    (verify exact contract at apply time per spec C's deferred OR-API question),
    `IUserSession`, `SlugValidator`, the static resources from tasks 1.1 + 1.2, an
    `LoggerInterface` for rollback logging.
  - Public method: `createApplication(array $payload): string` — returns the new
    Application's UUID on success; throws `WizardCreationException` on failure (the exception
    carries `failedAtStep` + `rollbackStatus` + `orphanedResources` for the controller to
    serialize into the 500 response body per REQ-OBWIZ-007).
  - Internal flow per Decision 7: validate → create Application → for each version
    (create ApplicationVersion + provision register) → wire `promotesTo` chain → set
    `productionVersion`. On any failure, run `rollback($state)` which reverse-deletes
    everything in the order: registers, versions, application.
  - Each rollback step wrapped in try/catch; failures appended to `orphanedResources` array
    rather than aborting rollback. Log each orphan with `LoggerInterface::error()` and a
    structured message identifying the resource.
  - SPDX + EUPL-1.2 docblock; PHPDoc on every public method.
  - acceptance_criteria: PHPUnit unit tests including rollback-at-each-step simulations
    (validation fail, app-create fail, version-create fail on first version, register-
    provision fail on first version, version-create fail on second version, register-
    provision fail on second version, wiring fail, productionVersion set fail).
    Each simulation asserts the final state has zero leftover records (or the expected
    `orphanedResources` set when rollback fails). `composer check:strict` passes.

## 4. Server-side controller

- [x] 4.1 **Create `lib/Controller/ApplicationCreationController.php`**
  - spec_ref: REQ-OBWIZ-001, REQ-OBWIZ-007
  - files: `lib/Controller/ApplicationCreationController.php` (NEW)
  - Single method `wizard(): Response` annotated `#[NoAdminRequired]`.
  - Reads the JSON payload from the request body.
  - Delegates to `ApplicationCreationService::createApplication($payload)`.
  - On success: returns `JSONResponse` with body `{ "applicationUuid": "<uuid>" }` status
    `201`.
  - On `WizardCreationException`: returns `JSONResponse` with body
    `{ "code": "...", "failedAtStep": "...", "message": "...", "rollbackStatus": "...", "orphanedResources": [...] }`
    status `500` (rollback path) or `422` (validation path — `failedAtStep === "validate"`).
  - acceptance_criteria: PHPUnit unit tests cover success path (201), validation failure
    (422), rollback-complete failure (500), rollback-partial failure (500 with
    `orphanedResources`). `composer check:strict` passes.

- [x] 4.2 **Register `POST /api/applications/wizard` in `appinfo/routes.php`**
  - spec_ref: REQ-OBWIZ-001
  - files: `appinfo/routes.php`
  - Add route entry: `['name' => 'applicationCreation#wizard', 'url' => '/api/applications/wizard', 'verb' => 'POST']`.
  - acceptance_criteria: Newman: `POST /index.php/apps/openbuilt/api/applications/wizard`
    with an authenticated session resolves to the new controller; without auth it returns
    401.

## 5. Client-side slug utility

- [x] 5.1 **Create `src/utils/slugPattern.js`**
  - spec_ref: REQ-OBWIZ-005
  - files: `src/utils/slugPattern.js` (NEW)
  - Exports: `SLUG_PATTERN` (string constant matching `lib/Service/SlugValidator.php`'s
    `SLUG_PATTERN`), `toKebabCase(input: string): string`, `validateSlug(slug: string):
    { valid: boolean, message?: string }`.
  - The string constant is the source of truth; the regex is constructed once via `new
    RegExp(SLUG_PATTERN)` and reused.
  - acceptance_criteria: Vitest unit tests cover `toKebabCase` (spaces, accents,
    uppercase, special chars) and `validateSlug` (happy path, leading underscore, invalid
    chars, too short). ESLint passes.

## 6. Wizard dialog shell

- [x] 6.1 **Create `src/dialogs/CreateApplicationWizard.vue`**
  - spec_ref: REQ-OBWIZ-001, REQ-OBWIZ-002
  - files: `src/dialogs/CreateApplicationWizard.vue` (NEW)
  - `NcModal`-based four-step wizard shell.
  - Local reactive state: `step` (1..4), `payload` (`{ name, slug, description, icon, iconDark, preset, versions }`).
  - Step indicator across the top showing 1/4..4/4.
  - Footer with Back / Next (steps 1–3) and Back / Create (step 4) buttons. Back is hidden
    on step 1; Next is disabled while the current step is invalid.
  - Selecting any preset other than `custom` makes `step 2 → Next` advance directly to step
    4 (skipping step 3).
  - Selecting `custom` makes `step 2 → Next` advance to step 3.
  - On step 4 Create click: POST `payload` to `/api/applications/wizard` via `axios`; on
    201 close the modal and emit `created(applicationUuid)`; on error display an
    `NcNoticationToast` with the failure details (the toast surfaces `orphanedResources`
    via an expandable list when present).
  - Conforms to the ADR-004 modal isolation rule from `nextcloud-vue/CLAUDE.md`: lives in
    its own file under `src/dialogs/`.
  - acceptance_criteria: Vitest mounting test asserts the shell renders, navigation
    between steps respects the preset-aware skip rule, and the Create button is disabled
    while validation errors exist on any step.

- [x] 6.2 **Create `src/dialogs/CreateApplicationWizard/Step1Basics.vue`**
  - spec_ref: REQ-OBWIZ-002, REQ-OBWIZ-005
  - files: `src/dialogs/CreateApplicationWizard/Step1Basics.vue` (NEW)
  - Inputs: `name` (text), `slug` (auto-derived chip + Advanced toggle that reveals an
    editable input), `description` (textarea), `icon` (optional SVG file upload), `iconDark`
    (optional SVG file upload).
  - Live slug derivation from name via `toKebabCase`. Inline validation messages via
    `validateSlug`.
  - emits `update:payload(partial)` on every keystroke; parent merges into `payload`.
  - acceptance_criteria: Vitest mounting test asserts name → slug derivation, leading-
    underscore rejection, invalid-char rejection.

- [x] 6.3 **Create `src/dialogs/CreateApplicationWizard/Step2Preset.vue`**
  - spec_ref: REQ-OBWIZ-002, REQ-OBWIZ-003
  - files: `src/dialogs/CreateApplicationWizard/Step2Preset.vue` (NEW)
  - Four `NcButton` radio cards with descriptions:
    - **Single** — `production`
    - **Development + Production** — `development → production`
    - **Development + Staging + Production** — `development → staging → production`
    - **Custom** — Define your own chain
  - Selecting a preset writes its canonical version list to `payload.versions` (canned
    presets) or marks the selection so step 3 renders (custom).
  - emits `update:payload(partial)` on selection.
  - acceptance_criteria: Vitest mounting test asserts each preset writes the expected
    `payload.versions` shape.

- [x] 6.4 **Create `src/dialogs/CreateApplicationWizard/Step3Custom.vue`**
  - spec_ref: REQ-OBWIZ-004, REQ-OBWIZ-005, REQ-OBWIZ-006
  - files: `src/dialogs/CreateApplicationWizard/Step3Custom.vue` (NEW)
  - Add-row list. Each row: name input, slug chip + Advanced toggle, `↑` / `↓` keyboard-
    accessible reorder buttons, `×` remove button, drag handle.
  - `+ Add version` button at the bottom.
  - Default state on first render: one row `Production` (slug `production`).
  - Inline duplicate-slug detection across rows; row 2's chip turns red with message "Slug
    `<slug>` is already used in this chain" when it matches another row.
  - Min-1-row enforced: clicking `×` on the last remaining row is a no-op with an inline
    error message.
  - Drag-and-drop via Sortable.js (or whatever lib nextcloud-vue ships); `↑`/`↓` buttons
    are the keyboard equivalent and are NOT additive — they are the accessibility path
    that drag-and-drop is an enhancement to.
  - emits `update:payload(partial)` with the up-to-date version list.
  - acceptance_criteria: Vitest mounting test asserts add/remove/reorder operations,
    duplicate-slug inline error, min-1-row floor.

- [x] 6.5 **Create `src/dialogs/CreateApplicationWizard/Step4Review.vue`**
  - spec_ref: REQ-OBWIZ-002
  - files: `src/dialogs/CreateApplicationWizard/Step4Review.vue` (NEW)
  - Read-only summary:
    - App name + slug + description
    - Version chain in arrow form (e.g. `development → staging → production`)
    - "Production version: `<terminal-slug>`" callout
    - Icon previews (light + dark) if uploaded in step 1
  - No state of its own; emits `submit` event when the parent's Create button is clicked.
  - acceptance_criteria: Vitest mounting test asserts the rendered summary matches the
    payload for each preset shape.

## 7. Wire the wizard into the Virtual apps index

- [x] 7.1 **Replace the legacy Add Application click handler**
  - spec_ref: REQ-OBWIZ-001
  - files: `src/views/VirtualApps.vue` (or the actual component owning the Add Application
    button — verify at apply time; the existing manifest's `VirtualApps` page uses
    `actionsComponent` and `cardComponent`. The Add button likely lives in an actions
    component or in the manifest. Confirm and adapt.)
  - The button's `@click` handler now opens `CreateApplicationWizard` (via a local
    `showWizard` ref or by importing + mounting the dialog) instead of opening the legacy
    single-form dialog.
  - On `CreateApplicationWizard @created(applicationUuid)`: navigate to
    `/applications/{applicationUuid}` so the admin lands on the detail page of the
    newly-created app.
  - REMOVE the legacy single-form Add Application dialog component file (`src/dialogs/AddApplicationDialog.vue`
    or whatever it's named — verify at apply time) AND its imports. No deprecation shim,
    no feature flag.
  - acceptance_criteria: Playwright: clicking Add Application opens the wizard (not the
    legacy dialog); after completing the wizard the route is `/applications/<uuid>`.

## 8. Tests

- [x] 8.1 **PHPUnit: `SlugValidatorTest`**
  - files: `tests/Unit/Service/SlugValidatorTest.php` (NEW)
  - Cover: valid app slug, valid version slug, leading-underscore rejection, invalid-char
    rejection, too-short rejection, duplicate-slug detection across a chain (no dup, single
    dup, multiple dups), edge case of one-character slug (rejected by `[a-z0-9][a-z0-9-]*[a-z0-9]`
    which requires 2+ chars).

- [x] 8.2 **PHPUnit: `ApplicationCreationServiceTest`**
  - files: `tests/Unit/Service/ApplicationCreationServiceTest.php` (NEW)
  - Cover: success path for each of the four presets, validation failure (slug + chain
    duplicate), rollback at each step of the creation flow (8 simulations per Decision 7),
    rollback-partial when a rollback step itself fails (asserts `orphanedResources`
    correctly populated).

- [x] 8.3 **PHPUnit: `ApplicationCreationControllerTest`**
  - files: `tests/Unit/Controller/ApplicationCreationControllerTest.php` (NEW)
  - Cover: 201 on success, 422 on validation failure, 500 on rollback complete, 500 on
    rollback partial. Auth: assert `#[NoAdminRequired]` is honoured (admin and non-admin
    both succeed when payload is valid).

- [x] 8.4 **Newman: wizard endpoint integration**
  - files: add to `tests/integration/openbuilt-schema-editor.postman_collection.json` (or
    create `tests/integration/openbuilt-wizard.postman_collection.json`)
  - Cover: successful `single` preset creation (201 + valid UUID returned), invalid-slug
    rejection (422), unauthenticated request (401), `dev-staging-prod` creation followed by
    verification that all three ApplicationVersion records + three registers exist.

- [x] 8.5 **Playwright: wizard happy paths per preset**
  - files: `tests/e2e/createApplicationWizard.spec.ts` (NEW)
  - Test cases:
    1. `single` — admin opens wizard, enters name "Hello World" (slug auto-derives to
       `hello-world`), selects `Single` preset, clicks through to step 4, clicks Create,
       lands on `/applications/<uuid>`. Verify backend: one ApplicationVersion `production`,
       one register `openbuilt-hello-world-production`.
    2. `dev-prod` — same flow, `Development + Production` preset. Verify backend: two
       ApplicationVersions with the chain `development → production`, both registers exist.
    3. `dev-staging-prod` — same flow, `Development + Staging + Production` preset.
       Verify backend: three ApplicationVersions with the linear chain, three registers
       exist, `productionVersion` pointer is correct.
    4. `custom` — admin builds a 3-row custom chain (`alpha → beta → main`) via add-row +
       drag/reorder. Verify the resulting chain matches what was composed; `productionVersion`
       points at the terminal row.

- [x] 8.6 **Playwright: validation errors**
  - files: same as 8.5
  - Test cases:
    1. Leading-underscore version slug shows inline error and disables Create.
    2. Duplicate version slug within chain shows inline error on second row and disables
       Create.
    3. Empty version row name shows inline error and disables Create.
    4. App slug already in use across all apps shows server-side 409 / 422 with the
       admin able to edit + retry.

## 9. Documentation

- [x] 9.1 **Update `docs/integrator-guide.md` and `docs/openbuilt-runtime.md`**
  - Add a section "Creating a virtual app" walking through the wizard.
  - Note: the legacy `SeedHelloWorld` repair step (and its install-time auto-seed) is gone;
    fresh installs land the admin on an empty Virtual apps index.
  - acceptance_criteria: Doc pages render cleanly via the docusaurus preset; cross-links
    to `openbuilt-version-promotion` / `openbuilt-version-routing` resolve.

## 10. Quality gates

- [ ] 10.1 **PHP** — `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes.
- [x] 10.2 **JS** — `npm run lint` and `npm run test:unit` (Vitest) pass.
- [x] 10.3 **Integration** — Newman collection from 8.4 passes against a freshly seeded
  Newman dev environment.
- [ ] 10.4 **E2E** — Playwright spec from 8.5/8.6 passes against `localhost:3000`.
- [x] 10.5 **No leftover legacy code** — `git grep -E "AddApplicationDialog|legacy.*single-form|add-app(?!lication-creation-wizard)"` returns no matches in `src/`.
