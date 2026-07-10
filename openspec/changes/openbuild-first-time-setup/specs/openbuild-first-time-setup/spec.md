## ADDED Requirements

### Requirement: Manifest declares a first-time setup block

OpenBuild's `src/manifest.json` SHALL declare an ADR-042 `setup` block (`enabled`, `version`, `completionConfigKey: "setup_completed_version"`, `steps[]`) validating against the canonical app-manifest v2 schema. The steps SHALL include, in order: an `info` introduction, a required `run-action` step that seeds the bundled application templates, an optional (skippable) `config-fields` step for the remote template store (`registry_url`, `registry_register`, `registry_token` — token write-only, never echoed), and a `summary` step reflecting the manifest health checks.

#### Scenario: Setup block is present and schema-valid

- **WHEN** the manifest is validated against `app-manifest-v2.schema.json`
- **THEN** validation SHALL pass
- **AND** the `setup` block SHALL declare the four steps with the seed action marked `required` and the store configuration marked optional

### Requirement: Admin-only idempotent seed-templates action

The system SHALL expose `POST /apps/openbuild/api/setup/seed-templates`, restricted to Nextcloud administrators via an explicit server-side admin check, with CSRF enforced. The action SHALL seed the bundled ApplicationTemplate fixtures idempotently — creating templates that are missing (by slug) and never overwriting an existing template — and SHALL return `{ seeded, skipped, errors }`. The install-time repair step SHALL reuse the same seeding service so the two paths cannot drift.

#### Scenario: First run seeds, second run skips

- **GIVEN** an instance whose ApplicationTemplate set is empty
- **WHEN** an admin POSTs to `/api/setup/seed-templates`
- **THEN** the response SHALL be 200 with `seeded` equal to the bundled fixture count
- **AND** a second identical POST SHALL return 200 with `seeded: 0` and `skipped` equal to that count

#### Scenario: Non-admin is rejected

- **WHEN** an authenticated non-admin user POSTs to `/api/setup/seed-templates`
- **THEN** the request SHALL be rejected with 403
- **AND** no template SHALL be created

#### Scenario: Existing templates are never overwritten

- **GIVEN** an admin has edited a seeded template's metadata
- **WHEN** the seed action runs again
- **THEN** the edited template SHALL be left byte-identical and counted in `skipped`

### Requirement: Setup completion gates re-display and the getting-started tour

The wizard SHALL stamp `completionConfigKey` with the manifest `setup.version` when the summary step completes, and SHALL NOT re-display while the stored version is greater than or equal to the manifest version. On an instance that is already healthy at upgrade time (templates present, store configured or consciously not required), the first boot SHALL stamp completion without displaying the wizard. The `openbuild:getting-started` walkthrough SHALL only start for an admin after setup completion, so the tour's Store and create-from-template steps cannot dead-end on an unseeded instance.

#### Scenario: Completed setup does not reappear

- **GIVEN** `setup_completed_version` >= the manifest `setup.version`
- **WHEN** an admin loads OpenBuild
- **THEN** the setup wizard SHALL NOT be shown

#### Scenario: Healthy instance is pre-satisfied on upgrade

- **GIVEN** an instance upgraded to a setup-aware version whose templates are already seeded
- **WHEN** OpenBuild first boots after the upgrade
- **THEN** completion SHALL be stamped silently and no wizard SHALL appear

#### Scenario: Tour waits for setup

- **GIVEN** a fresh instance where setup has not been completed
- **WHEN** an admin first visits OpenBuild
- **THEN** the setup wizard SHALL take precedence over the `first-visit` walkthrough trigger
- **AND** after completion the walkthrough MAY start with the Store populated
