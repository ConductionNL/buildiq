## MODIFIED Requirements

### Requirement: Export target — GitHub repository

When the user selects target `github`, the system SHALL:

1. Create a new GitHub repository under the user-supplied org with
   the user-supplied name and visibility (`public` or `private`).
2. Push the exported tree as an initial commit on a `bootstrap`
   branch.
3. Open a pull request from `bootstrap` to the repo's default
   branch (`development` if the org's standard ruleset prescribes
   it, otherwise `main`) with a placeholder title
   `"chore: bootstrap from OpenBuild"` and a body linking back to
   the source OpenBuild Application.
4. Populate the ExportJob's `downloadUrl` field with the resulting
   PR URL.

The GitHub PAT SHALL be provided once by the user in the export
dialog and SHALL be stored exclusively via Nextcloud's
`ICredentialsManager`. The PAT SHALL NOT be persisted on the
ExportJob object, in plaintext logs, or in any
`x-openregister-lifecycle` audit field. Token usage SHALL be
scoped to the single export run; the credential record SHALL be
deleted on job terminal state (succeeded or failed).

Every GitHub call made on this path SHALL be relayed through
OpenRegister's credential broker (`CredentialBrokerService::request()`).
On any broker-relayed call failure — a non-2xx upstream response or a
broker-level exception — the resulting `errorMessage` SHALL include the
real upstream HTTP status code and a truncated, scrubbed excerpt of the
response body or failure reason, so the cause is diagnosable without a
blind retry. Any GitHub PAT-shaped token SHALL be redacted from that
detail before it reaches `errorMessage` or a log line.

#### Scenario: GitHub export creates repo + PR

- **WHEN** the user submits an export with `target: github`, org
  `acme-co`, repo `hello-world`, visibility `public`, and a valid
  PAT
- **THEN** the job completes with `status: succeeded`,
  `downloadUrl` set to the PR URL, the repo exists at
  `github.com/acme-co/hello-world`, the `bootstrap` branch
  contains the exported tree, and a PR is open against the
  default branch.

#### Scenario: PAT is wiped on job terminal state

- **WHEN** an ExportJob reaches `succeeded` or `failed`
- **THEN** no record of the PAT exists in
  `ICredentialsManager` for that job's key.

#### Scenario: Auth failure surfaces in errorMessage

- **WHEN** the user submits an export with an invalid PAT
- **THEN** the job transitions to `failed`, `errorMessage`
  contains a human-readable auth-failure summary (without echoing
  the PAT), and no repo is created.

#### Scenario: Upstream failure detail surfaces in errorMessage

- **WHEN** any broker-relayed GitHub call in the push flow (create-repo,
  blob/tree/commit/ref creation, pull-request creation) fails — whether
  GitHub returns a non-2xx status (e.g. `403` missing the `workflow`
  scope, `404` for a typo'd org, `422` for a malformed payload, a `429`/
  secondary-rate-limit response) or the broker call itself throws
- **THEN** the job transitions to `failed` and `errorMessage` includes
  the upstream HTTP status code and a truncated, scrubbed excerpt of the
  response body (or the scrubbed failure reason for a broker-level
  exception), instead of a bare, content-free failure string
- **AND** no GitHub PAT-shaped token appears anywhere in `errorMessage`
  or in the corresponding log line
