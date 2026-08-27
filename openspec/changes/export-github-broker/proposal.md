# Export to GitHub via the credential broker

## Why

Buildiq's export-to-GitHub flow took custody of the user's GitHub Personal Access
Token. The PAT was typed into `ExportDialog.vue`, POSTed to `ExportsController::submit()`,
persisted by `ExportJobService` under `ICredentialsManager` (key
`buildiq.export.<jobUuid>.pat`), read back by `RunExportJob`, and replayed by
`GitHubPushService` against `api.github.com`.

The original design (Decision 3) called this "method-scoped": the token was never held
on a service instance and was deleted on every terminal state. That is real hygiene, but
it is not custody. Buildiq could read the secret, so Buildiq was the trust boundary —
a bug in any of those four files, or a support dump taken at the wrong moment, exposes a
token that can write to every repository the user can.

The fleet already has the right mechanism: OpenRegister's credential broker. The app
sends `{method, path, body}` plus a credential UUID; the broker checks the owner, the
allowed-app grant and the immutable allow-rules, then injects the token server-side. The
app never sees it.

The one call the broker's `github` rules did not cover was `POST /repos/*/pulls` — which
is precisely why this path still held a raw PAT. That rule shipped in openregister #351,
so the blocker is gone.

## What Changes

- **`GitHubPushService`** makes every GitHub call through the broker. No `IClientService`,
  no `API_BASE` (the host is the broker's host-lock — if this service could name the host,
  it could name a different one), no `$pat` parameter anywhere. Fails closed when the
  broker is absent: there is deliberately no token-bearing fallback left.
- **`ExportJob`** carries `githubCredentialId` (a broker credential UUID — a reference,
  not a secret) and `requestedBy` (the queueing user's UID). `requestedBy` exists because
  `RunExportJob` is a cron job with no session, and the broker's owner guard needs an
  identity to check the credential against.
- **The PAT surface is deleted**, not deprecated: `ExportJobService::fetchPat()`,
  `clearPat()`, `credentialKey()`, its `ICredentialsManager` dependency, the `githubPat`
  request field, and the password input in `ExportDialog.vue`. The dialog now picks from
  the user's `github` credentials.
- **`registry_token` is flagged `sensitive`.** It was already write-only (never returned
  to the browser), but it was written as an ordinary appconfig string, so it sat in
  cleartext in `occ config:app:get` / `occ config:list` and every support dump those feed.
  A repair step re-flags tokens stored before this release.

## Impact

- Affected specs: `buildiq-exporter`
- Affected code: `lib/Service/GitHubPushService.php`, `lib/Service/ExportJobService.php`,
  `lib/Service/SettingsService.php`, `lib/BackgroundJob/RunExportJob.php`,
  `lib/Controller/ExportsController.php`, `lib/Repair/FlagRegistryTokenSensitive.php`,
  `lib/Settings/register.d/31-export-job-broker-credential.json`,
  `src/dialogs/ExportDialog.vue`
- **Breaking for in-flight jobs only**: a queued `target=github` job created before this
  release has no `githubCredentialId`, so it fails closed with a clear message rather than
  pushing. Queued ZIP exports are unaffected.
- Requires OpenRegister with the credential broker and its `POST /repos/*/pulls` rule
  (openregister #351).
