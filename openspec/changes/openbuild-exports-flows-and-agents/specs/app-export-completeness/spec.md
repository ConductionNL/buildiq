## Purpose

Defines what an exported OpenBuild application contains and what that export is good for. The guarantee is not that the ZIP mentions the app's parts, but that an instance which imports it can RUN them — an export whose flows import but never fire is worse than one that omits them, because nothing surfaces the difference.

## ADDED Requirements

### Requirement: An export MUST carry the flows and agents the application binds

The exporter MUST resolve the application's `flows` and `agents` bindings and write each definition into the scaffold as JSON, alongside the data-register schemas it already bundles.

Flow definitions MUST be read from the OpenRegister **`Flow` entity**. They MUST NOT be read from the `agentflow` object store, which mirrors some definitions and drifts from the entity — a definition present in the object store and stale relative to the entity would export a graph that is not the one the engine runs.

#### Scenario: A bound flow is written into the export
- **WHEN** an application binding `{"label": "Hydra sequencer", "flow": "hydra-sequencer"}` is exported
- **THEN** the ZIP MUST contain that flow's definition as JSON
- **AND** its nodes and edges MUST match the `Flow` entity, node for node

#### Scenario: The entity is the source, not the mirror
- **WHEN** a flow exists in BOTH the `Flow` entity and the `agentflow` object store, with different node counts
- **THEN** the exported definition MUST be the one from the `Flow` entity

#### Scenario: A bound agent is written into the export
- **WHEN** an application binds an agent by slug
- **THEN** the ZIP MUST contain that agent's definition as JSON

### Requirement: A flow using AGENTIC nodes MUST export through the ordinary path

There is one flow system (ADR-065). A flow whose nodes include agentic types contributed by hermiq is an ordinary OpenRegister flow and MUST be resolved, bundled and seeded by the same code as any other flow.

The exporter MUST NOT branch on node type, MUST NOT special-case hermiq, and MUST NOT emit a different file shape for a flow that calls an agent.

#### Scenario: An agentic flow exports like any other
- **WHEN** a bound flow contains `hermiq.workload-step` nodes
- **THEN** it MUST be bundled by the same path as a flow containing none
- **AND** the emitted JSON MUST have the same shape

### Requirement: An imported flow MUST be RUNNABLE, not merely present

An app that ships flow JSON MUST seed it into the importing instance's `Flow` table on install AND on update. An export that lands as files nobody loads produces an app whose flows are invisible to the engine.

Seeding MUST write the `Flow` entity, for the same reason the exporter reads it.

#### Scenario: A seeded flow can actually execute
- **WHEN** an exported app is installed on an instance that did not have the flow
- **THEN** the flow MUST exist as a `Flow` entity
- **AND** queueing a run of it MUST execute its nodes — the proof is a run, not a read-back

#### Scenario: Seeding happens on update, not only on first install
- **WHEN** an already-installed app is updated to a version carrying a changed flow
- **THEN** the update MUST seed the changed definition
- **AND** an install-only hook MUST NOT be relied on, because an app is installed once and updated many times

### Requirement: Seeding MUST be idempotent and MUST NOT clobber local edits

Re-running the seeder MUST NOT duplicate a flow. An operator who has edited a seeded flow on their own instance MUST NOT silently lose that edit to the next update.

#### Scenario: Seeding twice yields one flow
- **WHEN** the seeder runs twice with the same definition
- **THEN** exactly one `Flow` entity MUST exist for that slug

#### Scenario: A locally edited flow is not silently overwritten
- **WHEN** a seeded flow has been modified on the importing instance and the app is updated
- **THEN** the local modification MUST NOT be discarded without the change being recorded where an operator can see it

### Requirement: An unresolvable binding MUST be reported, not silently dropped

A bound slug that resolves to nothing MUST NOT fail the export, and MUST NOT vanish either. The export job's result MUST name what was skipped.

This follows the existing precedent — an unresolvable data register is skipped with a log line — but strengthens it: a log line alone is not a report, because the operator reading the finished job never sees it.

#### Scenario: A dangling flow binding is named in the result
- **WHEN** an application binds `{"flow": "flow-that-was-deleted"}` and is exported
- **THEN** the export MUST succeed
- **AND** the job result MUST name that binding as skipped, with the reason

### Requirement: A flow naming an UNREGISTERED node type MUST be surfaced at seeding

A flow may reference a node type the importing instance has not registered — an agentic node on an instance without hermiq is the ordinary case. Seeding it silently produces a flow that exists, validates, and cannot fire.

The seeder MUST detect this and surface it.

#### Scenario: An agentic flow seeded where the node type is absent
- **WHEN** a flow containing `hermiq.workload-step` is seeded on an instance where that type is not in the node registry
- **THEN** the condition MUST be surfaced to the operator
- **AND** the flow MUST NOT be presented as ready to run

### Requirement: The export UI MUST let an operator include flows and agents, and the round trip MUST be covered end to end

The behaviour MUST be verified through the interface an operator actually uses, and the verification MUST include the half that fails silently: importing and running.

#### Scenario: An operator exports an app with its flows and agents
- **WHEN** an operator binds a flow and an agent to an application in the UI and runs an export
- **THEN** the produced ZIP MUST contain both definitions

#### Scenario: The round trip is proven by execution
- **WHEN** the exported ZIP is imported on an instance that did not have the flow
- **THEN** the flow MUST be runnable there
- **AND** the test MUST assert on a RUN, because an import that produces an unrunnable flow passes every assertion that only inspects files
