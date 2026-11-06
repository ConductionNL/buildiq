# Spec: automation-ai-steps

## Purpose

A maker adds an AI step to an automation, or an AI field to a schema, without
drawing a flow. The step classifies, summarises, extracts, translates or answers
a prompt over named fields of a record, and writes the answer back to that
record. Hermiq runs the model and OpenRegister runs the flow.

## ADDED Requirements

### Requirement: The composer offers an Ask AI step (REQ-BQLS-001)

The automation composer SHALL offer an action kind "Ask AI" with the presets
classify, summarise, extract, translate and prompt. The step SHALL name a Hermiq
agent, at least one source field and a target field. The composer SHALL show the
fields the step sends to the model before the maker saves.

#### Scenario: A maker classifies incoming requests

- **GIVEN** a maker composing an automation on schema `melding` with trigger "Object created"
- **WHEN** they add "Ask AI", pick classify, choose source field `omschrijving`, target field `categorie` and labels `afval`, `verlichting`, `overig`, then save
- **THEN** the automation saves, and the step summary reads "Sends omschrijving to the AI"

### Requirement: An AI step compiles to a flow that writes the answer back (REQ-BQLS-002)

Applying an automation with an AI step SHALL create an OpenRegister flow with the
trigger node, `hermiq.agent-step` and `openregister.object-write` in update mode
on the triggering record. The prompt SHALL be built from the preset and SHALL
carry placeholders for the named source fields only.

#### Scenario: A new report gets its category

- **GIVEN** the classify automation from REQ-BQLS-001, applied, and Hermiq installed
- **WHEN** an app user submits a `melding` with omschrijving "Lantaarnpaal kapot bij de school"
- **THEN** the flow run writes `verlichting` to the record's `categorie`, and the run appears in the flow's run log

### Requirement: A classification only lands on a declared label (REQ-BQLS-003)

For the classify preset the compiler SHALL refuse a target field that is not an
enum of exactly the step's labels. An answer outside the labels SHALL fail the
write step and SHALL leave the record unchanged.

#### Scenario: A free text target is refused

- **GIVEN** a classify step whose target field `categorie` is a plain string
- **WHEN** the maker saves the automation
- **THEN** the save fails with "Make categorie a choice list of your labels first."

### Requirement: A schema field can be filled by AI (REQ-BQLS-004)

The schema designer SHALL offer AI fields with the operations translate,
categorise, clean, sentiment, summarise and prompt over named source fields of
the same schema. An AI field SHALL be read-only in forms. Saving it SHALL create
an automation with `origin: ai-field` that fills the field when a record is
created, and removing the field SHALL remove that automation and its flow.

#### Scenario: A maker adds an English summary field

- **GIVEN** a maker in the schema designer of `bezwaar`
- **WHEN** they add an AI field `summary_en` with operation translate, source `toelichting` and language English, then save the schema
- **THEN** `summary_en` shows as read-only in the form, and a new `bezwaar` gets an English summary shortly after it is created

### Requirement: AI steps need the Hermiq node (REQ-BQLS-005)

When OpenRegister's node catalogue does not list `hermiq.agent-step`, the Ask AI
action and the AI field button SHALL be disabled with the reason "Install Hermiq
to use AI steps.", and applying an automation that holds an AI step SHALL fail
without creating a flow.

#### Scenario: No Hermiq, no AI step

- **GIVEN** an instance without Hermiq
- **WHEN** a maker opens the action kind list in the composer
- **THEN** "Ask AI" is disabled and says "Install Hermiq to use AI steps."
