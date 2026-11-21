# Spec: agent-workspace

## Purpose

Adds to the existing `agent-workspace` capability: the Agents page is reachable
from the app it belongs to, and each run keeps the language model steps that led
to its plan, next to the tool calls it already keeps.

## ADDED Requirements

### Requirement: The app detail page leads to its agents (REQ-BQAG-003)

The actions menu of the app detail page SHALL offer "Agents" to the same roles
that see "Automations". Choosing it SHALL open the Agents page with that app
preselected through the `app` query parameter. The Agents page SHALL preselect
the app named in `app` when the caller can see it.

#### Scenario: A maker opens the agents of an app

- **GIVEN** a maker on the detail page of app `permits`
- **WHEN** they open the actions menu and choose "Agents"
- **THEN** the Agents page opens with `permits` selected and its agents listed

### Requirement: A run keeps its model steps (REQ-BQAG-004)

Every `AgentRun` SHALL carry `modelSteps[]`, one per language model call made
for that turn, each with its attempt number, kind (`plan` or `repair`), start
time, duration, status, task type, prompt size, the raw answer capped at 20,000
characters, and the parse or validator message that caused a repair. It SHALL
carry `knowledgeUsed[]` and `durationMs`. When the steps cannot be found, the
run SHALL be saved with empty `modelSteps` and `traceMissing: true`. Steps SHALL
only attach to a run of the same agent and the same user.

#### Scenario: A repaired plan shows both attempts

- **GIVEN** an agent whose first model answer was not valid JSON and whose repair succeeded
- **WHEN** the maker applies the plan
- **THEN** the saved run has two model steps, the first with the parse error as its refusal and the second with status successful

#### Scenario: A lost trace is recorded as lost

- **GIVEN** a plan whose cached trace expired before the maker applied it
- **WHEN** the run is saved
- **THEN** it carries `traceMissing: true` and an empty `modelSteps`, and its tool calls as before

### Requirement: The run history shows the model steps (REQ-BQAG-005)

The run history of an agent SHALL list each run's model steps above its tool
calls, with attempt, duration, status and refusal visible, and the raw answer
behind a disclosure. It SHALL list the knowledge files used. Access SHALL stay
limited to owners and editors of the agent's app.

#### Scenario: A colleague sees why a run took two calls

- **GIVEN** a colleague with editor access to app `permits` and a run with a repair
- **WHEN** they open the agent's run history
- **THEN** they see "Attempt 1, repair needed" with the parse error, then "Attempt 2" with its duration, then the tool calls
