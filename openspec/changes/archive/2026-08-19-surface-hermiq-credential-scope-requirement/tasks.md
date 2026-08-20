## 1. Report gains a warnings list

- [x] 1.1 `ChannelApplyReport`: add a private `array $warnings = []`, an `addWarning(string $code, string $channel, string $message): void` method, and include `'warnings' => $this->warnings` in `toArray()`.
- [x] 1.2 Unit test: `addWarning()` entries appear verbatim in `toArray()['warnings']`.

## 2. Proactive credential-scope check in AppChannelApplier

- [x] 2.1 Add `private const REASON_CREDENTIAL_MISSING_HERMIQ_SCOPE = 'credential-missing-hermiq-scope';`.
- [x] 2.2 Add `credentialAllowsApp(string $credentialId, string $appId): ?bool` — reads the credential via `ObjectServiceInterface::find()` against `CREDENTIAL_REGISTER`/`CREDENTIAL_SCHEMA` (same register/schema `credentialExists()` already reads), returns `true`/`false` when conclusive, `null` when the credential is absent or the lookup throws.
- [x] 2.3 In `apply()`, before delegating to `SkillChannelDelegate`: when the skills channel is non-empty and `$credentialId` is supplied, call `credentialAllowsApp($credentialId, 'hermiq')`. On a conclusive `false`, skip the skills channel with `REASON_CREDENTIAL_MISSING_HERMIQ_SCOPE` and record a `warnings` entry naming the declared skill count — WITHOUT calling `SkillChannelDelegate::apply()`. On `true` or `null` (inconclusive), call the delegate exactly as before.
- [x] 2.4 Unit test: a template with a non-empty `skills` channel + a credential whose `allowedApps` is `['openbuild']` → the skills channel reports `skipped`/`credential-missing-hermiq-scope`, the report's `warnings` carries one entry for `channel: 'skills'`, and `SkillChannelDelegate`'s underlying hermiq installer double is NEVER invoked (asserted via a locator/installer double that fails the test if called).
- [x] 2.5 Unit test (regression guard): the same template with `allowedApps` including `'hermiq'` → the delegate IS called and installs normally (existing `testAnIdempotentSourceReportingOnlyUnchangedIsNotTreatedAsUnaccountedFor`-style behaviour unchanged).
- [x] 2.6 Unit test: an inconclusive lookup (credential not found / lookup throws) does NOT add a warning and falls through to the delegate call, unchanged from current behaviour.

## 3. Surface warnings at the top level of both install paths

- [x] 3.1 `ApplicationsController::installFromTemplateArray()`: copy `$channels['warnings']` onto `data['warnings']`.
- [x] 3.2 `GitHubAppSyncService::pull()`: copy `$channels['warnings']` onto the result's top-level `warnings`.

## 4. Frontend surfacing

- [x] 4.1 `GitHubSyncModal.vue`: render `pullResult.warnings[].message` in a warning `NcNoteCard`, alongside the existing pull-success note card.
- [x] 4.2 `TemplateGallery.vue`: `onInstalled()` shows a `showWarning()` toast per `created.warnings[].message` before redirecting into the newly installed app.

## 5. Quality gates

- [x] 5.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — no new violations.
- [x] 5.2 Run the PHPUnit suite for the touched files (`AppChannelApplierTest`, `ChannelApplyReportTest`, plus the full suite for a regression check).

## 6. Spec + archive

- [x] 6.1 Add spec delta scenarios to `app-channel-application`: the skills-delegation requirement gains a "credential lacks hermiq scope" scenario; a new requirement covers the top-level `warnings` surfacing.
- [x] 6.2 Archive the change and fold the delta into `openspec/specs/app-channel-application/spec.md`.
