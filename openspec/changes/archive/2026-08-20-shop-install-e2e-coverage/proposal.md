---
kind: code
---

## Why

Two verification gaps were sitting uncommitted from the `surface-hermiq-credential-scope-requirement` work (PR #264, already merged to `development`):

1. **`AppChannelApplierTest.php` cannot run outside a full Nextcloud container.** That test file mocks `OCA\OpenRegister\Service\Flow\FlowService` (used by `FlowChannelProvisioner` for the `flows` channel), but `tests/stubs/openregister-stubs.php` — the call-surface-only mirror PHPUnit needs when OpenRegister's real classes aren't autoloadable — never declared a `FlowService` stub. Every one of the 18 tests in that file failed with `UnknownTypeException` when run locally (`vendor/bin/phpunit tests/Unit/Service/AppChannelApplierTest.php`), forcing anyone iterating on `AppChannelApplier` to either skip local verification entirely or stand up a full container for a pure-unit test.
2. **The GitHub shop's actual write path had never been run by an automated test.** `tests/e2e/github-store.spec.ts` deliberately covers only the read-side (search/browse) against the shared dev instance, by design — a write path must never touch shared data. `POST /apps/openbuild/api/shop/github/install` (`ShopController::githubInstall()`), which installs a real app-repo-format-v2 repo end to end (register + connectors/automations/flows + skills + agents channels), had only ever been exercised by hand, once, in the throwaway session that produced PR #264 — never by a repeatable, automated check. That is exactly the scenario `app-channel-application`'s own spec already documents (`### Requirement: Every install path applies the v2 channels` → `#### Scenario: Installing from the shop applies its channels`), and it carried no `@e2e` proof.

## What Changes

- **`tests/stubs/openregister-stubs.php`** gains a `OCA\OpenRegister\Service\Flow\FlowService` stub (`save()`, `find()`), mirroring the existing `OCA\OpenRegister\Service\Credential\CredentialBrokerService` stub immediately above it in the file — same `class_exists(..., autoload: false) === false` guard, so a real in-container run (where the genuine class autoloads) is unaffected. This is test scaffolding only; no production code changes.
- **`tests/e2e/github-install.spec.ts`** (new) — a Playwright spec that installs a real, known-good `app-repo-format-v2` repo (`ConductionNL/buildiq-spectr`) through the shop's write path against the disposable e2e instance (`PLAYWRIGHT_BASE_URL`, never the shared `:8080` instance — same pattern `tests/e2e/support/baseUrl.ts` and `github-store.spec.ts` already establish), and separately asserts an unreachable repo fails closed with a specific machine-readable reason rather than a silent empty success. Gated on the same GitHub-credential capability probe `github-store.spec.ts` uses; skips cleanly with a stated reason when no credential is granted, rather than failing or fabricating a pass. Tagged `@e2e app-channel-application::installing-from-the-shop-applies-its-channels` so gate-19 (e2e-coverage) recognises this as the scenario's automated proof.

## What does NOT change

- No `lib/` production code changes — this closes a test-and-verification gap only.
- `github-store.spec.ts` is untouched; it remains read-only-by-design against the shared instance.
- No new capability, and no requirement or scenario text changes in `app-channel-application` — the scenario this spec proves was already documented; this change supplies the missing automated proof for it.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

_None — no requirement or scenario text changes._ This change provides `@e2e` traceability for an existing `app-channel-application` scenario (`Installing from the shop applies its channels`); no spec delta is included since nothing about the documented behaviour changed.

## Impact

- `tests/stubs/openregister-stubs.php` — new `FlowService` stub.
- `tests/e2e/github-install.spec.ts` — new spec, 2 tests.
- `openspec/specs/app-channel-application/spec.md` — no text change; the `Installing from the shop applies its channels` scenario now has a passing `@e2e` reference.
