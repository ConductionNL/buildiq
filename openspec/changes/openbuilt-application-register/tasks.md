## 0. Deduplication Check

- [ ] 0.1 **Verify no parallel implementations exist**
  - Search `lib/Service/` for any class whose methods suggest lifecycle
    (`transitionTo`, `setStatus`, `publishApplication`, `archiveApplication`),
    RBAC (`checkPermission`, `hasRole`, `canEdit`), or slug-management
    (`ensureUniqueSlug`, `registerRoute`) semantics targeting Application objects.
  - Search `lib/Listener/` for `ApplicationVersionSnapshotListener.php` — confirm
    it does NOT exist (deleted by `openbuilt-versioning-model`).
  - If any such class or listener is found, flag it as an ADR-031 review block before
    proceeding. Document findings here.
  - Expected result: no such files; the capability is schema-declarative.

## 1. Schema — Application schema in openbuilt_register.json

- [ ] 1.1 **Verify Application schema shape matches REQ-OBA-001**
  - Open `lib/Settings/openbuilt_register.json`.
  - Confirm `components.schemas.Application.properties` contains exactly:
    `uuid` (string, UUID-format), `slug` (string, kebab-case pattern, required),
    `name` (string, required), `description` (string, optional),
    `permissions` (object, optional — per REQ-OBA-006), `productionVersion`
    (relation → `applicationVersion`, optional — per REQ-OBA-008).
  - Confirm the following properties are ABSENT: `manifest`, `version`, `status`,
    `currentVersion`. If any are present, remove them (they were deleted by
    `openbuilt-versioning-model`).
  - spec_ref: REQ-OBA-001

- [ ] 1.2 **Verify Application lifecycle block carries no states/transitions**
  - In `lib/Settings/openbuilt_register.json` under
    `components.schemas.Application.x-openregister-lifecycle`:
  - Confirm there is no `states` block and no `transitions` block.
  - Confirm there is no `on_transition` action that upserts `BuiltAppRoute` (that
    action must be on `ApplicationVersion`, not `Application`).
  - If a `states`/`transitions` block still exists, remove it — lifecycle is
    per-ApplicationVersion per ADR-002 / REQ-OBA-003.
  - spec_ref: REQ-OBA-003

- [ ] 1.3 **Declare the permissions property on Application**
  - In `lib/Settings/openbuilt_register.json` under
    `components.schemas.Application.properties`:
  - Add (or verify the presence of) the `permissions` property with the exact shape
    from REQ-OBA-006:
    `{ type: "object", properties: { owners: {type:"array",items:{type:"string"}},
    editors: {type:"array",items:{type:"string"}},
    viewers: {type:"array",items:{type:"string"}} }, additionalProperties: false }`.
  - The property must NOT be in `required` (it is optional for backward compatibility).
  - Implement: declarative JSON Schema patch. No PHP service class.
  - spec_ref: REQ-OBA-006
  - Test: integration test creates an Application with a valid `permissions` block
    and asserts round-trip equality; creates one with an unknown key (`admins`)
    and asserts 4xx.

- [ ] 1.4 **Declare the productionVersion relation on Application**
  - In `lib/Settings/openbuilt_register.json` under
    `components.schemas.Application.properties`:
  - Add (or verify the presence of) the `productionVersion` relation property
    referencing `applicationVersion` using OR's first-class relation type
    (per ADR-002 §Decision — not a raw UUID string).
  - The property must NOT be in `required` (optional — an App without a
    production version is valid during creation).
  - spec_ref: REQ-OBA-008
  - Test: integration test verifies the schema exposes `productionVersion` as a
    relation; verifies that pointing at a foreign ApplicationVersion returns 422.

- [ ] 1.5 **Verify BuiltAppRoute schema shape matches REQ-OBA-004**
  - In `lib/Settings/openbuilt_register.json` under `components.schemas.BuiltAppRoute`:
  - Confirm `slug` (string, required, kebab-case pattern) and `applicationUuid`
    (string, UUID-format, required) are the only properties.
  - Confirm `x-openregister-unique` is declared on `slug` scoped to `organisation`
    (or equivalent mechanism for per-org uniqueness).
  - spec_ref: REQ-OBA-004

- [ ] 1.6 **Verify BuiltAppRoute upsert action is on ApplicationVersion lifecycle**
  - In `lib/Settings/openbuilt_register.json` under
    `components.schemas.ApplicationVersion.x-openregister-lifecycle`:
  - Confirm the `draft → published` transition declares an `on_transition` action
    that upserts a `BuiltAppRoute` object with `slug` = parent Application's slug
    and `applicationUuid` = parent Application's UUID (resolved via the
    `application` relation).
  - Confirm NO such action exists on `Application.x-openregister-lifecycle`.
  - spec_ref: REQ-OBA-003, REQ-OBA-004

- [ ] 1.7 **Bump schema file version**
  - Update the `info.version` field in `lib/Settings/openbuilt_register.json`
    to reflect the schema changes in this spec (e.g. current version + patch bump
    or minor bump if the `permissions` property is new).
  - Validate the file is well-formed JSON:
    `php -r "json_decode(file_get_contents('lib/Settings/openbuilt_register.json'), false, 512, JSON_THROW_ON_ERROR);"`.

## 2. Permissions migration repair step

- [ ] 2.1 **Verify or create lib/Repair/PopulateApplicationPermissions.php**
  - Check if `lib/Repair/PopulateApplicationPermissions.php` exists (it may have
    been shipped by the `openbuilt-rbac` archived spec).
  - If it exists: verify it implements the contract in REQ-OBA-007 exactly —
    walks all `Application` objects, patches missing/null `permissions` to
    `{ owners: ["admin"], editors: [], viewers: [] }`, skips rows with a
    non-empty `permissions.owners`. If contract matches, this task is verify-only.
  - If it does NOT exist: create it implementing `\OCP\Migration\IRepairStep`.
    - Constructor takes OR's `ObjectService`, `LoggerInterface`.
    - `getName()` returns `"Populate Application permissions defaults"`.
    - `run()`: fetch all Application objects via
      `ObjectService::findAll('openbuilt/application')`.
      For each object: skip if `permissions.owners` is non-empty. Patch
      `permissions = { owners: ["admin"], editors: [], viewers: [] }` via
      `ObjectService::saveObject($applicationArray)`.
    - PHP file carries SPDX + EUPL-1.2 docblock (memory rule).
  - spec_ref: REQ-OBA-007

- [ ] 2.2 **Register the repair step in appinfo/info.xml**
  - Confirm (or add) `<repair-steps><post-migration>` registration for
    `OCA\OpenBuilt\Repair\PopulateApplicationPermissions` after
    `InitializeSettings`.
  - spec_ref: REQ-OBA-007

- [ ] 2.3 **PHPUnit test for PopulateApplicationPermissions**
  - `tests/Unit/Repair/PopulateApplicationPermissionsTest.php` (create if absent):
    - Fixture: one Application with no `permissions` field; one with
      `permissions.owners = ["team-alpha"]`.
    - Run the repair step once: assert the first Application now has
      `permissions.owners = ["admin"]`; assert the second is unchanged.
    - Run the repair step a second time: assert no Application is changed
      (idempotency).
  - spec_ref: REQ-OBA-007

## 3. Seed data

- [ ] 3.1 **Add Application seed objects to openbuilt_register.json**
  - Under `components.objects[]` in `lib/Settings/openbuilt_register.json`, add
    the five Application seed objects defined in design.md Seed Data:
    `hello-world`, `vergunning-aanvraag`, `meldingen-openbare-ruimte`,
    `wob-verzoek`, `subsidie-aanvraag`.
  - Each object uses the `@self` envelope:
    `{ "@self": { "register": "openbuilt", "schema": "application", "slug": "<slug>" }, ... }`.
  - Each seed object includes a `permissions` block (see design.md for values).
  - DO NOT include `manifest`, `version`, `status`, or `currentVersion` in any
    seed Application object.
  - spec_ref: REQ-OBA-001, REQ-OBA-006; ADR-001 (seed data requirement)

- [ ] 3.2 **Add BuiltAppRoute seed objects to openbuilt_register.json**
  - Under `components.objects[]`, add the three BuiltAppRoute seed objects:
    `route-hello-world`, `route-vergunning-aanvraag`,
    `route-meldingen-openbare-ruimte`.
  - Each uses the `@self` envelope with `schema: "builtapproute"`.
  - Use either static UUIDs (consistent with the Application seed UUIDs above) or
    `@ref:application:<slug>` notation if the importer supports it. Document which
    mechanism was used.
  - spec_ref: REQ-OBA-004; ADR-001

- [ ] 3.3 **Verify seed data loads idempotently on a dev install**
  - Run `occ maintenance:repair` twice on a fresh dev container.
  - After first run: confirm 5 Application objects + 3 BuiltAppRoute objects exist
    via OR REST (`GET /index.php/apps/openregister/api/objects/openbuilt/application`).
  - After second run: confirm counts are unchanged (importer skipped existing slugs).

## 4. Verification

- [ ] 4.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — all green;
      fix any pre-existing issues in touched files (memory rule
      `fix-all-issues-encountered`).
- [ ] 4.2 Run `php -r "json_decode(file_get_contents('lib/Settings/openbuilt_register.json'), false, 512, JSON_THROW_ON_ERROR);"` — no exception (confirms JSON is well-formed).
- [ ] 4.3 Run `npm run lint` / ESLint flat config — no new findings (no frontend
      changes expected; confirm build is clean).
- [ ] 4.4 **ADR-031 gate** — confirm no `ApplicationLifecycleService.php`,
      `PermissionsService.php`, `AuthorizationService.php`, or similar service class
      targeting Application lifecycle/RBAC logic exists under `lib/Service/`.
      Flag and remove any found.
- [ ] 4.5 **ADR-002 gate** — confirm `lib/Listener/ApplicationVersionSnapshotListener.php`
      does NOT exist. If it exists, it was not deleted by `openbuilt-versioning-model`
      and must be deleted here.
- [ ] 4.6 **ADR-002 gate** — confirm `Application.currentVersion` is NOT in
      `lib/Settings/openbuilt_register.json`. If it is, remove it.
- [ ] 4.7 Run all 13 Hydra gates locally via `bash scripts/run-hydra-gates.sh` (or
      equivalent); confirm clean on modified files.
- [ ] 4.8 Visually verify on a fresh `docker compose up`: the 5 seeded Applications
      appear in the OpenBuilt shell application list; a GET to
      `/index.php/apps/openregister/api/objects/openbuilt/builtapproute` returns
      at least 3 BuiltAppRoute rows.

## 5. Tests (ADR-008)

- [ ] 5.1 **PHPUnit** — `tests/Unit/Repair/PopulateApplicationPermissionsTest.php`
      (per task 2.3): two-run idempotency; selective patch of missing-permissions
      rows only. _(Requires NC bootstrap; runs in container.)_

- [ ] 5.2 **PHPUnit** — `tests/Integration/ApplicationSchemaTest.php` (create or
      extend):
  - Assert `Application` schema is importable and contains exactly the expected
    properties (UUID, slug, name, description, permissions, productionVersion).
  - Assert `manifest`, `version`, `status`, `currentVersion` are absent.
  - Assert `BuiltAppRoute` schema contains `slug` and `applicationUuid`.
  - Assert `Application.x-openregister-lifecycle` has no `states`/`transitions`.

- [ ] 5.3 **PHPUnit** — `tests/Integration/ApplicationPermissionsTest.php` (create
      or extend):
  - Create an Application via OR REST with `permissions = { owners: ["team-alpha"], editors: [], viewers: [] }`;
    assert 201 + round-trip equality on GET.
  - Attempt to PUT with `permissions = { owners: ["x"], admins: ["y"] }`;
    assert 4xx citing `additionalProperties`.
  - Create Application without `permissions`; run `PopulateApplicationPermissions`
    repair step; assert `permissions.owners = ["admin"]`.

- [ ] 5.4 **Newman** — `tests/api/openbuilt-application-register.postman_collection.json`
      (create or extend with):
  - POST Application with valid `permissions` block → 201, round-trip GET.
  - POST Application without `permissions` → 201 (schema-valid, optional field).
  - PUT Application with extra `permissions` key (`admins`) → 4xx.
  - GET list of Applications → 200, all 5 seed objects present after repair.
  - GET list of BuiltAppRoute → 200, 3 seed objects present.

## 6. Documentation (ADR-009)

- [ ] 6.1 Update `openspec/app-config.json` (if it exists) to reflect the current
      capabilities: `openbuilt-application-register` with its modified properties
      list (`permissions`, `productionVersion`).
- [ ] 6.2 Update or create `docs/openbuilt-application-register.md` documenting:
  - The current Application schema shape (ADR-002-aligned).
  - The `permissions` block shape, the default-on-creation behaviour, and the
    idempotent migration.
  - The `BuiltAppRoute` slug-uniqueness contract.
  - The `productionVersion` relation and its integrity guard.
  - A note that `manifest`, `version`, `status`, `currentVersion` no longer exist
    on Application (consumers should target `ApplicationVersion`).
- [ ] 6.3 NL Design (ADR-010) — no new frontend components in this spec; confirm
      the existing Application list/editor views use Nextcloud CSS variables only
      (spot-check).

## 7. i18n (ADR-007)

- [ ] 7.1 Confirm no new user-facing strings are introduced by this spec (schema
      changes and a repair step do not add i18n strings).
- [ ] 7.2 If the `PopulateApplicationPermissions` repair step logs any user-visible
      messages (error toasts, notification text), add the corresponding keys to
      `l10n/en.json` and `l10n/nl.json`.
