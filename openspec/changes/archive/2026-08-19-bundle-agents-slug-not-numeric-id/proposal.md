---
kind: code
---

## Why

`FlowAndAgentExportBundler::bundleAgents()` resolves the `agent` schema's
register and schema via two hardcoded numeric class constants:

```php
private const OPENBUILD_REGISTER = 206;
private const AGENT_SCHEMA = 5060;
```

Numeric register/schema ids in OpenRegister are auto-increment columns —
they are assigned in whatever order registers/schemas happen to be created
on an instance, and are **not stable across a fresh install**. `206`/`5060`
are this dev instance's ids for `openbuild`/`agent`; a fresh instance that
installs openbuild after even one extra register or schema exists gets
different numbers.

`bundleAgents()` queries with these ids as an `ObjectService::findAll()`
filter. When they do not match the local instance, the filter matches
nothing — `findAll()` returns `[]`, not an error. There is no exception, no
log line, no skip record: the method returns cleanly having found zero
agents, indistinguishable from an application that genuinely has none.

This was confirmed live, not theoretically: `app-repo-format-flow-agent-export`
(merged today, PR #255) wired this exact method into the `buildiq-*`
GitHub-repo export/import round trip via `FlowAgentChannelCollector`. Its
live round-trip test on a fresh instance hit exactly this failure mode —
zero agents bundled — and had to work around it manually to finish
verification, because the fresh instance's `openbuild` register and `agent`
schema did not happen to land on ids 206/5060.

`bundleAgents()` is now the shared, reused resolution logic for BOTH of
OpenBuild's export systems: `ExportService`'s standalone-app export, and
`AppRepoSerializer`'s buildiq-* GitHub round trip (via
`FlowAgentChannelCollector`). A silent-zero-agents bug here silently drops
agents from every app exported by either path, on any instance whose ids
differ from this one's — which is every fresh install. This directly
threatens the goal both systems exist for: genuine cross-instance app
portability.

## What Changes

Replace the two numeric class constants with the SLUG strings they name —
`'openbuild'` and `'agent'` — mirroring the pattern already established
elsewhere in this codebase (`AgentsController::REGISTER_SLUG = 'openbuild'`
/ `AGENT_SCHEMA = 'agent'`, `ObjectSchemaSlugResolver::REGISTER_SLUG`,
`AppChannelApplier::CREDENTIAL_REGISTER`/`CREDENTIAL_SCHEMA`). Slugs are
assigned at register/schema creation time from the app's own
`register.d`/schema definitions and are identical across every install of
openbuild — unlike the auto-increment id, a slug IS the portable identity.

`ObjectService::findAll()`'s `filters.register`/`filters.schema` already
accept a slug directly (`ObjectService::setRegister()`/`setSchema()`
resolve a string filter value through `RegisterMapper`/`SchemaMapper`,
which support slug lookup) — `AppChannelApplier::credentialExists()` already
relies on exactly this for its own `findAll()` filter. No new collaborator,
no constructor change, no call-site change beyond the two constants: this
is the minimal fix, not a rewrite of the resolution mechanism.

## Capabilities

### Modified Capabilities
- `openbuild-exporter`: agent resolution for export is now portable across
  instances (slug-based), rather than being pinned to whichever numeric
  ids `openbuild`'s register and `agent` schema happened to receive on the
  instance where the bundler was written.

## Impact

- **Modified**: `lib/Service/FlowAndAgentExportBundler.php` (two constants,
  `int` → `string`, value changed from numeric id to slug)
- **Modified**: `tests/Unit/Service/FlowAndAgentExportBundlerTest.php`
  (asserts the `findAll()` filter now carries the slugs, not the old
  numeric ids — the regression this bug would reintroduce)
- **Not touched**: `bundleFlows()` (already UUID-based, not implicated),
  `AppRepoSerializer`/`AppChannelApplier`/`FlowAgentChannelCollector`/
  `FlowChannelProvisioner`/`AgentChannelProvisioner` (call `bundleAgents()`
  unchanged; they benefit from the fix without any change of their own)
- **Data**: none — read-only query, no migration
