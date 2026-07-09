## 1. Controller attribute fix

- [ ] 1.1 Remove the `#[NoCSRFRequired]` attribute from `ExportsController::submit` (`lib/Controller/ExportsController.php:328`); leave `#[NoAdminRequired]`, the `isAuthorisedForApplication` guard and body validation untouched.
- [ ] 1.2 Extend the `submit` docblock with the CSRF rationale (state-changing POST, PAT-carrying, SPA posts via `@nextcloud/axios` which sends the `requesttoken`).
- [ ] 1.3 On `ExportsController::download`, add a docblock note that `#[NoCSRFRequired]` is INTENTIONAL (plain `<a href>` navigation download; idempotent GET; `isAuthorisedForJob` + 404-masking guard) so future security sweeps do not remove it.

## 2. Regression pinning

- [ ] 2.1 Add a unit test using reflection on `ExportsController` asserting `submit` does NOT carry `OCP\AppFramework\Http\Attribute\NoCSRFRequired` and `download` DOES; assert both carry `NoAdminRequired`.
- [ ] 2.2 Manual verify in the dev container: submit an export from `ExportDialog.vue` (ZIP target) and confirm 202 (the axios `requesttoken` passes the check); replay the same POST via curl WITHOUT a `requesttoken` header and confirm the CSRF rejection (412/`"CSRF check failed"`).

## 3. Quality gates

- [ ] 3.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — no new violations.
- [ ] 3.2 Run the hydra mechanical gates (route-auth, semantic-auth, no-admin-idor) — the changed method still declares its auth posture and keeps its per-object guard.
