## Tasks

### 1. Export side — flows and agents channels

- [x] Add `lib/Service/FlowAgentChannelCollector.php`, adapting `FlowAndAgentExportBundler::bundle()`'s scratch-directory output into the `path => contents` map convention
- [x] Wire it into `AppRepoSerializer::serialize()` as two new channels, `flows/<uuid>.json` and `agents/<uuid>.json`, with `channels.flows`/`channels.agents` counts in the descriptor
- [x] Extend `AppRepoParser::parseChannels()` to read both, UUID-keyed and path-revalidated (never slug-keyed, unlike every other channel)

Acceptance criteria
- Serialising an application with a resolvable flow binding emits `flows/<uuid>.json` carrying the flow's uuid/name/nodes/edges
- Serialising an application with an agent pointing at it emits `agents/<uuid>.json` with no `@self` envelope
- A dangling flow binding is counted in `channels.flows.skipped`, not silently dropped
- Absent a collector (v1 construction shape), both channels degrade to empty without throwing
- `FlowAndAgentExportBundler.php` is not modified

### 2. Import side — flows

- [x] Add `lib/Service/FlowChannelProvisioner.php`: create each published flow via `FlowService::save()`, never a raw `FlowMapper` insert
- [x] Declare the OPTIONAL `sourceUuid` property on the flow binding item schema (`register.d/22-flows-and-agents.json`) before writing it
- [x] Rebind newly created flows onto the local application's `flows[]`, carrying the published uuid forward as `sourceUuid`
- [x] Skip a flow whose `sourceUuid` is already bound locally, recording `already-exists`
- [x] Bound at `MAX_FLOWS = 512` with truncation logged and counted

Acceptance criteria
- A published flow becomes a real `Flow` entity, owner/organisation-stamped and trigger-indexed through the sanctioned entry point
- A repeat apply of the same repository does not duplicate flows
- Without a local application uuid, the channel reports `skipped` with reason `no-local-application-context`, declared count preserved

### 3. Import side — agents

- [x] Add `lib/Service/AgentChannelProvisioner.php`: write each published agent at its published uuid via `saveObject(uuid:, failIfExists: true)`
- [x] Always overwrite `applicationSlug` with the LOCAL application's own slug before writing, never the published value
- [x] Bound at `MAX_AGENTS = 512` with truncation logged and counted

Acceptance criteria
- A colliding agent uuid is left untouched and reported `skipped` with reason `already-exists`
- An installed agent's `applicationSlug` always matches the local application, never the source instance's
- Without a local application slug, the channel reports `skipped` with reason `no-local-application-context`, declared count preserved

### 4. Wiring both channels into the two install seams

- [x] Thread `applicationUuid`/`applicationSlug` through `AppChannelApplier::apply()` and both its callers (`ApplicationsController::installFromTemplateArray()`, `GitHubAppSyncService::pull()`)
- [x] Extract `AppRepoPayloadSafety` (pure path-safety/secret-redaction helpers) out of `AppRepoSerializer`, and `AgentChannelProvisioner` out of `AppChannelApplier`, so both classes stay under the repo's own size/complexity gates

Acceptance criteria
- `AppChannelApplier::apply()`'s existing five-channel behaviour is unchanged for every existing test
- `composer phpmd` reports no new `ExcessiveClassLength`/`ExcessiveClassComplexity` finding

### 5. Tests and quality

- [x] Unit tests covering the export channels (real `FlowAndAgentExportBundler`, mocked `FlowMapper`/`ObjectService`) and the parser round-trip
- [x] Unit tests covering flow create+rebind, repeat-apply idempotency, and agent tag/collision/degrade behaviour in `AppChannelApplierTest`
- [x] `composer phpunit`/`phpcs`/`phpmd`/`psalm`/`phpstan` clean on the changed files (pre-existing, unrelated `ZipArchive`/`ajv` environment gaps excluded — see report)
