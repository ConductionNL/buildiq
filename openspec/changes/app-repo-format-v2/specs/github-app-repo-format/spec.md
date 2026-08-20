# GitHub App Repo Format Specification

**Status**: in-progress
**Scope**: openbuild
**OpenSpec changes**:
- github-app-repo-format
- app-repo-format-v2

## Purpose

Defines the on-disk layout of a published OpenBuild application repository and the rules for reading it back. v1 carried the descriptor, the manifest, the app's own companion schemas and a README. v2 additionally carries the shared data registers an app binds to, the OpenConnector configuration it declares, its automations and its skills — the difference between an artefact that describes an app and one that can reconstitute it.

## ADDED Requirements

### Requirement: A published repository carries the app's whole configuration
The system MUST emit, in addition to the v1 files, one entry per bound data register, per declared connector, per automation and per skill. The system MUST stamp `formatVersion` `2.0` and MUST record per-channel entry counts in the descriptor.

#### Scenario: An app bound to a shared register publishes that register's definition
- GIVEN an application whose `dataRegisters` binds `spectr-live`
- WHEN it is serialised
- THEN the repository MUST contain `data-registers/spectr-live.json`
- AND that file MUST carry the register's schema definitions
- AND it MUST NOT carry register objects

#### Scenario: Channel counts are recorded so an empty export is visible
- GIVEN an application with no companion schemas of its own
- WHEN it is serialised
- THEN the descriptor MUST record `schemas: 0`
- AND the publish MUST NOT present itself as complete when every channel is empty

#### Scenario: A v1 repository still parses
- GIVEN a repository stamped `formatVersion` `1.0` containing only the four v1 file kinds
- WHEN it is parsed
- THEN parsing MUST succeed
- AND the result MUST be identical to what the v1 parser produced

### Requirement: Connectors are declared explicitly, never inferred
The system MUST select connectors from the application's own `connectors[]` binding. The system MUST NOT infer an app's connectors from which objects happen to target a register it binds. The system MAY additionally resolve the objects a declared entry directly references, to ONE level only, and MUST record declared and resolved counts separately.

#### Scenario: Only declared connectors are exported
- GIVEN an instance where several applications bind the same shared register
- AND an application declaring exactly two connectors
- WHEN it is serialised
- THEN exactly those two entries MUST be exported as declared
- AND another application's connectors MUST NOT appear

#### Scenario: A declared synchronization brings its source
- GIVEN a declared `synchronization` referencing a source and a mapping
- WHEN it is serialised
- THEN that source and mapping MUST be exported alongside it
- AND the descriptor MUST report them as resolved rather than declared
- AND resolution MUST NOT continue beyond one level

### Requirement: Credential values never leave the instance
The system MUST emit connector configuration with credential references only. The system MUST strip any inline secret-bearing value before it reaches the repository, and MUST record that a strip occurred.

#### Scenario: An inline secret is stripped and reported
- GIVEN a declared source whose configuration carries an inline password
- WHEN it is serialised
- THEN the emitted file MUST NOT contain that value
- AND the descriptor MUST record that a strip occurred for that entry

#### Scenario: A credential reference survives
- GIVEN a source authenticating through `configuration.authentication.credentialRef`
- WHEN it is serialised
- THEN the reference MUST be preserved
- AND no credential value MUST be resolved or emitted

### Requirement: Every channel path is validated before use
The system MUST validate each slug and kind against its pattern before using it as a path component, on BOTH emit and parse. The system MUST reject an entry whose path would escape its channel prefix rather than rewriting it.

#### Scenario: A crafted slug cannot escape its channel
- GIVEN a connector binding whose slug is `../../etc`
- WHEN the app is serialised
- THEN that entry MUST be rejected
- AND no file MUST be written outside `connectors/`

#### Scenario: The parser does not trust the repository
- GIVEN a repository containing `connectors/source/../../evil.json`
- WHEN it is parsed
- THEN that entry MUST be ignored
- AND parsing MUST NOT fail the whole repository on account of it

## Non-Functional Requirements

- **Performance:** Serialisation MUST remain a pure in-memory transformation apart from the object reads it needs; no per-entry network call.
- **Accessibility:** No UI surface in this change — no WCAG impact.
- **Internationalization:** Any new user-facing string MUST be available in Dutch and English (ADR-005).

## Acceptance Criteria

- A v2 repository carries data-registers, connectors, automations and skills alongside the v1 files
- A v1 repository parses unchanged
- Connectors come from the explicit binding; another app's connectors never appear
- One-level dependency resolution, reported separately from declared entries
- No credential value is ever emitted; strips are recorded
- Crafted slugs are rejected on both emit and parse

## Notes

- `skills/<name>/…` is byte-for-byte hermiq's `SkillBundleSerializer` layout — one shape, two apps.
- OpenConnector is read as OpenRegister objects (it has no `lib/Db` and no `openconnector_*` tables), so this introduces no cross-app PHP dependency, per ADR-022.
- Collectors are total, mirroring `collectCompanionSchemas()`: a missing source yields no entries rather than an exception. The descriptor's channel counts are what stop that becoming a silent empty export.
