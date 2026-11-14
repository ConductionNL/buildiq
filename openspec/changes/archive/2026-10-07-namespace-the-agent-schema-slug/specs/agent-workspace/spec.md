# Agent workspace

## ADDED Requirements

### Requirement: The agent workspace schema is namespaced (REQ-AW-020)

This app's agent schema slug SHALL be `buildAgent` and SHALL NOT be `agent`.

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so a bare `agent` resolved to hermiq's AI agent or to this app's
workspace pointer depending on which row was reached first.

The lookup for hermiq's own agent SHALL keep using `agent`, because that is
hermiq's slug and it does not move.

A repair step SHALL rename the row IN PLACE before the register import. The
import matches an existing schema by `(application, slug)` and CREATES a new one
when that misses, so a slug change in the shipped fragment alone creates a
second schema and orphans every object on the first, without erroring.

The rename SHALL be scoped to this app's own rows, matching BOTH `buildiq` and
`openbuild` as the owning application. Without the application filter it would
rename hermiq's row, which is the damage it exists to prevent; without the old
spelling it would silently do nothing on an install that has not yet migrated
its application id, which is the install that still needs it.

It SHALL refuse when both slugs exist and when the old slug is duplicated.

#### Scenario: The slug is renamed in place

- **GIVEN** an install carrying a buildiq-owned `agent` schema
- **WHEN** the repair step runs
- **THEN** the row keeps its schema id, and so its shard table and objects.

#### Scenario: hermiq's row is not touched

- **WHEN** the step looks for rows to rename
- **THEN** the query is filtered on this app's application ids.

#### Scenario: An ambiguous install is refused

- **GIVEN** both `agent` and `buildAgent` exist under this app
- **WHEN** the step runs
- **THEN** it warns and renames neither.
