# GitHub App Repo Format Specification (flow/agent delta)

**Status**: in-progress
**Scope**: openbuild
**OpenSpec changes**:
- github-app-repo-format
- app-repo-format-v2
- app-repo-format-flow-agent-export

## Purpose

Extends the v2 repository format (`data-registers/`, `connectors/`,
`automations/`, `skills/`) with the two channels the format had no room for
until now: the application's bound OpenRegister flows and the agents that
point at it. Reuses `FlowAndAgentExportBundler` — the openbuild-exporter's
existing, tested reader of this exact question — rather than adding a second
answer to it.

## ADDED Requirements

### Requirement: A published repository carries the app's bound flows and agents
The system MUST emit, in addition to the existing v2 channels, one entry per
resolvable flow binding and one entry per agent pointing at the application.
The system MUST record `flows` (`declared`/`exported`/`skipped`) and `agents`
counts in the descriptor's `channels` block. The system MUST resolve flows
against the OpenRegister `Flow` ENTITY, never a mirror, and MUST resolve
agents by `applicationSlug`, never a second binding.

#### Scenario: A bound flow is exported at its channel path
- GIVEN an application whose `flows[]` binds a UUID that resolves to a `Flow` entity
- WHEN it is serialised
- THEN the repository MUST contain `flows/<uuid>.json`
- AND that file MUST carry the flow's uuid, name, nodes and edges
- AND the descriptor MUST record it under `channels.flows.exported`

#### Scenario: An agent pointing at the application is exported at its channel path
- GIVEN an agent whose `applicationSlug` matches the application
- WHEN the application is serialised
- THEN the repository MUST contain `agents/<uuid>.json`
- AND that file MUST NOT carry the OpenRegister `@self` envelope
- AND the descriptor MUST record it under `channels.agents`

#### Scenario: A dangling flow binding is reported, not silently dropped
- GIVEN an application whose `flows[]` binds a UUID that resolves to no `Flow` entity
- WHEN it is serialised
- THEN no `flows/<uuid>.json` file is written for that binding
- AND the descriptor's `channels.flows.skipped` count MUST include it

#### Scenario: The flows and agents channels degrade to empty without a collector
- GIVEN a serializer constructed without a flow/agent collector (the v1 construction shape)
- WHEN it serialises an application whose `flows[]` binds a UUID
- THEN no `flows/` or `agents/` file is written
- AND the descriptor MUST still record `channels.flows.exported` as `0`
- AND serialisation MUST NOT throw

### Requirement: Flow and agent channel entries are UUID-keyed, never slug-keyed
Unlike every other v2 channel, the flows and agents channels MUST be keyed
by UUID rather than slug — `Flow` and `agent` objects carry no slug the way a
data register, connector or automation does. The parser MUST re-validate
every flow/agent filename against the UUID pattern before use, on the same
"every channel path is validated before use" posture as the other channels.

#### Scenario: A non-UUID flow filename is ignored on parse
- GIVEN a repository containing `flows/not-a-uuid.json`
- WHEN it is parsed
- THEN that entry MUST be ignored
- AND parsing MUST NOT fail the whole repository on account of it

#### Scenario: A traversal attempt in the agents channel is ignored on parse
- GIVEN a repository containing `agents/../../evil.json`
- WHEN it is parsed
- THEN that entry MUST be ignored
- AND no file outside `agents/` MUST be treated as an agent

## Non-Functional Requirements

- **Performance:** Collecting flows/agents remains bounded to
  `MAX_CHANNEL_ENTRIES` bindings per application, mirroring every other
  channel's cap.
- **Accessibility:** No UI surface in this change — no WCAG impact.
- **Internationalization:** No new user-facing string.

## Acceptance Criteria

- A v2 repository carries `flows/` and `agents/` entries alongside the four
  existing channels
- Flows are resolved against the `Flow` entity, never the `agentflow` mirror
- A dangling flow binding is counted as skipped, not silently omitted
- Both channels are UUID-keyed and path-validated on both emit and parse
- Absent a flow/agent collector, both channels degrade to empty without error

## Notes

- Export reuses `FlowAndAgentExportBundler` (openbuild-exporter, PR #233)
  unmodified, via `FlowAgentChannelCollector`'s scratch-directory adapter —
  see design.md Decision 1.
