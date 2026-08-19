## Context

`FlowAndAgentExportBundler::bundleAgents()` is the single place both of
OpenBuild's export systems resolve "which agents point at this
application" (see `openspec/changes/openbuild-exports-flows-and-agents/`
for how it came to exist, and `openspec/changes/archive/2026-08-19-app-repo-format-flow-agent-export/`
for its second caller). Its filter carried hardcoded numeric register/schema
ids that only happen to be correct on the instance where the class was
written.

## Goals / Non-Goals

- **Goal**: make `bundleAgents()`'s register/schema resolution portable
  across instances, matching the portability `bundleFlows()` already has
  (UUID-bound) and the portability the rest of this codebase's
  register/schema lookups already have (slug-bound).
- **Goal**: keep the fix to the two constants and their type — no new
  collaborator, no constructor signature change, no behaviour change for
  any caller.
- **Non-Goal**: touching `bundleFlows()`. It already resolves
  `Application.flows[].flow` by UUID against the `Flow` entity and is not
  implicated in this bug (see the class docblock, "BINDING BY UUID").
- **Non-Goal**: changing `AppRepoSerializer`, `AppChannelApplier`,
  `FlowAgentChannelCollector`, `FlowChannelProvisioner`, or
  `AgentChannelProvisioner` (merged today via #255). They call
  `bundleAgents()` through `FlowAgentChannelCollector` unchanged and
  inherit the fix for free.
- **Non-Goal**: introducing a general id↔slug resolver service for this one
  call site. `ObjectSchemaSlugResolver` (this codebase's existing resolver)
  goes the OTHER direction — numeric id → slug, for turning an event
  payload's ids into slugs to compare against a literal — which is not
  what `bundleAgents()` needs. `bundleAgents()` needs a slug literal to
  pass INTO a filter, which `ObjectService::findAll()` already accepts
  directly; adding a resolver here would be solving an already-solved
  problem with more code.

## Decision

Change the two constants from numeric ids to slug strings:

```php
private const OPENBUILD_REGISTER = 'openbuild';
private const AGENT_SCHEMA = 'agent';
```

(`@var int` docblocks become `@var string`.) No other line in
`bundleAgents()` changes — the same `ObjectService::findAll()` call, same
`filters` array shape, same `applicationSlug` matching.

**Why this resolves, not just relabels**: `ObjectService::findAll()`
prepares its config by calling `setRegister()`/`setSchema()` when
`filters.register`/`filters.schema` are present
(`ObjectService::prepareFindAllConfig()`). Both setters accept
`Register|Schema|string|int`; a `string` value is resolved through
`RegisterMapper::find()`/`SchemaMapper::find()`, whose `find()` supports id,
UUID, **and slug** lookup. Passing a slug therefore routes through the
exact same mapper-backed resolution `AgentsController`,
`DataRegisterProvisioner`, and `AppChannelApplier::credentialExists()`
already use for their own `findAll()`/`find()` calls — this is not a novel
mechanism, it is the mechanism already used everywhere else in this
codebase for register/schema lookup, applied to the one call site that had
not adopted it.

Register is set before schema in `prepareFindAllConfig()`, so the schema
slug lookup is REGISTER-SCOPED (`ObjectService::setSchema()`'s documented
behaviour) — `agent` resolves within the `openbuild` register specifically,
not globally across every register on the instance that might reuse the
slug. This matters because `ObjectSchemaSlugResolver`'s own docblock
records that schema slugs are not globally unique on this instance
(`automation` exists as two different schemas, ids 71 and 5103).

## Alternatives Considered

1. **Inject `RegisterMapper`/`SchemaMapper` and resolve to ids explicitly
   in the constructor**, mirroring `AgentsController::loadRunsForAgent()`.
   Rejected: adds two constructor parameters and two `find()` calls for a
   result `ObjectService::findAll()` already produces internally from a
   slug filter — extra code with no behavioural difference, and it would
   also require updating both existing production construction sites
   (`ExportService`, the `app-repo-format-flow-agent-export` wiring)
   instead of zero call sites.
2. **Read the ids from configuration/environment** rather than hardcoding
   them at all, so the value is instance-supplied. Rejected: openbuild's
   own `openbuild` register and `agent` schema are shipped BY openbuild
   (`register.d/*.json`) — they are not external configuration, they are
   this app's own fixed identity, addressed the same way every other
   in-app lookup in this codebase addresses them: by slug.

## Risks / Trade-offs

- None identified. This narrows the failure mode from "silently zero
  agents on any instance whose ids differ" to "resolves correctly
  everywhere the `openbuild` register and `agent` schema are actually
  installed under those names" — which they always are, since openbuild
  installs them itself.

## Migration Plan

None — read path only, no stored data changes shape.

## Open Questions

None.
