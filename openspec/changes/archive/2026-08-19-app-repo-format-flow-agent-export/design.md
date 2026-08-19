## Context

Two independent export/import systems both need to answer "which flows and
agents does this application carry" — openbuild-exporter already answers it
correctly (`FlowAndAgentExportBundler`, PR #233); app-repo-format-v2 has
never asked the question. This design wires the second system to the first
system's existing, tested answer, rather than growing a second one.

Existing machinery this design builds on, rather than reinvents:

| existing | reused for |
|---|---|
| `FlowAndAgentExportBundler::bundle()` | the entire flow/agent RESOLUTION — reading the `Flow` entity (never the `agentflow` mirror), reading agents by `applicationSlug` |
| `FlowService::save()` (openregister) | flow creation — owner/organisation stamping, trigger-index rebuild |
| `ObjectService::saveObject(uuid:, failIfExists:)` | the agents channel's UUID-preserving upsert (same pattern connectors already use) |
| `DataRegisterProvisioner` / `SkillChannelDelegate` | the "split a channel into its own provisioner class" shape `FlowChannelProvisioner`/`AgentChannelProvisioner` follow |

## Goals / Non-Goals

**Goals:**
- A published `buildiq-*` repository carries the application's real flows
  and agents, not just its manifest and companion schemas.
- Zero reimplementation of flow/agent resolution: the export side calls
  `FlowAndAgentExportBundler`, unmodified.
- The import side creates flows/agents through the same sanctioned entry
  points every other OpenBuild consumer uses (`FlowService`, `ObjectService`)
  — never a raw insert that bypasses owner/org stamping or trigger indexing.
- A repeat pull/re-install of the same repository is idempotent for both
  channels, matching every other channel's skip-if-exists behaviour.

**Non-Goals:**
- Touching `FlowAndAgentExportBundler.php` or `ExportService.php`
  (openbuild-exporter) in any way — that system is already correct and nothing
  here depends on changing it.
- Preserving a flow's published UUID on the target instance. See Decision 2.
- Updating an existing flow or agent on re-apply — only ever create, matching
  `apply-v2-channels`' own Non-Goal for every other channel.

## Decisions

### Decision 1: Export reuses `FlowAndAgentExportBundler` via a scratch-directory adapter, not by extracting shared logic

Two ways existed to reuse System 1's resolution logic from `AppRepoSerializer`:
call `FlowAndAgentExportBundler` directly, or extract a shared "resolve
flows/agents" primitive both serializers call.

Extraction was rejected because it requires editing
`FlowAndAgentExportBundler.php` — already correct, already shipped in PR
#233, explicitly out of scope. Calling it directly is therefore the only
option that reuses without touching.

The cost of calling it directly: `bundle()`'s contract is "write into a
scratch directory" (mirrored from `DataRegisterExportBundler`, and
`ExportService` already drives it this way), not "return a `path =>
contents` map" the way every other `AppRepoSerializer` collector does.
`FlowAgentChannelCollector::collect()` is the adapter: it hands the bundler
a throwaway temp directory, reads `lib/Settings/flows/*.json` and
`lib/Settings/agents/*.json` back with the SAME uuid-pattern path
revalidation the parser applies on the read side ("every channel path is
validated before use" — the rule does not stop applying just because the
source this time is our own bundler rather than an untrusted repository),
and deletes the scratch tree in a `finally` block.

This is filesystem I/O inside a class documented as "pure transformation...
NO OpenRegister writes" — the local-disk scratch write is not an OpenRegister
write, so the documented invariant holds, but it is still a new kind of side
effect for this class. Accepted: the alternative was reimplementing
`FlowMapper::findByUuid()` resolution and the entity-vs-mirror distinction a
second time, which is the exact defect class `FlowAndAgentExportBundlerTest`
exists to catch, and no fixture in a reimplementation could be trusted not to
reproduce it.

### Decision 2: Flows are created via `FlowService::save()`, minting a fresh local uuid — not seeded at the published uuid

`openbuild-app-binds-flows-and-agents`'s own proposal says the importing
side should "seed the flow with the same [uuid] rather than minting a new
one." That statement was written for a DIFFERENT deployment path: an
openbuild-exporter scaffold's own Nextcloud migration inserting a `Flow` row
directly, with no live session, no owner, no trigger index to reconcile —
seeding the exact uuid there is both possible and correct.

Applying a v2 repo onto a running instance is not that path. It always runs
inside a real request with a real session, so the correct move is the one
ADR-022 already prescribes for every other flow consumer: go through
`FlowService::save()`. That entry point has no parameter to seed a
caller-chosen uuid — it always mints one, and it does so *because* create
also stamps `owner`/`organisation` from the acting session and rebuilds the
trigger index from the nodes just written. A raw `FlowMapper` insert at a
chosen uuid would have to reimplement both by hand, off to the side of the
one place they are already implemented correctly.

**Consequence for idempotency**: since the local uuid is never the published
one, "does this flow already exist" cannot be answered by comparing uuids
the way connectors and agents are. It is answered instead by
`sourceUuid` — the published uuid, carried forward as a new OPTIONAL
property on the `Application.flows[]` binding item
(`register.d/22-flows-and-agents.json`, declared before
`FlowChannelProvisioner` ever writes it — the ADR-037 lesson). Before
creating a flow, the provisioner checks whether the LOCAL application's own
`flows[]` already carries a binding whose `sourceUuid` matches; if so, it is
skipped and reported `already-exists`, same as every other channel.

**Precedent already in this file**: `DataRegisterProvisioner` does the
analogous thing for schemas — `SchemaMapper::createFromArray()` mints its
own id too, and identity is tracked by slug, not by any id carried from the
source. Flows lack a slug (`FlowAndAgentExportBundler`'s own docblock: "the
Flow entity has no slug"), so `sourceUuid` is this channel's equivalent of
that slug-based identity anchor.

### Decision 3: Agents keep their published uuid; flows do not

An agent has no separate binding the way a flow does — deliberately, per
`FlowAndAgentExportBundler`'s own docblock: `agent.applicationSlug` IS the
relationship, and a second edge from the Application would just be able to
disagree with the first. Because nothing downstream needs to be rebound to a
fresh identity, `AgentChannelProvisioner` follows the connector precedent
exactly: `saveObject(uuid: $publishedUuid, failIfExists: true)`. A collision
is skipped and reported, never overwritten — the same guarantee connectors
already carry.

`applicationSlug` in the published blob is ALWAYS overwritten to the LOCAL
application's own slug before the write. Carrying the source instance's slug
across would tag the agent as belonging to an application that, on this
instance, is a different app or does not exist — identity data crossing an
install boundary unchanged, the same class of bug
`HybridMetadataLockListener`'s own docblock documents for `description`
arriving as an unintended `null` across a PUT-semantic write.

### Decision 4: `AppRepoSerializer` sheds pure helpers into `AppRepoPayloadSafety`

Adding the flows/agents channel pushed `AppRepoSerializer` (and, separately,
`AppChannelApplier`) past the repo's own size/complexity gates
(`ExcessiveClassLength`, `ExcessiveClassComplexity`) — the same tooling
signal that already justified splitting `DataRegisterProvisioner` and
`SkillChannelDelegate` out of `AppChannelApplier` before this change existed.

For `AppRepoSerializer`, the cut is `stripSecrets()`, `isSafeSlug()`,
`isSafeUuid()` and `connectorFileName()` — four methods with zero dependency
on the class's own collaborators (no `RegisterMapper`, no
`ObjectServiceInterface`, no logger; pure functions of their arguments) —
moved verbatim into `AppRepoPayloadSafety`, constructed with a parameter
default (`= new AppRepoPayloadSafety()`) so no existing caller or test needs
to change. No behaviour changed by the move.

For `AppChannelApplier`, the cut is the entire agents channel, which was
added as a private method and immediately became the piece that tipped the
class over `ExcessiveClassComplexity`; it became `AgentChannelProvisioner`
instead, injected the same way `FlowChannelProvisioner` is.

## Risks / Trade-offs

- **[Risk]** `FlowChannelProvisioner::rebindApplication()` is a two-step
  write (create flows, then save the Application's updated `flows[]`).
  OpenRegister has no cross-object transaction, so a failure on the second
  step leaves already-created flows unbound. **Mitigation**: logged loudly
  (`error`, not `warning`) — the created flows still show as `created` in
  the report, which is honest (they exist) but incomplete (they are not yet
  reachable); this matches `apply-v2-channels`' own accepted stance that
  atomicity is not deliverable and will not be faked.
- **[Trade-off]** A flow's identity is NOT stable across a publish → pull →
  re-publish round trip — pulling `buildiq-hydra` onto a second instance and
  republishing it from there mints yet another local uuid. Accepted: nothing
  in this workstream currently depends on flow-uuid stability across more
  than one hop, and forcing stability would mean bypassing `FlowService`,
  which Decision 2 already rejects.
- **[Risk]** `FlowAndAgentExportBundler::bundleAgents()` (System 1, untouched)
  resolves agents via hardcoded register/schema **numeric ids** (206/5060 on
  the dev instance) rather than slugs, unlike this change's own
  `collectAutomations()`-style slug lookups. Reused as-is per Decision 1;
  flagged here because it is a latent portability gap on an instance where
  those ids differ, not introduced or fixed by this change.
