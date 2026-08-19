## ADDED Requirements

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
