# Spec: agent-knowledge-base

## Purpose

A maker gives a builder agent documents to answer from. The files are attached
to the agent, read into the agent's plan prompt within a size budget, and only
the people who may run the agent can read them.

## ADDED Requirements

### Requirement: A maker attaches knowledge files to an agent (REQ-BQAG-001)

The agent editor SHALL offer a "Knowledge" section where an owner or editor of
the agent's app adds and removes files. Each file SHALL be stored as a file on
the `Agent` object and listed in `Agent.knowledge[]`. Plain text and Markdown
SHALL be accepted; other types SHALL be accepted only once OpenRegister serves
their text. Adding a file that would take the agent past 40,000 characters of
knowledge SHALL be refused with a message that names the room left.

#### Scenario: A maker adds a style guide to an agent

- **GIVEN** a maker editing the agent `Formulierbouwer` of app `permits`
- **WHEN** they add `huisstijl-formulieren.md` under Knowledge and save
- **THEN** the agent lists the file under Knowledge, and the file is stored on the agent object

#### Scenario: Knowledge over the budget is refused

- **GIVEN** an agent with 38,000 characters of knowledge
- **WHEN** the maker adds a 5,000 character file
- **THEN** the dialog refuses it and says 2,000 characters of room are left

### Requirement: An agent's plan reads its knowledge (REQ-BQAG-002)

A plan request issued with an agent id SHALL include the text of the agent's
knowledge files in the prompt, after the agent's instructions and in the order
of `knowledge[]`. The files SHALL be read with the access of the `Agent` object,
and a caller without owner or editor access to the app SHALL get no plan.

#### Scenario: The agent follows the style guide

- **GIVEN** the agent `Formulierbouwer` with `huisstijl-formulieren.md` saying field labels are in sentence case
- **WHEN** a maker asks it in the chat panel to add a contact form
- **THEN** the proposed form fields have sentence case labels, and the run lists `huisstijl-formulieren.md` as knowledge used
