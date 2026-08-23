## ADDED Requirements

### Requirement: Every install path applies the v2 channels

A parsed v2 app repo template carries four channels — `dataRegisters`, `connectors`,
`automations` and `skills`. Every code path that installs or pulls an app SHALL pass the
parsed template through the channel applier. No install path may read only the manifest.

Both paths SHALL call the same applier, so that a future channel cannot be wired into one
path and forgotten in the other.

#### Scenario: Pulling a v2 repo applies its channels
- **WHEN** `GitHubAppSyncService::pull()` parses a repo whose template declares a data
  register and four connector kinds
- **THEN** the bound register and every declared connector are applied to the instance
- **AND** the returned result carries a `channels` report describing each one

#### Scenario: Installing from the shop applies its channels
- **WHEN** `ShopController::githubInstall()` installs the same repo
- **THEN** the same channels are applied through the same applier
- **AND** the response carries the same `channels` report structure

#### Scenario: A v1 repo installs unchanged
- **WHEN** a template declares no channels at all
- **THEN** the install succeeds exactly as before
- **AND** the `channels` report records zero declared items for every channel

### Requirement: Connector identity is preserved on apply

A published connector carries the UUID it had on the source instance, and the source
application binds to it by that UUID through `Application.connectors[]`. The applier SHALL
write each connector at its published UUID so that those bindings resolve after install.

An applier that let OpenRegister assign a fresh UUID would break every binding while
reporting success.

#### Scenario: A connector lands at its published UUID
- **WHEN** a connector declared with UUID `00000000-0000-0000-0000-000000000000` is applied
  to an instance where that UUID does not exist
- **THEN** the object is created with that exact UUID
- **AND** the application binding for that UUID resolves to it

### Requirement: An existing connector is skipped and never overwritten

Connectors are shared infrastructure: one source may serve several applications. Installing
an application SHALL NOT modify a connector that already exists on the target instance.

When a declared connector UUID already exists, the applier SHALL leave the existing object
untouched and record the item as skipped with reason `already-exists`.

#### Scenario: A colliding connector UUID is left alone
- **WHEN** a declared connector UUID already exists locally with different content
- **THEN** the existing object is not modified in any field
- **AND** the report records that item as skipped with reason `already-exists`
- **AND** the install still succeeds

### Requirement: An existing register or schema is never mutated

The applier SHALL create registers and schemas that do not exist, and SHALL leave existing
ones untouched, recording them as skipped. Applying an app must never rewrite the shape of
data that is already on the instance.

#### Scenario: An existing register is not reshaped
- **WHEN** a declared data register slug already exists locally
- **THEN** the existing register and its schemas are unchanged
- **AND** the report records the register as skipped with reason `already-exists`

### Requirement: Skills are delegated to hermiq by repository coordinates

hermiq owns skill installation and already exposes `POST /api/skills/bundle/install`,
which takes owner, repo and ref and performs its own fetch. The applier SHALL delegate the
skills channel to hermiq by passing those coordinates, and SHALL NOT reimplement skill
parsing, frontmatter handling or aux-file placement.

#### Scenario: The skills channel is delegated, not reimplemented
- **WHEN** a template declares 94 skills and hermiq is installed and enabled
- **THEN** the applier invokes hermiq bundle install with the repo owner, name and ref
- **AND** the report carries the installed and skipped counts hermiq returned

### Requirement: An absent optional dependency degrades with a stated reason

Buildiq depends only on `openregister`. `openconnector` and `hermiq` are optional. When a
channel requires an app that is not installed or not enabled, the applier SHALL skip that
channel with a machine-readable reason and SHALL allow the remaining channels to apply.

A skipped channel SHALL never be reported as applied, and SHALL never be reported as zero
declared items when items were in fact declared.

#### Scenario: Connectors are skipped when openconnector is absent
- **WHEN** a template declares connectors and `openconnector` is not enabled
- **THEN** the connectors channel reports `skipped` with reason `openconnector-unavailable`
- **AND** the declared count still reflects the number of connectors in the template
- **AND** the data-registers channel is still applied

#### Scenario: Skills are skipped when hermiq is absent
- **WHEN** a template declares 94 skills and `hermiq` is not enabled
- **THEN** the skills channel reports `skipped` with reason `hermiq-unavailable` and a
  declared count of 94

### Requirement: Application is best effort with a complete per-item outcome report

OpenRegister provides no cross-object transaction, so the applier SHALL NOT claim
atomicity. A failure applying one item SHALL NOT abort the remaining items.

Every declared item SHALL appear in the report with exactly one outcome — `created`,
`skipped` or `failed` — and a skipped or failed item SHALL carry a reason. The counts in
the report SHALL satisfy `created + skipped + failed == declared` for every channel, so
that a dropped item is arithmetically impossible to hide.

#### Scenario: One failing connector does not abort the rest
- **WHEN** applying five connectors and the third one throws
- **THEN** the other four are still applied
- **AND** the report records the third as failed with its reason
- **AND** `created + skipped + failed` equals five

### Requirement: Every channel is bounded and truncation is reported

Each channel SHALL enforce an explicit maximum item count. When a channel exceeds its
bound, the applier SHALL log the truncation and SHALL record it in the report as a
`truncated` count.

A bound that silently drops items would reproduce the exact silent-cap defect this
programme has already hit once.

#### Scenario: Exceeding a channel bound is reported, not silent
- **WHEN** a channel declares more items than its configured maximum
- **THEN** the excess items are not applied
- **AND** the report records a non-zero `truncated` count for that channel
- **AND** the truncation is written to the log with the channel name and both counts

### Requirement: Unresolvable credential references are reported

Publishing blanks secret values while preserving `credentialRef`. An applied connector
whose `credentialRef` does not resolve on the target instance is installed but cannot run.

The applier SHALL collect every unresolvable credential reference into a
`needsCredentials` list in the report, naming the referenced credential and the connector
that needs it.

#### Scenario: A missing credential is surfaced, not swallowed
- **WHEN** an applied connector references credential `doffin` and no such credential
  exists on the target instance
- **THEN** the report lists `doffin` under `needsCredentials` together with that connector
- **AND** the install still reports the connector as created
