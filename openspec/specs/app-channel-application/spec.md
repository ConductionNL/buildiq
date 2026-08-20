# App Channel Application Specification

**Status**: in-progress
**Scope**: openbuild
**OpenSpec changes**:
- [apply-v2-channels](../../changes/apply-v2-channels/)
- [app-repo-format-flow-agent-export](../../changes/archive/2026-08-19-app-repo-format-flow-agent-export/) _(archived 2026-08-19)_
- [surface-hermiq-credential-scope-requirement](../../changes/archive/2026-08-19-surface-hermiq-credential-scope-requirement/) _(archived 2026-08-19)_

## Purpose

Defines how a parsed app-repo-format-v2 template is APPLIED onto a target
Nextcloud instance — the install/pull-side counterpart to
`github-app-repo-format`'s serialize/parse contract. A repository can carry
every channel correctly and still install as if it carried nothing, if the
applier that consumes the parsed template does not actually write each
channel back onto the instance; this capability is what closes that gap, for
`dataRegisters`, `connectors`, `automations`, `skills`, and — since
app-repo-format-flow-agent-export — `flows` and `agents`.

## Requirements

### Requirement: Every install path applies the v2 channels

A parsed v2 app repo template carries six channels — `dataRegisters`, `connectors`,
`automations`, `skills`, `flows` and `agents`. Every code path that installs or pulls an app SHALL pass the
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

### Requirement: An absent optional dependency degrades with a stated reason

OpenBuild depends only on `openregister`. `openconnector` and `hermiq` are optional. When a
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

### Requirement: Published flows are created and rebound onto the local application

A parsed v2 app repo template's `flows` channel carries every flow the
source application was bound to, keyed by the UUID it was published under.
The applier SHALL create each one through `FlowService::save()` — the
single OpenRegister-sanctioned entry point for flows — and SHALL rebind the
newly created flow onto the LOCAL application's own `flows[]` array,
recording the published uuid as `sourceUuid` on that binding.

The applier SHALL NOT seed a flow at its published uuid: `FlowService`
mints its own uuid on every create, and going around it to force one would
mean reimplementing the owner/organisation stamping and trigger-index
rebuild that call already performs.

A repeat apply of the same published repository SHALL be idempotent: a
flow whose `sourceUuid` is already bound on the local application SHALL be
skipped and recorded with reason `already-exists`, never duplicated.

#### Scenario: A published flow is created and bound locally
- **WHEN** a template declares one flow under a published uuid, and the local
  application has no matching `sourceUuid` bound
- **THEN** a new `Flow` entity is created via `FlowService::save()`
- **AND** the local application's `flows[]` gains a binding whose `flow` is
  the newly minted local uuid and whose `sourceUuid` is the published uuid
- **AND** the report records the item as `created`

#### Scenario: A repeat apply of the same flow is skipped, not duplicated
- **WHEN** a template declares a flow whose published uuid already appears
  as a `sourceUuid` on the local application's `flows[]`
- **THEN** no new `Flow` entity is created
- **AND** the report records the item as `skipped` with reason `already-exists`

#### Scenario: Flows degrade without a local application context
- **WHEN** a template declares flows and the caller supplies no local
  application uuid (no Application has been materialised yet)
- **THEN** the flows channel reports `skipped` with reason
  `no-local-application-context`
- **AND** the declared count still reflects the number of flows in the template

### Requirement: Published agents are tagged with the local application's slug

A parsed v2 app repo template's `agents` channel carries every agent that
pointed at the source application, keyed by its published uuid. The applier
SHALL write each one at its published uuid, following the same
never-overwrite rule connectors already carry, and SHALL ALWAYS overwrite
the published `applicationSlug` with the LOCAL application's own slug before
writing — never the value published in the blob.

#### Scenario: A published agent is created and tagged locally
- **WHEN** a template declares one agent published with `applicationSlug: "source-app"`,
  and the local application's own slug is `local-app`
- **THEN** the agent object is created at its published uuid
- **AND** its stored `applicationSlug` is `local-app`, never `source-app`

#### Scenario: A colliding agent uuid is skipped, never overwritten
- **WHEN** a declared agent uuid already exists locally
- **THEN** the existing object is not modified in any field
- **AND** the report records that item as skipped with reason `already-exists`

#### Scenario: Agents degrade without a local application context
- **WHEN** a template declares agents and the caller supplies no local
  application slug
- **THEN** the agents channel reports `skipped` with reason
  `no-local-application-context`
- **AND** the declared count still reflects the number of agents in the template

## Notes

- Live-verified end-to-end 2026-08-19 (app-repo-format-flow-agent-export):
  publish → real disposable GitHub repository → pull into a second, separate
  local Application on the same instance. A flow bound on the source
  Application landed as a genuine `Flow` entity, owner/organisation-stamped,
  rebound onto the target Application's own `flows[]` with `sourceUuid`
  correctly persisted (the schema-declared-before-write ADR-037 discipline
  held — the property was NOT silently dropped). An agent published with a
  foreign `applicationSlug` landed tagged with the LOCAL application's own
  slug. A second pull of the same repository skipped the already-bound flow
  (`sourceUuid` match) and the already-existing agent (uuid collision)
  without duplicating either, while a newly-added agent in the same pull was
  still created — confirming idempotency and the create path both hold in
  the same apply.
- `FlowAndAgentExportBundler::bundleAgents()` (export side, untouched by
  this capability) resolves agents via hardcoded numeric register/schema ids
  rather than slugs, unlike this capability's own connector/register
  resolution. Confirmed live 2026-08-19 to be more than a theoretical
  portability gap: on a freshly-provisioned instance, whose register/schema
  ids are never the exact numbers baked into that class, the agents export
  channel silently produces zero entries even when a matching agent exists.
  Flagged for future work; not fixed here, and out of this capability's own
  scope (it lives entirely on the export side).
