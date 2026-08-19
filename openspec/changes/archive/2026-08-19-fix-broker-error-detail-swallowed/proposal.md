---
kind: code
---

## Why

`GitHubPushService::brokerCall()` (`lib/Service/GitHubPushService.php:480-529`) is the single choke point every GitHub write in the export pipeline goes through (`createRepo`, `postJson` — which backs `pushTree`'s blob/tree/commit/ref calls and `openPullRequest`). On any non-2xx response from the broker it logs only the bare HTTP status (`'... returned HTTP ' . $status`, no body) and returns `null`. The callers that receive that `null` then throw a RuntimeException with a fixed, contentless string:

- `postJson()` (line 453): `'GitHub API call failed: POST ' . $path`
- `createRepo()` (line 275): `'GitHub create-repo failed.'`

That RuntimeException's message is what `RunExportJob::run()` writes verbatim into the ExportJob's `errorMessage` field (`lib/BackgroundJob/RunExportJob.php:94-105`) — the only thing the user ever sees. It carries no status code, no GitHub error body, nothing that distinguishes a 401 (bad/expired credential), a 403 (missing `workflow` scope on the token, or a rate limit), a 404 (org typo), or a 422 (malformed payload). This was observed live this session: a publish failure surfaced only `GitHub API call failed: POST /repos/.../git/trees` in `errorMessage`, giving no way to diagnose the cause; the only option was a blind retry, which happened to succeed without ever learning what the first attempt hit.

The same `brokerCall()` also drops the transport-exception path: when `Server::get()`/`$broker->request()` throws (`CredentialAccessDeniedException`, `CredentialUpstreamException`, or a container-resolution failure), the (already-scrubbed) exception message is logged server-side but likewise never reaches the RuntimeException the caller throws.

Verified this is safe to surface: `CredentialBrokerService::request()` (openregister `lib/Service/Credential/CredentialBrokerService.php:192`) returns `{status, headers, body}` as "the upstream status, headers, and body" verbatim from GitHub — GitHub's own response body cannot contain our secret, since GitHub never saw it (only used it to authenticate the request). The two exceptions the broker can throw carry guard-failure/transport-failure descriptions, not secret material (`CredentialAccessDeniedException`/`CredentialUpstreamException` docblocks). `GitHubPushService` already has a `scrub()` helper (line 589) that strips GitHub PAT-shaped tokens (`gh[pousr]_...`) from any string bound for a log or exception as defence in depth; reusing it for the new detail is sufficient — no new redaction logic is needed.

## What Changes

- **`GitHubPushService::brokerCall()`** records the failure detail it currently only logs — `HTTP {status}: {truncated, scrubbed body}` for a non-2xx response, or `transport error: {scrubbed exception message}` for a caught `Throwable` — on a private `$lastFailureDetail` property, reset to `null` at the top of every call so a stale value can never leak into an unrelated failure.
- **`postJson()`** and **`createRepo()`** append that detail (when present) to the RuntimeException message they already throw, instead of the bare fixed string.
- No change to `brokerCall()`'s `null`-on-failure contract, the `failQuietly` behaviour for `assertRepoAbsent()`, or any caller's control flow — this only enriches the message text that already flows to `errorMessage`.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `openbuild-exporter`: the GitHub-repository export target's `errorMessage` on failure now carries the real upstream HTTP status and a scrubbed, truncated body/reason for every broker-relayed call, not just the auth-failure case already specced.

## Impact

- `lib/Service/GitHubPushService.php` — `brokerCall()`, `postJson()`, `createRepo()`, plus a `$lastFailureDetail` property and a small truncation helper.
- `openspec/specs/openbuild-exporter/spec.md` — the "Export target — GitHub repository" requirement gains a scenario generalising the existing auth-failure scenario to any upstream failure.
- Tests: `tests/Unit/Service/GitHubPushServiceTest.php` — extend the existing fail-closed test to assert the message now carries diagnostic detail, and add focused tests on `postJson()`/`createRepo()`'s message assembly (via a partial mock of `brokerCall()`, decoupled from the `Server::get()` container dependency the existing tests already work around) plus a direct test of `scrub()` on a detail string.
