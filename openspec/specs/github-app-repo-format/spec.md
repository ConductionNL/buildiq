# GitHub App Repo Format Specification

**Status**: in-progress
**Scope**: buildiq
**OpenSpec changes**:
- [github-app-repo-format](../../changes/github-app-repo-format/)
- [app-repo-format-v2](../../changes/app-repo-format-v2/)
- [app-repo-format-flow-agent-export](../../changes/archive/2026-08-19-app-repo-format-flow-agent-export/) _(archived 2026-08-19)_

## Purpose

Defines the on-disk layout of a published Buildiq application repository and
the rules for reading it back. v1 carried the descriptor, the manifest, the
app's own companion schemas and a README. v2 additionally carries the shared
data registers an app binds to, the OpenConnector configuration it declares,
its automations and its skills — the difference between an artefact that
describes an app and one that can reconstitute it. The v2 channel set was
later extended once more to carry the application's bound OpenRegister flows
and the agents that point at it, reusing `FlowAndAgentExportBundler` — the
buildiq-exporter's existing, tested reader of this exact question — rather
than adding a second answer to it.

## Requirements

### Requirement: Canonical GitHub repo layout for an Buildiq app

The system SHALL define one canonical GitHub repository layout that represents an
Buildiq app as a round-trippable **data** definition (not the exporter's PHP
scaffold). A conforming repo SHALL contain, at its root:

- `buildiq-app.json` — the app descriptor (REQ-GARF-002).
- `manifest.json` — the `ApplicationVersion.manifest` blob verbatim (the JSON
  consumed by `CnAppRoot` at runtime).
- `schemas/` — a directory holding one JSON file per companion schema, the file's
  base name equal to the schema's kebab-case slug (`schemas/<slug>.json`).

A conforming repo MAY additionally contain an optional `README.md` and an
optional `img/` directory (screenshots + icon SVGs referenced by the descriptor).
The format SHALL NOT require any PHP, `appinfo/`, or build-tool files — the repo
is a definition only Buildiq parses, never an installable Nextcloud app.

**ID:** REQ-GARF-001

#### Scenario: A conforming repo carries descriptor, manifest, and schemas

- **WHEN** a repo file map contains `buildiq-app.json` and `manifest.json` at
  the root and one or more `schemas/<slug>.json` files
- **THEN** the parser (REQ-GARF-006) recognises it as a conforming Buildiq app
  repo and produces an in-memory install payload

#### Scenario: A repo without a root descriptor is not an Buildiq app repo

- **WHEN** a repo file map has no `buildiq-app.json` at its root
- **THEN** the parser rejects it with the `descriptor_missing` error and produces
  no payload

### Requirement: buildiq-app.json descriptor contract

The `buildiq-app.json` descriptor SHALL declare: `formatVersion` (string,
required — the layout version, initially `"1.0"`), `slug` (string, required,
kebab-case), `name` (string, required), `description` (string, required),
`category` (string, required), `appType` (string enum `virtual | hybrid`,
required), and `version` (string, required — the exported `ApplicationVersion`
semver). It MAY additionally declare `icon` / `iconDark` (each `{ "ref": "<path>" }`
pointing at an SVG under `img/`), `useCase` (string), `baseRef`
(`{ kind, id, manifestVersion? }`, present for `appType: hybrid`), and
`credentials` (an array of `{ provider, reason, scopes[] }` entries — see
REQ-GARF-009). The descriptor SHALL NOT embed the manifest blob or companion
schema blobs (those live in `manifest.json` / `schemas/*.json`).

**ID:** REQ-GARF-002

#### Scenario: A valid descriptor exposes the identity and version fields

- **WHEN** the parser reads an `buildiq-app.json` carrying `formatVersion`,
  `slug`, `name`, `description`, `category`, `appType: virtual`, and `version`
- **THEN** those fields are mapped onto the in-memory install payload's `slug`,
  `title`, `description`, `category`, and `version`

#### Scenario: A descriptor with an unknown appType is rejected

- **WHEN** the parser reads a descriptor whose `appType` is neither `virtual` nor
  `hybrid`
- **THEN** the parse fails with the `app_type_unknown` error naming the offending
  value

### Requirement: Discovery contract via the buildiq-app topic

The system SHALL define that a GitHub repository is discoverable as an Buildiq
app **iff** it carries the GitHub **topic `buildiq-app`** AND exposes a
parseable `buildiq-app.json` at its root. The topic is the search key that the
shop (change `github-shop-catalogue`) queries; the root descriptor is the
authoritative parse target. A repo carrying the topic but no parseable descriptor
SHALL be treated as a non-conforming candidate (surfaced as unparseable, not
silently skipped).

**ID:** REQ-GARF-003

#### Scenario: The topic is the declared discovery key

- **WHEN** the format's discovery contract is consulted
- **THEN** it names the GitHub topic `buildiq-app` as the search key AND a root
  `buildiq-app.json` as the parse target

### Requirement: manifest.json carries the ApplicationVersion manifest blob

The repo's `manifest.json` SHALL contain the exported `ApplicationVersion.manifest`
blob verbatim, valid against the canonical app-manifest schema
(`@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`, v2.14.0+). For
a `virtual` app it is the full manifest; for a `hybrid` app it is the delta
manifest (the descriptor's `baseRef` names the base). The serializer SHALL write
it verbatim from the chosen version; the parser SHALL validate it with the same
manifest validation the local clone path applies before use.

**ID:** REQ-GARF-004

#### Scenario: The manifest blob round-trips verbatim

- **WHEN** the serializer emits a repo for an ApplicationVersion whose `manifest`
  is M
- **THEN** the emitted `manifest.json` parses back to a blob byte-equal (after
  canonicalisation) to M

#### Scenario: An invalid manifest is rejected on parse

- **WHEN** the parser reads a `manifest.json` that fails app-manifest schema
  validation
- **THEN** the parse fails with the `manifest_invalid` error naming the failing
  manifest property path
- **AND** no install payload is produced

### Requirement: companion schemas are one JSON file per schema

The repo's `schemas/` directory SHALL hold one JSON file per companion schema,
each file being a single OpenRegister JSON-schema object and its base filename
equal to the schema's kebab-case slug. The serializer SHALL emit one file per
companion schema of the app's per-app register (filename = slug); the parser SHALL
collect them into the install payload's `companionSchemas` array. Two schema
files resolving to the same slug SHALL be rejected (REQ-GARF-008,
`schema_slug_duplicate`).

**ID:** REQ-GARF-005

#### Scenario: Companion schemas are collected into the payload

- **WHEN** the parser reads a repo with `schemas/permit.json` and
  `schemas/inspection.json`
- **THEN** the install payload's `companionSchemas` array contains both parsed
  schema objects

#### Scenario: A schema file with a non-slug base name is rejected

- **WHEN** the parser reads a `schemas/` entry whose base filename is not a valid
  kebab-case slug (e.g. a path-traversal or spaced name)
- **THEN** the parse fails with the `schema_invalid` error naming the file

### Requirement: AppRepoSerializer produces the repo file set

The system SHALL provide an `AppRepoSerializer` service that, given a local
`Application` and a chosen `ApplicationVersion`, produces the ordered set of repo
files (`buildiq-app.json`, `manifest.json`, one `schemas/<slug>.json` per
companion schema, and an optional `README.md`). The serializer SHALL emit files
in a deterministic order and canonicalise every JSON file (sorted keys, stable
indentation, trailing newline) so that re-serialising an unchanged app yields a
byte-identical file set (stable diffs for the push path). The serializer SHALL
perform no network I/O and no OpenRegister writes.

**ID:** REQ-GARF-006

#### Scenario: Serialize yields the descriptor, manifest, and schema files

- **WHEN** `AppRepoSerializer` serialises an Application with two companion
  schemas at a chosen version
- **THEN** the produced file map contains `buildiq-app.json`, `manifest.json`,
  and exactly two `schemas/<slug>.json` files

#### Scenario: Re-serialising an unchanged app is byte-stable

- **WHEN** `AppRepoSerializer` serialises the same Application + version twice
  with no intervening change
- **THEN** the two produced file maps are byte-identical

### Requirement: AppRepoParser maps a repo file set onto the clone seam

The system SHALL provide an `AppRepoParser` service whose `parse(array $files)`
takes an in-memory map of `path => contents` and returns an
`ApplicationTemplate`-shaped array (`slug`, `title`, `description`, `useCase`,
`category`, `version`, `manifest`, `companionSchemas`, `templateOrigin`) suitable
for `ApplicationsController::installFromTemplateArray`. The parser SHALL perform
no network I/O and no OpenRegister writes — it is a pure function of the file map
— and SHALL NOT re-implement companion-schema namespacing or manifest rewriting
(that reuse lives in the clone seam it feeds).

**ID:** REQ-GARF-007

#### Scenario: Parse output matches the clone-seam input shape

- **WHEN** `AppRepoParser::parse` runs on a conforming repo file map
- **THEN** it returns an array carrying `slug`, `title`, `description`,
  `category`, `version`, `manifest`, and `companionSchemas`
- **AND** the array is accepted by `installFromTemplateArray` without further
  reshaping

#### Scenario: templateOrigin records the GitHub source

- **WHEN** `AppRepoParser::parse` runs on a repo fetched from GitHub
- **THEN** the returned array's `templateOrigin` records `source: "github"`, the
  repo identity, and the descriptor `version`

### Requirement: Strict, all-or-nothing, actionable import validation

The parser SHALL fail the **entire** parse — importing nothing partially — when
any conforming-repo invariant is violated, and SHALL surface a structured,
actionable error carrying a stable error code and the offending file path(s). The
recognised failures and codes SHALL include at least: `descriptor_missing`,
`descriptor_unparseable`, `format_version_unsupported`, `app_type_unknown`,
`manifest_missing`, `manifest_unparseable`, `manifest_invalid`,
`schema_unparseable`, `schema_invalid`, and `schema_slug_duplicate`. The parser
SHALL NOT best-effort-skip a malformed file (the
`manifest-validation-discards-backend-delta` failure mode is explicitly
forbidden). The error codes and file paths carry no secret and no PII and SHALL
be safe to return to the caller (per ADR-005) so the user can locate and fix the
bad file.

**ID:** REQ-GARF-008

#### Scenario: One malformed schema file fails the whole parse

- **WHEN** the parser reads a repo whose `manifest.json` is valid but one
  `schemas/*.json` file is not valid JSON
- **THEN** the parse fails with the `schema_unparseable` error naming that file
- **AND** no install payload is produced (nothing is imported partially)

#### Scenario: An unsupported format version is rejected loudly

- **WHEN** the parser reads a descriptor whose `formatVersion` major is not
  supported by this Buildiq
- **THEN** the parse fails with the `format_version_unsupported` error naming the
  version

#### Scenario: Duplicate schema slugs are rejected

- **WHEN** the parser reads a repo where two `schemas/*.json` files resolve to the
  same schema slug
- **THEN** the parse fails with the `schema_slug_duplicate` error naming the
  colliding files

### Requirement: Credentials declaration in the descriptor

The system SHALL support an optional `credentials` array in the descriptor, each
entry of shape `{ provider, reason, scopes[] }`, mirroring the manifest's
top-level `credentials[]` field (app-manifest v2.14.0). The serializer SHALL derive the
descriptor `credentials` from the manifest's `credentials[]` when present; on
parse the manifest remains the authoritative source and the descriptor
`credentials` is a surfaced convenience copy (so a shop browser can see an app's
credential needs without parsing the whole manifest). A descriptor and manifest
that disagree SHALL NOT fail the parse — the manifest wins.

**ID:** REQ-GARF-009

#### Scenario: A declared credential is surfaced in the descriptor

- **WHEN** the serializer serialises an app whose manifest declares a top-level
  `credentials[]` entry for the `github` provider
- **THEN** the emitted `buildiq-app.json` carries a matching `credentials`
  entry with `provider: "github"`, its `reason`, and its `scopes`

#### Scenario: The manifest is authoritative on parse

- **WHEN** the parser reads a repo whose descriptor `credentials` disagrees with
  the manifest's `credentials[]`
- **THEN** the parse succeeds using the manifest's `credentials[]` as
  authoritative
- **AND** the descriptor mismatch does not fail the import

### Requirement: A published repository carries the app's whole configuration (v2)
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
The system MUST validate each slug and kind against its pattern before using it as a path component, on BOTH emit and parse. The system MUST reject an entry whose path would escape its channel prefix rather than rewriting it. Flow and agent channel entries are additionally UUID-keyed rather than slug-keyed (`Flow` and `agent` objects carry no slug the way a data register, connector or automation does); the parser re-validates every flow/agent filename against the UUID pattern before use, on the same posture.

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

### Requirement: A published repository carries the app's bound flows and agents
The system MUST emit, in addition to the v2 channels above, one entry per
resolvable flow binding and one entry per agent pointing at the application.
The system MUST record `flows` (`declared`/`exported`/`skipped`) and `agents`
counts in the descriptor's `channels` block. The system MUST resolve flows
against the OpenRegister `Flow` ENTITY, never a mirror, and MUST resolve
agents by `applicationSlug`, never a second binding. Export reuses
`FlowAndAgentExportBundler` (buildiq-exporter, PR #233) unmodified, via
`FlowAgentChannelCollector`'s scratch-directory adapter.

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

## Non-Functional Requirements

- **Performance:** Serialisation MUST remain a pure in-memory transformation apart from the object reads it needs; no per-entry network call. Collecting flows/agents remains bounded to `MAX_CHANNEL_ENTRIES` bindings per application, mirroring every other channel's cap.
- **Accessibility:** No UI surface in this change — no WCAG impact.
- **Internationalization:** Any new user-facing string MUST be available in Dutch and English (ADR-005). No new user-facing string was introduced by the flows/agents channels.

## Acceptance Criteria

- A v2 repository carries data-registers, connectors, automations, skills, flows and agents alongside the v1 files
- A v1 repository parses unchanged
- Connectors come from the explicit binding; another app's connectors never appear
- One-level dependency resolution, reported separately from declared entries
- No credential value is ever emitted; strips are recorded
- Crafted slugs are rejected on both emit and parse
- Flows are resolved against the `Flow` entity, never the `agentflow` mirror
- A dangling flow binding is counted as skipped, not silently omitted
- Both the flows and agents channels are UUID-keyed and path-validated on both emit and parse
- Absent a flow/agent collector, both channels degrade to empty without error

## Notes

- `skills/<name>/…` is byte-for-byte hermiq's `SkillBundleSerializer` layout — one shape, two apps.
- OpenConnector is read as OpenRegister objects (it has no `lib/Db` and no `openconnector_*` tables), so this introduces no cross-app PHP dependency, per ADR-022.
- Collectors are total, mirroring `collectCompanionSchemas()`: a missing source yields no entries rather than an exception. The descriptor's channel counts are what stop that becoming a silent empty export.
- `FlowAndAgentExportBundler.php` and `ExportService.php` (buildiq-exporter) are untouched by the flows/agents channels — reuse is by calling, not by extraction or duplication.
- Fetching a published repository's files (`GitHubCatalogService::fetchChannelFiles()`) must independently enumerate every channel prefix the parser understands — the parser being able to read a channel does not mean the fetch step downloads it. Verified live twice: once for data-registers/connectors/automations/skills (this class's own docblock), and once more for flows/agents during app-repo-format-flow-agent-export's live round-trip verification (fixed 2026-08-19).
