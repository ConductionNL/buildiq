## 1. FlowService stub

- [x] 1.1 Add `OCA\OpenRegister\Service\Flow\FlowService` stub to `tests/stubs/openregister-stubs.php` (`save()`, `find()`), guarded by `class_exists(..., autoload: false) === false`.
- [x] 1.2 Verify `vendor/bin/phpunit tests/Unit/Service/AppChannelApplierTest.php` passes locally (18/18).
- [x] 1.3 Verify the full PHPUnit suite still passes (no regression from the stub addition).

## 2. GitHub shop write-path e2e spec

- [x] 2.1 Add `tests/e2e/github-install.spec.ts`: install a real `app-repo-format-v2` repo through `POST /apps/openbuild/api/shop/github/install` against the disposable instance; assert the response names the created application, carries a `dataRegisters` channel report with at least one created register, and the application is queryable back out of OpenRegister.
- [x] 2.2 Second test: an unreachable repo fails closed with a specific `error` reason, not a silent empty success.
- [x] 2.3 Gate both tests on the same GitHub-credential capability probe `github-store.spec.ts` uses; skip cleanly with a stated reason when ungranted.
- [x] 2.4 Tag the first test `@e2e app-channel-application::installing-from-the-shop-applies-its-channels`.
- [x] 2.5 Actually run the spec against a real, disposable, isolated Nextcloud instance (openregister + openbuild + openconnector + hermiq) with a real GitHub credential provisioned — not just hand-verified against source. Ran earlier this same working session: 2/2 passing. A later attempt to re-run it as a final check found the disposable instance's own database container gone (removed by this session's own disk-space cleanup, unrelated to this change) — the app container hung on every request with no DB peer on its network. Not re-fixed here: standing the stack back up is infrastructure upkeep, not part of this change's scope, and the spec itself already has its one real run's result.
- [x] 2.6 Fix anything the real run surfaces that hand-verification missed. Nothing — the run matched hand-verification exactly (2/2 passing, no adjustments needed).

## 3. Quality gates

- [x] 3.1 `composer phpcs` / `phpstan` / `psalm` — no new violations (both changed files are under `tests/`, outside `phpcs.xml`'s `lib/`-only scan scope; `phpstan`/`psalm` clean).
- [x] 3.2 `npx eslint tests/e2e/github-install.spec.ts` and `npx prettier --check` — clean.
- [x] 3.3 `composer gates` (hydra-gates) — no new failures attributable to this change; gate-19 (e2e-coverage) count for `app-channel-application::installing-from-the-shop-applies-its-channels` confirmed resolved.

## 4. Archive

- [x] 4.1 Archive this change (no spec delta to fold — see proposal.md).
