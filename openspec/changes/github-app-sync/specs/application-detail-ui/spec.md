## ADDED Requirements

### Requirement: Detail cockpit renders a GitHub sync section for owners

The application detail cockpit SHALL render a **GitHub** section in the maintainer
detail/admin area exposing the round-trip actions. The section SHALL include a
**credential picker** listing the user's `github` credentials (via OpenRegister's
`GET /apps/openregister/api/credentials`) with a link to the CnCredentials pane to
add one when none exists, a **link-repo** affordance (owner + name, optional org),
a **Publish** action, a **Pull** action, and a **status readout** (linked repo,
last pushed sha, last pulled sha) sourced from
`GET /api/applications/{slug}/github/status`. The write actions
(link/publish/pull) SHALL be presented to owners; a non-owner MAY see the status
readout but not the write controls. Every new dialog/modal in this section SHALL
live in its own file under `src/modals/` (modal-isolation) — no inline
`NcModal`/`NcDialog`.

@e2e exclude retrofit component-contract spec — the GitHub-section render, credential
listing, and action wiring are `application-detail-ui` component-state contracts
verified by Vitest; the end-to-end round-trip is covered by the
application-detail-overview Playwright tests.

#### Scenario: Owner sees the GitHub section with actions

- **WHEN** an owner opens the detail cockpit of an app
- **THEN** the GitHub section renders the credential picker, a link-repo
  affordance, Publish and Pull actions, and the status readout

#### Scenario: Non-owner sees status but not write controls

- **WHEN** a viewer opens the detail cockpit of an app
- **THEN** the GitHub status readout is visible
- **AND** the link/publish/pull write controls are not offered

### Requirement: GitHub section feature-detects broker availability and degrades cleanly

The GitHub section SHALL feature-detect publish availability from the status
endpoint's `publishAvailable` / `brokerCredentialAvailable` flags. When publish is
unavailable (the broker is absent, its widened `github` write allowRules are not
present, or the owner has no allowed github credential), the section SHALL render
the **Publish** control in a disabled state with a clear hint stating what is
missing and how to resolve it (e.g. add a github credential, or that the broker
rules are not yet enabled). Pull of a public repo SHALL remain available
anonymously even when publish is disabled. The feature detection SHALL be advisory
in the UI — the authoritative gate is the server-side broker.

#### Scenario: Publish disabled with a hint when unavailable

- **WHEN** the status reports `publishAvailable: false`
- **THEN** the Publish control is disabled
- **AND** a clear hint explains what is missing and how to resolve it

#### Scenario: Publish enabled when a credential and rules are present

- **WHEN** the status reports `publishAvailable: true` and
  `brokerCredentialAvailable: true`
- **THEN** the Publish control is enabled

### Requirement: Publish and Pull actions call the sync endpoints and reflect results

The Publish action SHALL call `POST /api/applications/{slug}/github/push` with the
selected credential (and chosen version) and, on success, SHALL update the status
readout with the resulting commit sha. The Pull action SHALL call
`POST /api/applications/{slug}/github/pull` with a ref (and, for a private repo,
the selected credential) and, on success, SHALL surface the newly-created draft
version (which the owner promotes via the existing version-promotion flow) — a
pull SHALL NOT be presented as overwriting the production version. A
strict-parse failure returned by pull SHALL be surfaced as an actionable error
naming the offending file, with nothing created.

@e2e exclude retrofit component-contract spec — the action-calls-endpoint and
result-reflection behaviours are `application-detail-ui` component-state contracts
verified by Vitest; the integration is covered by the application-detail-overview
Playwright tests.

#### Scenario: Publish reflects the resulting commit sha

- **WHEN** an owner triggers Publish with a valid credential and the push succeeds
- **THEN** the status readout shows the new commit sha

#### Scenario: Pull surfaces the new draft version

- **WHEN** an owner triggers Pull with a ref and the pull succeeds
- **THEN** the section surfaces the newly-created draft version
- **AND** the production version is not presented as changed

#### Scenario: Pull parse failure is surfaced as an actionable error

- **WHEN** the pull endpoint returns a strict-parse failure
- **THEN** the section shows an actionable error naming the offending file
- **AND** no draft version is created
