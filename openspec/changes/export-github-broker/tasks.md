# Tasks — export-github-broker

## Task 1: Route GitHubPushService through the broker

- [x] 1.1 Replace `IClientService` + `$pat` with `Server::get(CredentialBrokerService)`;
      add `isBrokerAvailable()` and a `brokerCall()` helper mirroring
      `GitHubAppSyncService::brokerJson()`.
- [x] 1.2 Drop `API_BASE`, `requestOptions()`, and `resolveDefaultBranch()` — the host is
      the broker's host-lock, and `auto_init` means the created repo already reports its
      own `default_branch`.
- [x] 1.3 Fail closed in `push()` when the broker is absent or `credentialId` is empty.

## Task 2: Carry a credential reference on the ExportJob

- [x] 2.1 Add `githubCredentialId` + `requestedBy` to the `exportJob` schema via
      `lib/Settings/register.d/31-export-job-broker-credential.json` (both optional).
- [x] 2.2 Bump the app version so OpenRegister re-imports the register (the import is
      APP-version gated — without the bump the new properties never land).

## Task 3: Delete the PAT surface

- [x] 3.1 Remove `fetchPat()`, `clearPat()`, `credentialKey()`, `PAT_CREDENTIAL_*` and the
      `ICredentialsManager` dependency from `ExportJobService`.
- [x] 3.2 Stop accepting `githubPat` in `ExportsController::submit()`; `unset()` it
      defensively so a legacy client cannot get one into a log or the job record.
- [x] 3.3 Pass `requestedBy` (the session UID) into `ExportJobService::queue()`.
- [x] 3.4 Drop the `clearPat()` call from `RunExportJob`'s `finally` — there is nothing
      left to clear.

## Task 4: Credential picker in the export dialog

- [x] 4.1 Replace the PAT password field in `ExportDialog.vue` with an `NcSelect` over the
      user's `github` credentials from `GET /apps/openregister/api/credentials`.
- [x] 4.2 Auto-select when the user has exactly one; refuse to submit with none.
- [x] 4.3 Explain the model in the hint text — the token stays in the vault; OpenBuild
      sends only the request it wants made.

## Task 5: Tests

- [x] 5.1 `GitHubPushServiceTest`: rewrite against the broker (no `IClientService` mock);
      pin that NO method takes a PAT, and cover fail-closed-without-broker and
      fail-closed-without-credential.
- [x] 5.2 `ExportJobServiceTest`: the queued job carries `githubCredentialId` +
      `requestedBy` and no secret; assert the whole PAT surface is gone.
- [x] 5.3 `RunExportJobTest`: a `target=github` job with no credential fails closed; the
      credential + acting user reach `push()`; no token-shaped string is ever logged.
- [x] 5.4 `ExportDialog.spec.js`: no password input; the payload carries
      `githubCredentialId` and never `githubPat`.

## Task 6: registry_token stored sensitive

- [x] 6.1 Write `registry_token` with `sensitive: true` in
      `SettingsService::updateSettings()`.
- [x] 6.2 Add the `FlagRegistryTokenSensitive` repair step (post-migration) to re-flag a
      token stored before this release; register it in `appinfo/info.xml`.

## Task 7: Verify

- [x] 7.1 PHPUnit (585), PHPCS (0 errors), Psalm (0 errors), PHPStan (0 errors),
      vitest (1177), webpack build — all green in a PHP 8.4 container.
- [x] 7.2 Hydra gates: no new failure attributable to this change.
- [ ] 7.3 Playwright: the export dialog renders the credential picker and no token field.

## Task 8: Pre-existing issues fixed along the way

- [x] 8.1 `AppNavigationServiceTest` — the service gained an `IAppConfig` constructor
      parameter and the test was never updated, so all 11 of its cases had simply been
      erroring on a TypeError.
- [x] 8.2 `AutomationsController::dryRun()` — the closure logged `$uuid` without capturing
      it (`use ($uuid)` missing), so the one line naming the failing automation read an
      undefined variable.
- [x] 8.3 `TemplateSeedService::seed()` — returned a `deferred` key its `@return` docblock
      never declared, making `SeedApplicationTemplates`' check for it statically impossible.
- [x] 8.4 `psalm.xml` — `OCA\OpenRegister\Db\Schema` was missing from the cross-app
      allowlist while its Mapper was listed, so `AutomationCompilerService`'s 7 uses of it
      were red.
