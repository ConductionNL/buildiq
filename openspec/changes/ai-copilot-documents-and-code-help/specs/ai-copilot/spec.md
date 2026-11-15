# Spec: ai-copilot

## Purpose

Adds to the existing `ai-copilot` capability: "Generate with AI" reads a
requirements document the maker picks from their Files, proposes user stories
next to the schemas and pages, and the created app keeps those stories.

## ADDED Requirements

### Requirement: The wizard reads a document from Files (REQ-BQCH-005)

"Generate with AI" SHALL offer "Add a document", which opens the Nextcloud file
picker. The plan request SHALL carry the picked file id. The server SHALL open
the file as the requesting user and SHALL answer 404 when that user cannot read
it. Plain text and Markdown SHALL be accepted. Other types SHALL be accepted only
once OpenRegister serves their text; until then the dialog SHALL say which types
work. A document over 60,000 characters SHALL be refused with a message that
names the limit, and SHALL NOT be cut.

#### Scenario: A maker generates an app from a requirements file

- **GIVEN** a maker with `Eisen tuinvergunning.md` in their Files
- **WHEN** they open "Generate with AI", click "Add a document", pick the file and click Generate
- **THEN** the review shows schemas and pages drawn from the document, and the file name under the brief

#### Scenario: Someone else's file is not read

- **GIVEN** a file id that belongs to another user and is not shared with the maker
- **WHEN** the maker's plan request carries that id
- **THEN** the endpoint answers 404 and no text task is scheduled

### Requirement: The plan carries user stories (REQ-BQCH-006)

A plan SHALL carry `stories[]`, each with a role, a goal, an optional benefit and
`servedBy[]` indexes into the plan's steps. The plan validator SHALL refuse a
story without a role or goal, and a `servedBy` index that does not point at a
schema or page step. The review SHALL list the stories before the schemas, each
with the names of the schemas and pages that serve it.

#### Scenario: The review shows stories first

- **GIVEN** a maker who generated a plan from a requirements document
- **WHEN** the review opens
- **THEN** a "User stories" group comes first, and the story "As an applicant I want to track my permit" shows the page `Mijn aanvragen` under it

### Requirement: The created app keeps its stories (REQ-BQCH-007)

Executing a plan SHALL save its stories on the created `Application` as
`stories[]`. The app detail page SHALL list them with the pages and schemas that
serve each one.

#### Scenario: A colleague reads why a page exists

- **GIVEN** an app created from a plan with three stories
- **WHEN** a colleague opens the app detail page
- **THEN** a stories widget lists the three stories and the pages each one led to
