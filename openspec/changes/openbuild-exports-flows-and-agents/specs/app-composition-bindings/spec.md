## ADDED Requirements

### Requirement: A binding MUST be RESOLVED against the OpenRegister `Flow` entity

The head of this chain requires only that a binding be storable and shape-valid. This change adds the consumer, and with it the requirement that resolution happen against the right store.

A consumer resolving a `flows` binding MUST look up the OpenRegister **`Flow` entity** by slug. It MUST NOT resolve against the `agentflow` object store.

The two stores mirror each other and drift. Observed directly: a definition written to the object store left the engine executing the previous graph — the run log showed the old node set while the register UI showed the new one, and nothing reported an error. A consumer that resolved the object would therefore export or seed a graph that is not the one that runs, and every check short of executing it would pass.

#### Scenario: Resolution reads the entity
- **WHEN** a `flows` binding is resolved by any consumer
- **THEN** the definition MUST come from the `Flow` entity
- **AND** the `agentflow` object store MUST NOT be consulted

### Requirement: A dangling binding MUST reach the operator

The head requires that a dangling binding not be fatal and be "discoverable by whatever consumes it". This change names the consumer and therefore names the destination: the export job's RESULT, not only a log line.

An operator reads the finished job. A log line they never open is indistinguishable from a binding that resolved.

#### Scenario: The skip is in the result the operator sees
- **WHEN** a binding resolves to nothing during an export
- **THEN** the skipped binding and its reason MUST appear in the job result
- **AND** the export MUST still succeed
