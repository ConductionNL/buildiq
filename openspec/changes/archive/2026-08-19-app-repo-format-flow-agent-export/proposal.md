---
kind: code
---

## Why

OpenBuild has two independent export/import systems, and only one of them
carries flows and agents.

**openbuild-exporter** (`ExportService`/`RunExportJob`, PR #233) already
exports an app's flows and agents correctly: `FlowAndAgentExportBundler`
reads the OpenRegister `Flow` **entity** (never the `agentflow` object
mirror it drifts from) via `Application.flows[]`'s UUID bindings, and reads
agents by querying `agent.applicationSlug`. Verified live: `hydra-console`'s
Application object already carries `flows[]` populated with all 12 real
hydra pipeline flow UUIDs.

**app-repo-format-v2** (`AppRepoSerializer`/`AppChannelApplier`/
`GitHubAppSyncService`) is the OTHER export/import system — the one that
produces and consumes the `buildiq-*` GitHub content repos via shop-install
and owner push/pull. Its `automations/` channel reads OpenBuild's own
pre-ADR-065 `register: openbuild, schema: automation` primitive, which
hydra's real pipeline was never modelled through. Confirmed live:
`buildiq-hydra` has no `automations`/`flows` directory at all.

The consequence: publishing `buildiq-hydra` today produces a repository with
a manifest and companion schemas, and **none of the 12 flows or any agent
that makes the app do anything** — the same class of "reports success, ships
nothing that runs" defect `apply-v2-channels` closed for
connectors/registers/automations/skills, now found in the two channels that
change added no room for.

The data and the correct read-side logic already exist
(`FlowAndAgentExportBundler`). This change wires them into app-repo-format-v2
rather than reimplementing flow/agent resolution a second time.

## What Changes

**Export side** — `AppRepoSerializer::serialize()` gains two new channels,
`flows/<uuid>.json` and `agents/<uuid>.json`, alongside the existing four.
They are collected by calling `FlowAndAgentExportBundler` directly (the
SAME tested class `ExportService` already uses) through a small adapter,
`FlowAgentChannelCollector`, that translates its scratch-directory contract
into the `path => contents` map convention every other channel here uses.
`FlowAndAgentExportBundler` itself is untouched — this is reuse by calling
it, not by extracting or duplicating its resolution logic.

**Import side** — `AppChannelApplier` gains two new channels:

- **Flows** — `FlowChannelProvisioner` creates each published flow through
  `FlowService::save()`, the ADR-022-sanctioned single entry point for
  flows (never a raw `FlowMapper` insert, so owner/organisation stamping and
  trigger-index rebuilding are not reimplemented by hand). `FlowService`
  always mints its own uuid on create, so the published uuid cannot be
  seeded verbatim the way a connector's can; instead it is carried forward
  as `sourceUuid` on the new `Application.flows[]` binding, which is what a
  repeat apply of the same repository checks to stay idempotent. The
  binding item schema (`register.d/22-flows-and-agents.json`) gains this
  OPTIONAL `sourceUuid` property, declared before it is written — the
  ADR-037 lesson this workstream has already paid for once.
- **Agents** — `AgentChannelProvisioner` writes each published agent at its
  published uuid (an agent has no binding to preserve, so this can and does
  follow the connector precedent exactly), with `applicationSlug` ALWAYS
  overwritten to the LOCAL application's own slug — never the source
  instance's, the same class of bug `HybridMetadataLockListener`'s own
  docblock warns against for identity fields crossing an install boundary.

Both new channels follow every existing rule in `apply-v2-channels`
unchanged: skip-if-already-applied rather than overwrite, best-effort with a
complete per-item outcome report, an explicit bound with truncation
reported rather than silently dropped.

## Capabilities

### Modified Capabilities
- `github-app-repo-format`: a published v2 repository additionally carries
  the application's bound flows and the agents that point at it.
- `app-channel-application`: applying a parsed v2 repo additionally creates
  OpenRegister flows (rebound onto the local application) and agents
  (tagged with the local application's slug).

## Impact

- **New**: `lib/Service/FlowAgentChannelCollector.php`,
  `lib/Service/FlowChannelProvisioner.php`,
  `lib/Service/AgentChannelProvisioner.php`,
  `lib/Service/AppRepoPayloadSafety.php` (extracted from `AppRepoSerializer`
  for size, no behaviour change — see design.md)
- **Modified**: `lib/Service/AppRepoSerializer.php` (flows/agents channels),
  `lib/Service/AppRepoParser.php` (parse the two new channels),
  `lib/Service/AppChannelApplier.php` (apply the two new channels),
  `lib/Controller/ApplicationsController.php` and
  `lib/Service/GitHubAppSyncService.php` (thread the local application's
  uuid/slug through to the applier),
  `lib/Settings/register.d/22-flows-and-agents.json` (`sourceUuid` property)
- **Not touched**: `lib/Service/FlowAndAgentExportBundler.php`,
  `lib/Service/ExportService.php` — openbuild-exporter's own working system
- **API**: install/pull `channels` reports gain `flows` and `agents` entries
- **Data**: creates OpenRegister `Flow` entities and `agent` objects on the
  target instance; never overwrites an existing one
