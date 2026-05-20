## 0. Deduplication Check

- [ ] 0.1 Search `openspec/specs/` and existing controller files for any existing diff
      endpoint or diff-related service. Confirm no prior `DiffResolverService`,
      `DiffController`, or `diffVersions` method exists in `lib/Controller/` or
      `lib/Service/`. Document findings here before proceeding:
      _Expected: no overlap — the diff endpoint is new in this change._
- [ ] 0.2 Confirm that `ApplicationVersionsController` introduced by
      `openbuilt-versioning-model` exists and does NOT already define `diffVersions`.
      Identify its exact file path and class name for use in tasks 2 and 3.
- [ ] 0.3 Confirm OR's object-history API shape at the installed OR version. Locate the
      OR endpoint for fetching a named revision (expected:
      `GET /api/registers/{register}/objects/{uuid}/history/{revisionId}` or similar).
      Record the exact path for use in task 1.5.

## 1. DiffResolverService — reference parsing, OR lookup, RBAC gate

- [ ] 1.1 Create `lib/Service/DiffResolverService.php`. Constructor injects
      OR's `ObjectService`, an OR object-history API client or method (resolved in
      task 0.3), `IUserSession`, and `LoggerInterface`. ADR-022 — no app-local DB;
      all resolution goes via OR.
- [ ] 1.2 Implement `parseRef(string $ref): array` as a pure static method returning
      `['form' => 'slug'|'current'|'history', 'versionSlug' => string,
      'revisionId' => string|null]`:
      - Bare string with no `:` separator → `form = 'slug'`.
      - Starts with `current:` → `form = 'current'`, remainder is `versionSlug`.
      - Starts with `history:` → `form = 'history'`, split on `:` to extract
        `versionSlug` and `revisionId`. Throw a 400-mapped exception if `revisionId`
        is empty.
      - Any other `:` pattern → throw 400-mapped exception with
        `{code: "invalid_ref_format"}`.
- [ ] 1.3 Implement `resolveRef(string $appSlug, string $ref, ?IUser $caller): ?array`
      that:
      - Calls `resolveApplication($appSlug)` to load the `Application` record.
        Returns `null` if not found.
      - Performs the RBAC gate (task 1.4). Returns `null` on failure.
      - Calls `parseRef($ref)` to determine the form.
      - For `slug` or `current` form: calls `findVersionBySlug($application,
        $versionSlug)` via OR's `ObjectService`. Returns `null` if not found.
        Returns `['manifest' => …, 'semver' => …, 'savedAt' => …]` on success.
      - For `history` form: calls `findVersionBySlug(…)` then calls
        `fetchHistoryRevision($applicationVersion, $revisionId)` (task 1.5).
        Returns `null` if either lookup fails.
- [ ] 1.4 Implement `checkPermission(array $application, ?IUser $caller): bool`
      reading `application['permissions']['owners']`,
      `application['permissions']['editors']`, and `application['permissions']['viewers']`.
      Returns `true` if `$caller->getUID()` appears in any of these arrays.
      Returns `false` for null callers and for Nextcloud admins not in the lists
      (NC admin power does NOT bypass — spec REQ-OBV-503, consistent with
      `ManifestResolverService` decision in spec E). Log a `debug`-level line with
      `diff_access_denied` + caller uid when returning `false` for an auth reason.
- [ ] 1.5 Implement `fetchHistoryRevision(array $applicationVersion,
      string $revisionId): ?array` calling OR's object-history endpoint (path
      confirmed in task 0.3). Map the response to
      `['manifest' => …, 'semver' => …, 'savedAt' => …]`. Return `null` on `404`
      from OR. Propagate non-404 OR errors as a logged warning + return `null`.
- [ ] 1.6 PHPDoc on the class and every public/protected method. SPDX header inside
      the opening docblock (hydra-gate-spdx). No forbidden patterns (`var_dump`,
      `die`, `error_log`, `print_r`, `dd`, `dump`).
- [ ] 1.7 Unit tests in `tests/Unit/Service/DiffResolverServiceTest.php`:
      - `parseRef`: bare slug, `current:`, `history:` with valid revisionId, empty
        revisionId → 400 exception, unknown `:` prefix → 400 exception.
      - `checkPermission`: owner allowed, editor allowed, viewer allowed, non-member
        returns false, NC admin not in list returns false.
      - `resolveRef`: slug form happy path, current: form happy path, history: form
        happy path, missing application → null, missing version slug → null,
        missing history revision → null, RBAC failure → null.

## 2. ApplicationVersionsController::diffVersions — thin controller method

- [ ] 2.1 Open the `ApplicationVersionsController` file identified in task 0.2.
- [ ] 2.2 Inject `DiffResolverService` into the constructor. Do not remove or rename
      any existing constructor parameters.
- [ ] 2.3 Implement `diffVersions(string $slug): JSONResponse`:
      - Read `$request->getParam('from')` and `$request->getParam('to')`. If either
        is missing or empty, return `new JSONResponse(['status' => 400, 'message' =>
        'Missing from or to parameter'], Http::STATUS_BAD_REQUEST)`.
      - Call `DiffResolverService::resolveRef($slug, $from, $caller)` and
        `DiffResolverService::resolveRef($slug, $to, $caller)` (the RBAC gate runs on
        the first call; if it returns null, the second call is skipped and 404 is
        returned immediately).
      - If either result is `null`: return
        `new JSONResponse(['status' => 404, 'message' => 'Version not found'],
        Http::STATUS_NOT_FOUND)` (spec REQ-OBV-502 — same shape for missing and
        unauthorised; no existence leak).
      - On success: return `new JSONResponse(['from' => $fromPayload, 'to' => $toPayload],
        Http::STATUS_OK)` (spec REQ-OBV-501).
- [ ] 2.4 Annotate `diffVersions()` with `#[NoAdminRequired]` per spec REQ-OBV-501 and
      `hydra-gate-route-auth`. Confirm the method does NOT carry `#[PublicPage]`
      (authenticated session required for RBAC resolution).
- [ ] 2.5 PHPDoc on the method. No forbidden patterns.
- [ ] 2.6 Unit tests in `tests/Unit/Controller/ApplicationVersionsControllerDiffTest.php`
      (new file, or add to existing ApplicationVersionsController test file if present):
      - Happy path: both refs resolve → `200` with `{from, to}`.
      - Missing `from` param → `400`.
      - Missing `to` param → `400`.
      - `from` resolves but `to` is null → `404` with correct body.
      - `from` is null (RBAC failure) → `404` with correct body.
      - Both null (both missing) → `404` (single 404, not two).

## 3. Route registration

- [ ] 3.1 Open `appinfo/routes.php`. Add the entry:
      ```php
      ['name' => 'ApplicationVersions#diffVersions',
       'url'  => '/api/applications/{slug}/versions/diff',
       'verb' => 'GET']
      ```
      Place it adjacent to the other ApplicationVersions routes for readability.
- [ ] 3.2 Verify the route resolves at runtime:
      ```bash
      php occ route:list 2>&1 | grep diff
      ```
      after an `apache2ctl graceful` in the dev container. Confirm the output shows the
      new `openbuilt.ApplicationVersions.diffVersions` route entry.

## 4. Newman / Postman integration tests

- [ ] 4.1 Create `tests/integration/openbuilt-version-diff.postman_collection.json`.
      Wire it into the existing multi-collection Newman runner.
- [ ] 4.2 Happy path — diff two current-state versions by slug:
      - `from=development&to=production` as an authorised editor on `hello-world`.
      - Assert `200`, `from.manifest` and `to.manifest` both present and non-null,
        `from.semver` and `to.semver` are valid semver strings,
        `from.savedAt` and `to.savedAt` are valid ISO-8601 strings.
- [ ] 4.3 Happy path — `current:` alias:
      - `from=current:development&to=current:production` as authorised editor.
      - Assert `200` with same semantics as bare-slug form.
- [ ] 4.4 History-ref case (if OR's object-history API is reachable in the test
      environment):
      - Perform a manifest edit on `staging` to create at least two revisions.
      - `from=history:staging:r1&to=staging` as authorised editor.
      - Assert `200`, `from.savedAt` predates `to.savedAt`.
      - If OR's history API is unavailable in the CI environment, mark this test as
        `skip` with a comment and track separately.
- [ ] 4.5 Unknown version slug → `404`:
      - `from=nonexistent&to=production`. Assert `404`,
        body `{"status": 404, "message": "Version not found"}`.
- [ ] 4.6 Non-member caller → `404`:
      - Use a test user not listed in `hello-world` permissions.
      - Assert `404`, same body shape — no partial data.
- [ ] 4.7 Missing `from` parameter → `400`:
      - GET with only `?to=production`. Assert `400`.
- [ ] 4.8 Verified locally: all assertions passing (`npx newman run
      tests/integration/openbuilt-version-diff.postman_collection.json`). Record result
      count here: ___ / ___ passing.

## 5. Documentation — retired requirements

- [ ] 5.1 Confirm `lib/Listener/ApplicationVersionSnapshotListener.php` does NOT exist
      (it was deleted by `openbuilt-versioning-model`). If it does exist, open a
      separate ticket and do NOT proceed until `openbuilt-versioning-model` is applied.
- [ ] 5.2 Confirm `Application.currentVersion` is NOT present in
      `lib/Settings/openbuilt_register.json` (also deleted by `openbuilt-versioning-model`).
      If it exists, same escalation as above.
- [ ] 5.3 Confirm no `create_relation(ApplicationVersion)` action exists in
      `Application.x-openregister-lifecycle` in `lib/Settings/openbuilt_register.json`.
- [ ] 5.4 Add a comment in `lib/Settings/openbuilt_register.json` at the top of the
      `ApplicationVersion` schema entry referencing `openbuilt-version-snapshots` as the
      change that formally retired the legacy snapshot-row semantics (REQ-OBV-001 through
      REQ-OBV-006) and introduced the diff endpoint (REQ-OBV-501 – REQ-OBV-503).

## 6. Quality gates

- [ ] 6.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan); fix every finding.
      No `// SPDX-` line comments — SPDX tags live inside the class docblock
      (hydra-gate-spdx). No forbidden patterns (`var_dump`, `die`, `error_log`, `print_r`,
      `dd`, `dump`).
- [ ] 6.2 Run `composer test`; confirm all PHPUnit tests pass including the new
      `DiffResolverServiceTest` and `ApplicationVersionsControllerDiffTest`.
- [ ] 6.3 Run `npm run lint` and `npm run test:unit`; confirm no ESLint errors and no
      failing unit tests. (This change ships no Vue files; the lint pass verifies no
      existing JS is broken by the route change.)
- [ ] 6.4 Run the Hydra mechanical gates: `bash scripts/run-hydra-gates.sh`. Verify:
      - `hydra-gate-route-auth`: `diffVersions()` carries `#[NoAdminRequired]`.
      - `hydra-gate-no-admin-idor`: `DiffResolverService::checkPermission` does NOT call
        `isAdmin()` as a bypass.
      - `hydra-gate-orphan-auth`: every auth check in `DiffResolverService` is called
        from a reachable code path.
      - `hydra-gate-semantic-auth`: RBAC failure returns `null` (→ 404), not
        `false`/exception/empty array.
      - `hydra-gate-spdx`: SPDX header present in docblock on `DiffResolverService`.
      - `hydra-gate-forbidden-patterns`: no debug helpers in any new file.
      - `hydra-gate-stub-scan`: no empty `run()` bodies, no "In a complete implementation"
        comments.
- [ ] 6.5 Open PR against `development`; reference `openbuilt-versioning-model` (the
      foundation), spec E (`openbuilt-version-routing`), and `openbuilt-app-detail-overview`
      (the spec that will render the diff UI) in the PR description so reviewers can trace
      the chain delivery wave.
