## Purpose

Defines what an OpenBuild application declares itself to be composed of. An application is not only a scaffold and a name: it binds the data registers, connectors, flows and agents that make it that application. These bindings are what an export reads, so a thing an application cannot declare is a thing an export cannot carry.

## ADDED Requirements

### Requirement: An application MUST be able to bind the FLOWS it is composed of

The `application` schema MUST declare an optional `flows` array. Each entry MUST carry a human `label` and a `flow` identifying one flow.

The binding MUST address the flow by SLUG, not by numeric id. Ids are per-instance: an export that carried them would resolve to a different flow, or to nothing, on the instance that imported it.

The binding is OPTIONAL and absent MUST mean "this application binds no flows". Every application that exists today declares none, and they MUST all remain valid.

#### Scenario: An application declares the flows it is made of
- **WHEN** an application object is saved with `flows: [{"label": "Hydra sequencer", "flow": "hydra-sequencer"}]`
- **THEN** the object MUST validate against the `application` schema
- **AND** the binding MUST be readable back in the shape it was written

#### Scenario: An application that binds no flows stays valid
- **WHEN** an existing application object with no `flows` property is loaded and re-saved
- **THEN** it MUST validate
- **AND** no `flows` value MUST be invented for it

#### Scenario: A flow is addressed by slug so it can travel
- **WHEN** a `flows` entry is written
- **THEN** the `flow` value MUST be the flow's slug
- **AND** a numeric id MUST NOT be accepted in its place, because an id resolves to a different flow on another instance

### Requirement: A bound flow MUST mean an OpenRegister flow, and there MUST NOT be a second kind

ADR-065 makes OpenRegister the only home for a flow engine. This binding MUST point at an OpenRegister `Flow`, and the schema MUST NOT grow a second binding for any app-specific notion of a flow.

A flow that calls an agent is an ORDINARY OpenRegister flow whose nodes are agentic node types contributed to the engine's registry by hermiq (`hermiq.workload-step`, `hermiq.workload-collect`). It is not a different kind of object and MUST NOT be bound through a different property.

⚠️ OpenRegister holds flow definitions in the `Flow` ENTITY. A parallel `agentflow` OBJECT store also exists in the hermiq register and mirrors some of them. The two drift: a definition written to the object left the engine executing the previous graph, with no error anywhere. The binding MUST therefore be understood to address the entity.

#### Scenario: An agentic flow binds through the same property as any other
- **WHEN** an application binds a flow whose nodes include `hermiq.workload-step`
- **THEN** it MUST use the `flows` binding
- **AND** no `agentFlows` or app-specific flow binding MUST exist to use instead

#### Scenario: The schema does not grow a second flow vocabulary
- **WHEN** the `application` schema is inspected
- **THEN** exactly one property MUST bind flows
- **AND** its description MUST name the OpenRegister `Flow` entity as the referent

### Requirement: An application MUST be able to bind the AGENTS it is composed of

The `application` schema MUST declare an optional `agents` array. Each entry MUST carry a human `label` and an `agent` identifying one agent by slug.

The binding MUST follow the same rules as `flows`: slug-addressed, optional, and absent meaning "binds none".

#### Scenario: An application declares its agents
- **WHEN** an application object is saved with `agents: [{"label": "Code reviewer", "agent": "juan-claude-van-damme"}]`
- **THEN** the object MUST validate
- **AND** the binding MUST be readable back in the shape it was written

### Requirement: A binding MUST have the same shape as the bindings that already exist

`flows` and `agents` MUST be arrays of objects carrying a `label` and one slug field, matching `dataRegisters` (`{label, register}`) and `connectors`. The UI, the export payload and the reviewer all recognise that shape; a binding that invented its own would be handled by none of them.

#### Scenario: The new bindings are shaped like dataRegisters
- **WHEN** the `application` schema's `flows` and `agents` properties are compared with `dataRegisters`
- **THEN** all three MUST be arrays of objects
- **AND** each MUST carry a `label` plus exactly one slug-valued reference field

### Requirement: A dangling binding MUST be reportable rather than fatal

A bound slug that resolves to nothing MUST be a recoverable, reportable state — not a validation failure that makes the application unsaveable.

An application whose flow was renamed or deleted is a real and ordinary situation, and the existing precedent already models it: a data register that cannot be resolved is skipped with a log line rather than failing the export.

#### Scenario: A bound flow that no longer exists does not make the app invalid
- **WHEN** an application binds `{"label": "Old", "flow": "flow-that-was-deleted"}`
- **THEN** the application object MUST still validate and save
- **AND** the dangling reference MUST be discoverable by whatever consumes the binding, rather than silently dropped at write time
