## Purpose

Defines what an OpenBuild application declares itself to be composed of. An application is not only a scaffold and a name: it binds the data registers, connectors and flows that make it that application, and its agents point back at it. These relationships are what an export reads, so a part an application has no way to express is a part an export cannot carry — and a part expressed TWICE is two facts that can disagree.

## ADDED Requirements

### Requirement: An application MUST be able to bind the FLOWS it is composed of

The `application` schema MUST declare an optional `flows` array. Each entry MUST carry a human `label` and a `flow` identifying one flow.

The binding MUST address the flow by **UUID**, not by numeric id. An id is an auto-increment column and is per-instance: an export carrying one would resolve to a different flow, or to nothing, on the instance that imported it. A UUID is globally unique and travels, provided the importing side seeds the flow with the same UUID rather than minting a new one.

The `Flow` entity carries `uuid`, `name`, `app`, `enabled`, `trigger`, `nodes` and `edges` — and no slug — so a slug binding was never available. `FlowMapper::findByUuid()` is the lookup.

The binding is OPTIONAL and absent MUST mean "this application binds no flows". Every application that exists today declares none, and they MUST all remain valid.

#### Scenario: An application declares the flows it is made of
- **WHEN** an application object is saved with `flows: [{"label": "Hydra sequencer", "flow": "00000000-0000-0000-0000-000000000000"}]`
- **THEN** the object MUST validate against the `application` schema
- **AND** the binding MUST be readable back in the shape it was written

#### Scenario: An application that binds no flows stays valid
- **WHEN** an existing application object with no `flows` property is loaded and re-saved
- **THEN** it MUST validate
- **AND** no `flows` value MUST be invented for it

#### Scenario: A flow is addressed by UUID so it can travel
- **WHEN** a `flows` entry is written
- **THEN** the `flow` value MUST be the flow's UUID
- **AND** a numeric id MUST NOT be accepted in its place, because an id resolves to a different flow on another instance
- **AND** a value that is not UUID-shaped MUST be refused

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

### Requirement: The application schema MUST NOT bind agents, because that edge already exists

An application MUST NOT declare an `agents` array. The `agent` schema already carries `applicationSlug`, and that is how `AgentsController` resolves an application's agents today.

A second edge for the same relationship, pointing the other way, is two facts that can disagree with nothing to arbitrate between them. A consumer that needs an application's agents MUST query agents by `applicationSlug`.

#### Scenario: An app's agents are found without a binding
- **WHEN** a consumer needs the agents belonging to an application
- **THEN** it MUST query `agent` objects whose `applicationSlug` matches the application's slug
- **AND** the `application` schema MUST NOT declare an `agents` property to consult instead

### Requirement: The binding MUST have the same shape as the bindings that already exist

`flows` MUST be an array of objects carrying a `label` and one reference field, matching `dataRegisters` (`{label, register}`) and `connectors`. The UI, the export payload and the reviewer all recognise that shape; a binding that invented its own would be handled by none of them.

`label` matters more here than for a slug-addressed binding, because a UUID is unreadable in a picker.

#### Scenario: The new binding is shaped like dataRegisters
- **WHEN** the `application` schema's `flows` property is compared with `dataRegisters`
- **THEN** both MUST be arrays of objects
- **AND** each MUST carry a `label` plus exactly one reference field

### Requirement: A dangling binding MUST be reportable rather than fatal

A bound UUID that resolves to nothing MUST be a recoverable, reportable state — not a validation failure that makes the application unsaveable.

An application whose flow was deleted is a real and ordinary situation, and the existing precedent already models it: a data register that cannot be resolved is skipped with a log line rather than failing the export.

#### Scenario: A bound flow that no longer exists does not make the app invalid
- **WHEN** an application binds a well-formed UUID that resolves to no flow
- **THEN** the application object MUST still validate and save
- **AND** the dangling reference MUST be discoverable by whatever consumes the binding, rather than silently dropped at write time
