## ADDED Requirements

### Requirement: The assistant response is delivered incrementally

The chat companion SHALL render assistant text as it arrives rather than
only on completion. The orchestrator SHALL emit token events for a
response while the underlying provider call is still in flight, and the
companion SHALL append each to the assistant bubble as it is received.

A provider that cannot stream SHALL still produce a correct response: the
non-streaming path remains the fallback, and the Thinking indicator
continues to cover it.

#### Scenario: Partial text appears before the call completes

- **WHEN** a message is submitted that produces a multi-paragraph answer
- **THEN** the assistant bubble contains a non-empty, strictly shorter
  prefix of the final text at some point before the provider call
  completes
- **AND** the text only ever grows — no already-rendered token is
  replaced or reordered

#### Scenario: A non-streaming provider still answers

- **WHEN** the configured provider does not expose `generateStreamOfText`
- **THEN** the response is delivered whole on completion
- **AND** the Thinking indicator remains visible for the duration, then
  clears

### Requirement: A long-running call is distinguishable from a hung one

The orchestrator SHALL emit a heartbeat while a call is outstanding, at
an interval short enough that a healthy slow response is never mistaken
for a stalled one.

#### Scenario: A long call surfaces at least one heartbeat

- **WHEN** a prompt is submitted whose response takes longer than the
  heartbeat interval
- **THEN** at least one heartbeat event reaches the frontend before the
  response completes
- **AND** the Thinking indicator stays visible until the first token
  arrives
