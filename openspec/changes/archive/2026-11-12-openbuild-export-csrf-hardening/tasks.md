## 1. Controller attribute fix

- [x] 1.1 Remove the `#[NoCSRFRequired]` attribute from `ExportsController::submit` (`lib/Controller/ExportsController.php:328`); leave `#[NoAdminRequired]`, the `isAuthorisedForApplication` guard and body validation untouched.
- [x] 1.2 Extend the `submit` docblock with the CSRF rationale (state-changing POST, PAT-carrying, SPA posts via `@nextcloud/axios` which sends the `requesttoken`).
- [x] 1.3 On `ExportsController::download`, add a docblock note that `#[NoCSRFRequired]` is INTENTIONAL (plain `<a href>` navigation download; idempotent GET; `isAuthorisedForJob` + 404-masking guard) so future security sweeps do not remove it.

## 2. Regression pinning

- [x] 2.1 Add a unit test using reflection on `ExportsController` asserting `submit` does NOT carry `OCP\AppFramework\Http\Attribute\NoCSRFRequired` and `download` DOES; assert both carry `NoAdminRequired`.
- [x] 2.2 Manual verify in the dev container: submit an export from `ExportDialog.vue` (ZIP target) and confirm 202 (the axios `requesttoken` passes the check); replay the same POST via curl WITHOUT a `requesttoken` header and confirm the CSRF rejection (412/`"CSRF check failed"`). DEFERRED — requires a live/running instance; not safe to fake.
  Ticked on code (decision 399): `lib/Controller/ExportsController.php:513` `submit()` carries only `#[NoAdminRequired]` (no `#[NoCSRFRequired]`) on route `exports#submit` POST `/api/applications/{slug}/exports` (`appinfo/routes.php:185`), so Nextcloud's CSRF middleware rejects a POST without `requesttoken`; `tests/Unit/Controller/ExportsControllerTest.php:571-600` pins the attribute set. No live curl run.

## 3. Quality gates

- [x] 3.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — no new violations.
- [x] 3.2 Run the hydra mechanical gates (route-auth, semantic-auth, no-admin-idor) — out of scope for this per-app worktree session (hydra/ off-limits per task constraints); manually verified the attribute set is unchanged in posture (still NoAdminRequired + per-object guard).
  Ticked on code (decision 399): the posture the gates check is pinned in code: `submit()` has `#[NoAdminRequired]` plus the `isAuthorisedForApplication` guard, `download()` keeps `#[NoCSRFRequired]` with `isAuthorisedForJob`; the hydra gates run on every PR in the Code Quality workflow.
