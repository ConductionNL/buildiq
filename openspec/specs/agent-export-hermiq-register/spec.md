# agent-export-hermiq-register Specification

## Purpose
`FlowAndAgentExportBundler::bundleAgents()` finds an application's agents by
querying OpenRegister scoped to buildiq's own `agent` schema. That scope cannot
see an agent living in hermiq's own register (hermiq declares its own `agent`
schema in its own `hermiq` register, and an agent there can carry
`applicationSlug` too — hermiq-agent-application-slug). This capability adds a
fallback lookup against hermiq's register, consulted only when buildiq's own
schema finds nothing and only when hermiq is installed, so an application whose
agents live in hermiq's register is not silently exported with zero agents.

## Requirements

### Requirement: buildiq's own agent schema is queried first
The system MUST query `register: buildiq, schema: agent` for the given
`applicationSlug` before consulting any other store. The common case — an
application's agents living in buildiq's own store — MUST stay a single query.

#### Scenario: buildiq's own schema already has the agents
- GIVEN an application whose agents live in buildiq's own `agent` schema
- WHEN `bundleAgents()` runs
- THEN only the buildiq-scoped query is issued
- AND `IAppManager::isEnabledForUser()` is never called
@e2e exclude Backend-only export bundler with no UI surface; asserted by
`testTheFallbackIsSkippedWhenOpenbuildsOwnSchemaAlreadyFoundAgents()`.

### Requirement: hermiq's register is a fallback when buildiq's own schema finds nothing
The system MUST query `register: hermiq, schema: agent` for the same
`applicationSlug` when buildiq's own schema query returns no results AND hermiq
is installed (`IAppManager::isEnabledForUser('hermiq')`). This is a FALLBACK, not
a merge: an application's agents are expected to live in one store or the other.

#### Scenario: An agent living only in hermiq's register is found
- GIVEN an application with no agents in buildiq's own `agent` schema
- AND a real agent in hermiq's own register carrying the same `applicationSlug`
- WHEN `bundleAgents()` runs and hermiq is installed
- THEN the hermiq-register agent is bundled into the export
- AND its richer shape (`prompt`, `tools`) travels into the exported JSON
  unmodified — never coerced into buildiq's own narrower `agent` schema
@e2e exclude Backend-only export bundler with no UI surface; asserted by
`testAnAgentLivingOnlyInHermiqsRegisterIsFoundByTheFallback()`. Live-verified on
a fresh isolated instance: the OLD code (pre-fallback) silently found zero
agents for `hydra-console` despite three real matching agents existing in
hermiq's register; the NEW code found all three, correctly excluding a fourth
agent carrying a different `applicationSlug`.

### Requirement: The fallback never runs when hermiq is not installed
The system MUST NOT attempt the hermiq-register query when hermiq is not
installed, even when buildiq's own schema finds nothing — buildiq does not
hard-depend on hermiq (mirrors `SkillChannelDelegate`'s existing optional-dependency
pattern elsewhere in this codebase).

#### Scenario: hermiq is absent
- GIVEN hermiq is not installed
- AND buildiq's own `agent` schema finds no agents for the application
- WHEN `bundleAgents()` runs
- THEN only the buildiq-scoped query is ever issued
- AND the export proceeds with no agents rather than failing
@e2e exclude Backend-only export bundler with no UI surface; asserted by
`testTheFallbackIsNeverAttemptedWhenHermiqIsNotInstalled()`.

### Requirement: A failed fallback degrades, it does not fail the export
A failure in the hermiq-register fallback query MUST be logged and MUST NOT
propagate — the export proceeds with no agents from that source, since
buildiq's own lookup already succeeded (with zero results) before the fallback
was even attempted.

#### Scenario: The hermiq-register query throws
- GIVEN buildiq's own schema found no agents
- AND hermiq is installed
- WHEN the hermiq-register query throws
- THEN `bundleAgents()` returns no skip and no exception propagates
@e2e exclude Backend-only export bundler with no UI surface; covered by
`findHermiqRegisterAgentsOrEmpty()`'s catch block.
