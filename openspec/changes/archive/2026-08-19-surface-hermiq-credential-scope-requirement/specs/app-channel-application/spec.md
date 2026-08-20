## MODIFIED Requirements

### Requirement: Skills are delegated to hermiq by repository coordinates

hermiq owns skill installation and already exposes `POST /api/skills/bundle/install`,
which takes owner, repo and ref and performs its own fetch. The applier SHALL delegate the
skills channel to hermiq by passing those coordinates, and SHALL NOT reimplement skill
parsing, frontmatter handling or aux-file placement.

hermiq's fetch authenticates to the credential broker under hermiq's OWN app identity,
independent of which app the supplied credential was originally scoped for. Before
delegating, when a credential id was supplied, the applier SHALL check that credential's
own `allowedApps` for hermiq's app id. When the check conclusively finds hermiq absent,
the applier SHALL skip the skills channel with reason `credential-missing-hermiq-scope`
and SHALL NOT invoke hermiq's installer with a credential already known to be denied. When
the check is inconclusive (the credential cannot be found, or the lookup itself fails), the
applier SHALL delegate to hermiq exactly as it would without this check — an inconclusive
lookup SHALL NOT be treated as a scope gap.

#### Scenario: The skills channel is delegated, not reimplemented
- **WHEN** a template declares 94 skills and hermiq is installed and enabled
- **THEN** the applier invokes hermiq bundle install with the repo owner, name and ref
- **AND** the report carries the installed and skipped counts hermiq returned

#### Scenario: A credential missing hermiq's scope is detected before the call, not after
- **WHEN** a template declares skills, a credential id is supplied, and that credential's
  `allowedApps` does not include hermiq's app id
- **THEN** the skills channel reports `skipped` with reason `credential-missing-hermiq-scope`
  and the declared count is preserved
- **AND** hermiq's bundle installer is never invoked with that credential
- **AND** the report's top-level `warnings` list gains an entry naming the channel and the
  declared skill count

#### Scenario: An inconclusive credential-scope lookup does not block the delegation
- **WHEN** a template declares skills, a credential id is supplied, and the credential
  lookup used to check its `allowedApps` throws or finds nothing
- **THEN** the applier delegates to hermiq exactly as it would with no scope check at all
- **AND** no `credential-missing-hermiq-scope` warning is recorded

### Requirement: Application is best effort with a complete per-item outcome report

OpenRegister provides no cross-object transaction, so the applier SHALL NOT claim
atomicity. A failure applying one item SHALL NOT abort the remaining items.

Every declared item SHALL appear in the report with exactly one outcome — `created`,
`skipped` or `failed` — and a skipped or failed item SHALL carry a reason. The counts in
the report SHALL satisfy `created + skipped + failed == declared` for every channel, so
that a dropped item is arithmetically impossible to hide.

In addition to the per-channel outcome, the report SHALL carry a top-level `warnings` list
of structured entries (`code`, `channel`, `message`) for any condition that degraded a
channel in a way a caller should act on — such as a credential missing a scope a delegated
channel needs — so that a caller of either install path does not have to read a specific
channel's nested `reason` field to learn something needs fixing.

#### Scenario: One failing connector does not abort the rest
- **WHEN** applying five connectors and the third one throws
- **THEN** the other four are still applied
- **AND** the report records the third as failed with its reason
- **AND** `created + skipped + failed` equals five

#### Scenario: Both install paths surface the same top-level warning
- **WHEN** `ShopController::githubInstall()` installs a repo whose skills channel is
  skipped for a missing credential scope
- **THEN** the response's `data.warnings` carries that warning
- **AND** `GitHubSyncController::pull()` installing the same repo through the same
  applier surfaces the identical warning at the top level of its own response
