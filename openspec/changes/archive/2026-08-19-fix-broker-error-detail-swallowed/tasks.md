## 1. Capture the swallowed detail

- [x] 1.1 Add a private `?string $lastFailureDetail` property to `GitHubPushService`, reset to `null` at the top of `brokerCall()`.
- [x] 1.2 On a non-2xx response, set it to `'HTTP ' . $status . ': ' . truncate(scrub(body))` (only when the body is non-empty) and keep the existing status-only warning log, now including the truncated/scrubbed body.
- [x] 1.3 On a caught `\Throwable`, set it to `'transport error: ' . scrub($e->getMessage())`, matching what the existing warning log already scrubs.
- [x] 1.4 Add a small `truncate()` helper (cap ~300 chars, matching the pattern already used in `GitHubAppSyncService::brokerJson()`) so a large GitHub error body can't blow up `errorMessage`.

## 2. Surface it in the thrown exceptions

- [x] 2.1 `postJson()`: append `' — ' . $this->lastFailureDetail` to the `RuntimeException` message when the detail is non-null.
- [x] 2.2 `createRepo()`: same append on its own `RuntimeException`.
- [x] 2.3 Leave `assertRepoAbsent()` untouched — a denial/404 there is the happy path (repo absent), not a failure to diagnose.

## 3. Tests

- [x] 3.1 Extend `testPushFailsClosedWhenTheBrokerCannotServeTheCall` to assert the exception message is no longer the bare original string (it now contains transport-failure detail from the `Server::get()` resolution failure that already occurs in the unit-test environment).
- [x] 3.2 Add a test that partial-mocks `brokerCall()` on `GitHubPushService`, sets `lastFailureDetail` via reflection, and invokes `postJson()`/`createRepo()` via reflection to assert the exact detail string lands in the thrown message — decoupled from the `Server::get()` container dependency.
- [x] 3.3 Add a direct test of `scrub()` (via reflection) proving a `gh[pousr]_...`-shaped token embedded in a detail string is redacted before it could reach `errorMessage` or a log line.

## 4. Quality gates

- [x] 4.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — no new violations.
- [x] 4.2 Run the PHPUnit suite for the touched files.

## 5. Spec + archive

- [x] 5.1 Add a spec delta scenario to `openbuild-exporter` generalising "Auth failure surfaces in errorMessage" to any upstream failure carrying real detail.
- [x] 5.2 Archive the change and fold the delta into `openspec/specs/openbuild-exporter/spec.md`.
