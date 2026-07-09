## ADDED Requirements

### Requirement: Canonical GitHub repo layout for an OpenBuild app

The system SHALL define one canonical GitHub repository layout that represents an
OpenBuild app as a round-trippable **data** definition (not the exporter's PHP
scaffold). A conforming repo SHALL contain, at its root:

- `openbuild-app.json` — the app descriptor (REQ-GARF-002).
- `manifest.json` — the `ApplicationVersion.manifest` blob verbatim (the JSON
  consumed by `CnAppRoot` at runtime).
- `schemas/` — a directory holding one JSON file per companion schema, the file's
  base name equal to the schema's kebab-case slug (`schemas/<slug>.json`).

A conforming repo MAY additionally contain an optional `README.md` and an
optional `img/` directory (screenshots + icon SVGs referenced by the descriptor).
The format SHALL NOT require any PHP, `appinfo/`, or build-tool files — the repo
is a definition only OpenBuild parses, never an installable Nextcloud app.

**ID:** REQ-GARF-001

#### Scenario: A conforming repo carries descriptor, manifest, and schemas

- **WHEN** a repo file map contains `openbuild-app.json` and `manifest.json` at
  the root and one or more `schemas/<slug>.json` files
- **THEN** the parser (REQ-GARF-006) recognises it as a conforming OpenBuild app
  repo and produces an in-memory install payload

#### Scenario: A repo without a root descriptor is not an OpenBuild app repo

- **WHEN** a repo file map has no `openbuild-app.json` at its root
- **THEN** the parser rejects it with the `descriptor_missing` error and produces
  no payload

### Requirement: openbuild-app.json descriptor contract

The `openbuild-app.json` descriptor SHALL declare: `formatVersion` (string,
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

- **WHEN** the parser reads an `openbuild-app.json` carrying `formatVersion`,
  `slug`, `name`, `description`, `category`, `appType: virtual`, and `version`
- **THEN** those fields are mapped onto the in-memory install payload's `slug`,
  `title`, `description`, `category`, and `version`

#### Scenario: A descriptor with an unknown appType is rejected

- **WHEN** the parser reads a descriptor whose `appType` is neither `virtual` nor
  `hybrid`
- **THEN** the parse fails with the `app_type_unknown` error naming the offending
  value

### Requirement: Discovery contract via the openbuild-app topic

The system SHALL define that a GitHub repository is discoverable as an OpenBuild
app **iff** it carries the GitHub **topic `openbuild-app`** AND exposes a
parseable `openbuild-app.json` at its root. The topic is the search key that the
shop (change `github-shop-catalogue`) queries; the root descriptor is the
authoritative parse target. A repo carrying the topic but no parseable descriptor
SHALL be treated as a non-conforming candidate (surfaced as unparseable, not
silently skipped).

**ID:** REQ-GARF-003

#### Scenario: The topic is the declared discovery key

- **WHEN** the format's discovery contract is consulted
- **THEN** it names the GitHub topic `openbuild-app` as the search key AND a root
  `openbuild-app.json` as the parse target

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
files (`openbuild-app.json`, `manifest.json`, one `schemas/<slug>.json` per
companion schema, and an optional `README.md`). The serializer SHALL emit files
in a deterministic order and canonicalise every JSON file (sorted keys, stable
indentation, trailing newline) so that re-serialising an unchanged app yields a
byte-identical file set (stable diffs for the push path). The serializer SHALL
perform no network I/O and no OpenRegister writes.

**ID:** REQ-GARF-006

#### Scenario: Serialize yields the descriptor, manifest, and schema files

- **WHEN** `AppRepoSerializer` serialises an Application with two companion
  schemas at a chosen version
- **THEN** the produced file map contains `openbuild-app.json`, `manifest.json`,
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
  supported by this OpenBuild
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
- **THEN** the emitted `openbuild-app.json` carries a matching `credentials`
  entry with `provider: "github"`, its `reason`, and its `scopes`

#### Scenario: The manifest is authoritative on parse

- **WHEN** the parser reads a repo whose descriptor `credentials` disagrees with
  the manifest's `credentials[]`
- **THEN** the parse succeeds using the manifest's `credentials[]` as
  authoritative
- **AND** the descriptor mismatch does not fail the import
