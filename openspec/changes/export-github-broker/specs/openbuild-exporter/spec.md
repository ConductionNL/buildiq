## ADDED Requirements

### Requirement: The GitHub export holds no token

OpenBuild SHALL NOT accept, store, or transmit a GitHub Personal Access Token on the
export path. `GitHubPushService` SHALL make every GitHub call — repo-existence check,
create-repo, create-blob/tree/commit/ref, open-pull-request — through OpenRegister's
`CredentialBrokerService`, passing `{method, path, body}` plus a credential UUID and the
credential owner's UID. The service SHALL NOT construct the GitHub base URL itself: the
host is the broker's host-lock, and a service that can name the host can name a different
one.

When the broker is unavailable, or when a `target=github` job carries no
`githubCredentialId`, the push SHALL fail closed with an explanatory error. There SHALL
be no token-bearing fallback path.

The `githubPat` request field, `ExportJobService::fetchPat()`, `clearPat()`,
`credentialKey()`, and the `ICredentialsManager` dependency SHALL NOT exist.

@e2e exclude Pushing to GitHub requires a real PAT-backed credential and creates a real
remote repository, so the happy path cannot run in CI or against the dev instance. The
security-relevant halves ARE mechanically verified: the fail-closed guards and the
absence of any PAT surface are covered by `GitHubPushServiceTest` and
`ExportJobServiceTest`, and the credential-picker UI is covered by the exporter Vitest
suite.

#### Scenario: A GitHub export without a credential is refused

- **WHEN** a `target=github` ExportJob is run with an empty `githubCredentialId`
- **THEN** the job fails with an error naming the missing broker credential
- **AND** no outbound call to GitHub is made

#### Scenario: The push fails closed when the broker is absent

- **WHEN** `GitHubPushService::push()` runs on an instance without OpenRegister's
  credential broker
- **THEN** it throws, rather than falling back to any token-bearing path

#### Scenario: The export dialog offers credentials, never a token field

- **WHEN** a user selects the GitHub target in the export dialog
- **THEN** the dialog offers a picker over the user's `github` broker credentials
- **AND** no password/token input is rendered
- **AND** submitting without a selected credential is refused client-side

### Requirement: The ExportJob record carries a credential reference, not a secret

The `exportJob` schema SHALL carry `githubCredentialId` (the UUID of a `github`
credential in OpenRegister's broker) and `requestedBy` (the Nextcloud UID of the queueing
user). Both are non-secret by construction: the token behind `githubCredentialId` lives in
the vault and is injected server-side, and `requestedBy` is a plain UID.

`requestedBy` SHALL be carried on the record because `RunExportJob` is a cron-driven job
with no HTTP session, and the broker's owner/IDOR guard needs an identity to check the
credential against.

Neither property SHALL be required: ExportJobs created before this change, and every
`target=zip` job, simply do not have them.

@e2e exclude Schema-shape contract on an OR-backed record, verified by
`ExportJobServiceTest` (the queued job carries `githubCredentialId` + `requestedBy` and no
secret) — there is no user-visible surface to drive.

#### Scenario: Queueing a GitHub export records the credential reference

- **WHEN** a user queues a `target=github` export with a selected credential
- **THEN** the ExportJob record carries that credential's UUID in `githubCredentialId`
- **AND** carries the queueing user's UID in `requestedBy`
- **AND** carries no secret in any field

### Requirement: The remote-registry token is stored sensitive

`registry_token` SHALL be written to app config with `sensitive: true`, so Nextcloud
encrypts it at rest and redacts it from `occ config:app:get`, `occ config:list`, and the
support/status dumps those feed. A repair step SHALL re-flag a token stored before this
release, so an existing install is migrated without the admin retyping it.

The token SHALL remain write-only: `getSettings()` returns only a `registry_token_set`
boolean, never the value.

@e2e exclude Storage-flag and CLI-redaction behaviour, with no user-visible surface —
covered by `SettingsServiceTest`.

#### Scenario: A stored registry token is redacted from CLI output

- **WHEN** an admin saves a remote-registry token and an operator runs
  `occ config:app:get openbuild registry_token`
- **THEN** the value is not printed in cleartext
