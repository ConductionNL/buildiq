## 1. Fix A — nav entry href

- [x] 1.1 In `lib/Service/AppNavigationService.php::registerNavEntries` (~line 185), replace the hand-built `$appUrl = '/apps/openbuild/builder/'.$slug;` with `$appUrl = $this->urlGenerator->linkToRoute('openbuild.dashboard.builder', ['slug' => $slug]);`, using the existing `$this->urlGenerator` constructor dependency (same pattern as the icon URL two lines above).
- [x] 1.2 Extend `tests/Unit/Service/AppNavigationServiceTest.php` with a case that mocks `IURLGenerator::linkToRoute` to return a distinctive value including `/index.php` for route name `openbuild.dashboard.builder`, then asserts the registered closure's returned `href` equals that mocked value, not a hand-built string.

## 2. Fix B — manifest name authority

- [x] 2.1 In `lib/Controller/ApplicationsController.php::getManifest` (~line 250, alongside the existing `injectOwnerSignal` call), inject the Application's authoritative `name` field as the manifest's top-level `name` (`$manifest['name'] = $applicationArray['name'] ?? $manifest['name'] ?? $slug;`), so the served manifest always carries the cased display name regardless of the stored manifest blob's own `name` value.
- [x] 2.2 Create `tests/Unit/Controller/ApplicationsControllerTest.php` with a case asserting `getManifest`'s returned manifest has `name` equal to the Application's `name` field even when the stored manifest blob's `name` is missing or stale (e.g. blob has `name: "pet-store"`, Application has `name: "Pet Store"`, response must have `name: "Pet Store"`).

## 3. Verify

- [x] 3.1 Run `OPENBUILD_SKIP_NC_BOOTSTRAP=1 ./vendor/bin/phpunit -c phpunit-unit.xml` on the host (out of container, PHP 8.3) and confirm both new/extended test cases and the full unit suite pass.

## Acceptance Criteria

- The nav entry `href` for a published Application is produced by `IURLGenerator::linkToRoute('openbuild.dashboard.builder', ...)`, not a hand-built string, and resolves correctly on both front-controller-required and rewrite-enabled instances.
- `ApplicationsController::getManifest` always returns a manifest whose top-level `name` equals the Application's authoritative, cased `name` field, regardless of what the stored manifest blob's own `name` contains.
- No frontend files are changed — `src/builder.js` already consumes `manifest.name` correctly.
- All PHPUnit unit tests pass, including the new/extended cases in `AppNavigationServiceTest.php` and `ApplicationsControllerTest.php`.

## Quality Checklist

- No hand-built `/apps/openbuild/builder/{slug}` string literals remain in `AppNavigationService`.
- SPDX license header present on the new `ApplicationsControllerTest.php` file, matching the style already used in `AppNavigationServiceTest.php`.
- Commit message follows Conventional Commits (`fix(openbuild): ...`) with an `Assisted-by` trailer per AGENTS.md.
