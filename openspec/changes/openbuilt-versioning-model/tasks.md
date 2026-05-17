## 1. Schema split in lib/Settings/openbuilt_register.json

- [x] 1.1 Open `lib/Settings/openbuilt_register.json` and locate the existing
      `Application` schema entry under `components.schemas`.
- [x] 1.2 Remove the `manifest`, `version`, `status`, and `currentVersion` properties
      from `Application`. Remove `manifest`, `version`, `status` from `required`.
- [x] 1.3 Add `productionVersion` to `Application.properties` as an OR relation
      pointing at `applicationVersion` (use OR's first-class relation type per
      ADR-002, not a raw UUID-string).
- [x] 1.4 Remove the `x-openregister-lifecycle` `states` and `transitions` block
      from `Application` (lifecycle moves to ApplicationVersion). Remove any
      `on_transition` action that assigns `self.currentVersion = @result.uuid`
      and any `create_relation(ApplicationVersion)` action.
- [x] 1.5 Remove the `on_transition` action that upserts `BuiltAppRoute` from
      `Application.x-openregister-lifecycle` (this action moves to ApplicationVersion
      per spec REQ-OBV-106; the upsert key remains the parent Application's `slug`).
- [x] 1.6 Add a new top-level entry under `components.schemas` for
      `ApplicationVersion` with these properties (per spec REQ-OBV-101): `name`
      (string, required), `slug` (string, required, kebab-case pattern, min 2, max
      48), `manifest` (object, required, JSON-schema-ref to
      `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` v1.4.0+),
      `register` (string, required, pattern matching
      `^openbuilt-[a-z0-9-]+-[a-z0-9-]+$`), `semver` (string, required, semver
      pattern, default `0.1.0`), `status` (string, enum
      `draft | published | archived`, default `draft`), `application` (relation
      → Application, required), `promotesTo` (relation → ApplicationVersion,
      optional).
- [x] 1.7 Add `x-openregister-lifecycle` to `ApplicationVersion` with states
      (`draft | published | archived`) and transitions (`draft → published`,
      `published → archived`, `archived → draft`). On the `draft → published`
      edge, declare the upsert-BuiltAppRoute action using the parent Application's
      `slug` (resolve via the `application` relation).
- [x] 1.8 Declare an `x-openregister-validation` block on `ApplicationVersion`
      that rejects same-row self-loops (`promotesTo !== self.uuid`) as the cheap
      first-filter cycle check (broader cross-row check lives in
      `ApplicationVersionService`).
- [x] 1.9 Bump the schema file's `info.version` (e.g. `0.2.0 → 0.3.0`) to
      reflect the breaking shape change.
- [x] 1.10 Validate the schema file is well-formed JSON
      (`php -r "json_decode(file_get_contents('lib/Settings/openbuilt_register.json'), false, 512, JSON_THROW_ON_ERROR);"`).

## 2. Retire the ApplicationVersionSnapshotListener

- [x] 2.1 Delete the file
      `lib/Listener/ApplicationVersionSnapshotListener.php` outright (do not leave
      a stub — see spec REMOVED REQ-OBA-007).
- [x] 2.2 Open `lib/AppInfo/Application.php` and remove the
      `$context->registerEventListener(ObjectLifecycleTransitionedEvent::class,
      ApplicationVersionSnapshotListener::class)` call (or equivalent) from the
      `register()` method.
- [x] 2.3 Remove the corresponding `use` import for the listener class.
- [x] 2.4 Update / delete the PHPUnit test file
      `tests/Unit/Listener/ApplicationVersionSnapshotListenerTest.php` (if it
      exists). Adjust `phpunit.xml` `<testsuites>` config if it referenced the
      file directly.

## 3. ApplicationVersionService — semver, cycle, deletion logic

- [x] 3.1 Create `lib/Service/ApplicationVersionService.php` (constructor takes
      `IRootFolder`, `LoggerInterface`, OR's `ObjectService` /
      `ConfigurationService` per ADR-022 — no app-local DB access).
- [x] 3.2 Implement `canonicaliseManifest(array $manifest): string` — recursive
      key sort, JSON_THROW_ON_ERROR, no whitespace.
- [x] 3.3 Implement `hashManifest(array $manifest): string` returning the SHA-256
      hex digest of the canonicalised manifest.
- [x] 3.4 Implement `bumpPatch(string $semver): string` (e.g.
      `0.1.0 → 0.1.1`, `2.5.7 → 2.5.8`) with a 4xx error path on malformed input.
- [x] 3.5 Implement `onSave(ApplicationVersion $current, ApplicationVersion $next):
      void` that compares hashes and patch-bumps `next.semver` + updates
      `next.manifestHash` only on `manifest` content change. Metadata-only
      changes leave `semver` and `manifestHash` untouched (spec REQ-OBV-103).
- [x] 3.6 Implement `guardNoCycle(string $currentUuid, ?string $proposedTargetUuid):
      void` walking `promotesTo` forward from the proposed target up to 100 hops;
      throw a 422 validation exception if `$currentUuid` is encountered (spec
      REQ-OBV-104). Throw a 4xx if the 100-hop cap is exceeded (chain corruption).
- [x] 3.7 Implement `guardProductionVersionOwnership(Application $app,
      ApplicationVersion $proposed): void` verifying the back-reference (spec
      REQ-OBV-105 / REQ-OBA-008).
- [x] 3.8 Implement `deleteVersion(string $versionUuid, string $strategy): void`
      branching on `delete-now | orphan-grace | keep-register` (spec REQ-OBV-108).
      Reject the call when the version is the parent Application's
      `productionVersion`. Use OR's register-delete API for `delete-now`; set
      an orphan-mark flag on the register row for `orphan-grace`; no-op on the
      register for `keep-register`.
- [x] 3.9 Add `manifestHash` as a private mapper-internal field on the
      `ApplicationVersion` row (per design Decision 4 — not in the public schema).
      Mechanism depends on what the OR floor exposes (e.g. a `_self` namespaced
      key or a mapper-only column); pick the lightest-touch option that survives
      schema-import round-trips.
- [x] 3.10 Unit tests for the service: canonicalisation determinism,
      patch-bump arithmetic, cycle detection on linear / branching / 100-hop
      chains, production-version guard, deletion strategies (each branch and the
      production-version refusal).

## 4. ApplicationVersionsController — CRUD + delete-with-strategy

- [x] 4.1 Create `lib/Controller/ApplicationVersionsController.php` extending
      `ApiController`. Inject `ApplicationVersionService`, OR's `ObjectService`,
      and `IUserSession` / `IGroupManager` for RBAC.
- [x] 4.2 Implement `index($slug)` — list ApplicationVersions for the named
      Application (spec REQ-OBV-107).
- [x] 4.3 Implement `show($slug, $versionSlug)` — fetch one.
- [x] 4.4 Implement `create($slug, $payload)` — POST creating an ApplicationVersion;
      default `semver` to `0.1.0` when omitted (spec REQ-OBV-102).
- [x] 4.5 Implement `update($slug, $versionSlug, $payload)` — PUT; runs
      `ApplicationVersionService::onSave()` (semver auto-bump) and
      `guardNoCycle()` pre-save.
- [x] 4.6 Implement `destroy($slug, $versionSlug, $strategy)` — DELETE accepting
      `?strategy=` query param; rejects missing / unknown strategy with 400;
      delegates to `ApplicationVersionService::deleteVersion()`.
- [x] 4.7 Annotate every method with `#[NoAdminRequired]`. Enforce
      Application-level RBAC (owners/editors for write, viewers for read) per
      spec REQ-OBV-107.
- [x] 4.8 Register routes in `appinfo/routes.php`:
      - `GET /api/applications/{slug}/versions` → `index`
      - `GET /api/applications/{slug}/versions/{versionSlug}` → `show`
      - `POST /api/applications/{slug}/versions` → `create`
      - `PUT /api/applications/{slug}/versions/{versionSlug}` → `update`
      - `DELETE /api/applications/{slug}/versions/{versionSlug}` → `destroy`
- [x] 4.9 Newman / Postman test collection for the five endpoints + the deletion
      strategies + the production-version refusal.

## 5. Green-field migration repair step

- [x] 5.1 Create `lib/Repair/MigrateToVersionedModel.php` implementing
      `\\OCP\\Migration\\IRepairStep`. Constructor takes OR's `ObjectService` /
      `RegisterService` and `LoggerInterface`.
- [x] 5.2 Implement `getName(): string` returning a clear name (e.g.
      `"Migrate OpenBuilt to versioned app model (DESTRUCTIVE)"`).
- [x] 5.3 Implement the short-circuit detection in `run()`: query the `openbuilt`
      register schemas; if `applicationVersion` schema exists OR no Application
      rows carry a `currentVersion` field, log
      `Migrated-to-versioned-model: schema already in versioned shape, skipping`
      and return (spec REQ-OBGFM-002).
- [x] 5.4 Implement the enumeration: fetch every Application row in the
      `openbuilt` register via `ObjectService::findAll('openbuilt/application')`.
- [x] 5.5 For each row: derive the per-app register name (`openbuilt-{slug}`);
      call OR's register-delete API; on failure log the error with the slug and
      continue to the next row WITHOUT deleting the Application (spec
      REQ-OBGFM-004). On success, delete the Application row.
- [x] 5.6 On every successful row deletion, emit
      `$output->info("Migrated-to-versioned-model: dropped Application '<slug>'
      and register 'openbuilt-<slug>'")` (spec REQ-OBGFM-003).
- [x] 5.7 Top-of-file docblock with `@destructive` marker and a SAFETY note:
      "This step deletes every pre-migration Application row and its per-app
      register. ADR-002 records the explicit decision to accept this data loss;
      existing installs hold only test data."
- [x] 5.8 Register the step in `appinfo/info.xml` under
      `<repair-steps><post-migration>`.
- [x] 5.9 PHPUnit test: pre-migration fixture with three Application rows + three
      registers — confirm three deletions + three log lines + zero leftover rows.
      Second test: idempotency — running on already-migrated state is a no-op
      with the single short-circuit log line.
- [x] 5.10 PHPUnit test for partial failure: simulate OR register-delete
      returning failure for one of three rows — confirm the failing row is NOT
      deleted from the Application table, the other two ARE deleted, and the
      failure is logged.

## 6. Lifecycle-action relocation (BuiltAppRoute upsert)

- [x] 6.1 In `lib/Settings/openbuilt_register.json`, on
      `ApplicationVersion.x-openregister-lifecycle.transitions[draft→published]
      .actions`, declare an upsert of `BuiltAppRoute` keyed by the parent
      Application's `slug` (resolved via `application` relation) with
      `applicationUuid` set to that parent's `uuid`.
- [x] 6.2 Verify by Playwright / Newman: creating an Application + first
      ApplicationVersion, then publishing the version, results in one
      `BuiltAppRoute` row with the right slug → applicationUuid mapping.

## 7. Application schema integrity (productionVersion guard)

- [x] 7.1 Wire `ApplicationVersionService::guardProductionVersionOwnership()` into
      the Application pre-save path. Mechanism options (pick the lightest):
      - Subscribe an `ObjectSavingEvent` listener for the Application schema
        and call the guard.
      - Wire it into an `ApplicationService::save()` shim if one exists.
- [x] 7.2 PHPUnit: setting `Application.productionVersion` to a foreign
      ApplicationVersion is rejected with 422; setting it to a back-referencing
      ApplicationVersion succeeds.

## 8. Retire / no-op the SeedHelloWorld repair step

- [x] 8.1 Open `lib/Repair/SeedHelloWorld.php` and confirm whether it writes
      `currentVersion` (it likely does — see spec REMOVED REQ-OBA-006).
- [x] 8.2 **Decision**: delete the file entirely. Hello-world seeding under the
      new model is owned by the creation-wizard spec
      (`openbuilt-app-creation-wizard`); leaving a stale seed step here would
      conflict with the green-field migration on every upgrade.
- [x] 8.3 Remove the `<post-migration>` registration of `SeedHelloWorld` from
      `appinfo/info.xml`.
- [x] 8.4 Delete the related PHPUnit test
      (`tests/Unit/Repair/SeedHelloWorldTest.php`) if it exists.
- [ ] 8.5 Confirm in a manual dev-install run that no Hello World data appears
      post-install (the wizard spec will re-introduce it on a later wave).

## 9. End-to-end verification

- [ ] 9.1 Apply the change in a fresh dev container; confirm the
      `MigrateToVersionedModel` repair step logs the short-circuit line
      (no pre-migration data on a fresh install).
- [ ] 9.2 Create one Application + one ApplicationVersion via OR REST; confirm
      the row has `semver: "0.1.0"` and an internal `manifestHash`.
- [ ] 9.3 PUT the ApplicationVersion with a `manifest` content change — confirm
      `semver` is now `0.1.1` and `manifestHash` updated.
- [ ] 9.4 PUT the ApplicationVersion with only `name` changed — confirm `semver`
      and `manifestHash` are unchanged.
- [ ] 9.5 Create a second ApplicationVersion and form a chain
      (`<v1>.promotesTo = <v2>`); attempt a cycle (`<v2>.promotesTo = <v1>`) and
      confirm 422.
- [ ] 9.6 Set `Application.productionVersion = <v1>` (where `<v1>.application` is
      this Application); confirm success. Try setting it to a foreign
      ApplicationVersion and confirm 422.
- [ ] 9.7 Publish `<v1>`; confirm a `BuiltAppRoute` row is upserted with the
      parent slug.
- [ ] 9.8 Attempt to DELETE `<v1>` (which is `productionVersion`); confirm 422.
- [ ] 9.9 DELETE `<v2>` with `?strategy=delete-now`; confirm both the version row
      and the per-version register `openbuilt-<slug>-<v2-slug>` are gone.
- [ ] 9.10 Re-run the repair step (`occ maintenance:repair`); confirm it
      short-circuits without modifying any data.

## 10. Quality gates

- [ ] 10.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan); fix every
      finding (no pre-existing issues left unaddressed — memory rule
      `fix-all-issues-encountered`).
- [ ] 10.2 Run the full PHPUnit suite (`composer test`); confirm all pass.
- [ ] 10.3 Re-run `openspec validate openbuilt-versioning-model --strict`;
      confirm clean.
- [ ] 10.4 Open PR against `development` (memory rule
      `feature-branches-from-dev`); reference ADR-002, this change id, and the
      four sibling spec ids in the description so reviewers can trace the chain
      delivery wave.
