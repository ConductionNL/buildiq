# Spec: rules-evaluation-for-other-apps

## Purpose

Another app's server asks buildiq's rules engine for a decision with the inputs it has, and gets the outcome without the table and without a user session.

## ADDED Requirements

### Requirement: A rule set names the apps that may call it (REQ-BQRX-001)

A rule set SHALL carry `callableBy`, a list of app ids, empty by default. The rule set editor SHALL let a maker set it. Buildiq SHALL answer `GET /api/rules?callableBy={app}` for a signed-in user with the active rule sets that list that app, projected to slug, title, version, inputs and outputs, without the decision table.

#### Scenario: a maker lets the portal use the parking rule
- **WHEN** a maker adds portaliq to "Mag worden aangeroepen door" on rule set `parkeren-soort-vergunning` and activates it
- **THEN** `GET /api/rules?callableBy=portaliq` lists it with its inputs `woonplaats` and `auto` and its output `soortVergunning`, and no rules

#### Scenario: an existing rule set stays private
- **WHEN** a rule set without `callableBy` is listed for portaliq
- **THEN** it is not returned

### Requirement: Another app evaluates a rule set through a typed command (REQ-BQRX-002)

Buildiq SHALL offer `OCA\Buildiq\Event\RuleEvaluationRequestedEvent` carrying the calling app, a rule set slug and inputs. The listener SHALL evaluate the active version of that rule set when the app is in its `callableBy`, with every action suppressed, and SHALL answer the result, the triggered rule ids and the version. It SHALL refuse with `unknown-rule-set`, `not-allowed`, `invalid-input`, `timeout` or `evaluation-failed`, and SHALL NOT throw to the caller. Each evaluation SHALL write one execution log line naming the calling app and the version.

#### Scenario: the portal decides which permit step comes next
- **WHEN** portaliq dispatches the command for `parkeren-soort-vergunning` with `woonplaats` Zuiddrecht and `auto` true
- **THEN** the answer is `{ ok: true, result: { soortVergunning: "bewoner" } }` with the version, and one execution log line names portaliq
- @e2e exclude backend command without a UI; covered by PHPUnit

#### Scenario: an app the rule set does not list
- **WHEN** an app outside `callableBy` dispatches the command
- **THEN** the refusal is `not-allowed` and nothing is evaluated or logged as an evaluation
- @e2e exclude backend command without a UI; covered by PHPUnit

#### Scenario: a rule set with a notification action
- **WHEN** a rule set that sends a notification when it matches is evaluated through the command
- **THEN** the outcome is answered and no notification is sent
- @e2e exclude backend command without a UI; covered by PHPUnit
