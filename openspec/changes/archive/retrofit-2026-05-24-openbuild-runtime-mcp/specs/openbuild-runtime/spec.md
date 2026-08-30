---
retrofit_extensions:
  - REQ-OBR-MCP-001
  - REQ-OBR-MCP-002
  - REQ-OBR-MCP-003
  - REQ-OBR-MCP-004
---

# openbuild-runtime Specification Delta (Retrofit — MCP surface)

## Requirements

### Requirement: MCP tool-provider contract

The OpenBuild MCP surface SHALL be implemented by a class
(`OCA\OpenBuild\Mcp\OpenBuildToolProvider`) that implements
`OCA\OpenRegister\Mcp\IMcpToolProvider`. The provider SHALL declare its
host Nextcloud app id (`openbuild`), expose a static tool catalogue of
read tools (`openbuild.listApps`, `openbuild.getAppManifest`) and write
tools covering virtual-app lifecycle (`openbuild.createApp`,
`openbuild.promoteVersion`) and draft-version authoring
(`openbuild.upsertSchema`, `openbuild.upsertPage`, `openbuild.addWidget`,
`openbuild.upsertMenuItem`), and SHALL dispatch each invocation by tool
id to the matching internal handler. Unknown tool ids SHALL return a
uniform error envelope of shape
`{ isError: true, error, message }` carrying the machine-readable code
`unknown_tool` and a human-readable message that lists the available
tool ids.

**ID:** REQ-OBR-MCP-001

#### Scenario: Provider reports the OpenBuild app id

- **WHEN** OpenRegister's MCP orchestrator calls `getAppId()` on the
  provider
- **THEN** the provider returns the string `openbuild`

#### Scenario: Catalogue surfaces all OpenBuild tools

- **WHEN** OpenRegister's MCP orchestrator calls `getTools()`
- **THEN** the returned array contains the eight tool descriptors
  (`openbuild.listApps`, `openbuild.getAppManifest`,
  `openbuild.createApp`, `openbuild.promoteVersion`,
  `openbuild.upsertSchema`, `openbuild.upsertPage`,
  `openbuild.addWidget`, `openbuild.upsertMenuItem`), each with an
  `inputSchema` of `type: object`

#### Scenario: Unknown tool id returns a structured error

- **WHEN** OpenRegister's MCP orchestrator calls
  `invokeTool('openbuild.nope', [])`
- **THEN** the response is `{ isError: true, error: 'unknown_tool',
  message: ... }` and `message` lists the available tool ids

### Requirement: Auth-gated dispatch with arg validation

Every MCP tool exposed by this provider SHALL require an authenticated
Nextcloud session. The provider SHALL resolve the active user via
`IUserSession`; if no user is signed in (or the user UID is empty), the
handler SHALL short-circuit with an `{ isError: true, error:
'forbidden', message }` envelope before performing any read or write.
Read-tool argument shape SHALL be validated up-front — `listApps`
SHALL clamp `limit` to the range 1..50 and SHALL reject any
`statusFilter` outside the closed set `{any, draft, published,
archived}` with `{ isError: true, error: 'invalid_arguments' }`. Slug
arguments accepted by the write surface SHALL conform to a shared
pattern (lowercase alphanumeric, hyphen-separated, 2..48 chars,
matching `^[a-z0-9][a-z0-9-]*[a-z0-9]$`). A public `isAdmin($userId)`
helper SHALL delegate to `IGroupManager::isAdmin` so callers can probe
admin posture without re-implementing the check.

**ID:** REQ-OBR-MCP-002

#### Scenario: Unauthenticated caller is rejected

- **WHEN** the MCP orchestrator invokes any OpenBuild tool with no
  active `IUserSession` user
- **THEN** the response is `{ isError: true, error: 'forbidden', ... }`
  and no OpenRegister read/write is attempted

#### Scenario: listApps rejects an out-of-range limit

- **WHEN** an authenticated caller invokes `openbuild.listApps` with
  `limit: 0` (or `limit: 51`)
- **THEN** the response is `{ isError: true, error:
  'invalid_arguments', message: "Invalid limit 0." }`

#### Scenario: listApps rejects an unknown statusFilter

- **WHEN** an authenticated caller invokes `openbuild.listApps` with
  `statusFilter: 'weird'`
- **THEN** the response is `{ isError: true, error:
  'invalid_arguments', message: "Invalid statusFilter 'weird'." }`

#### Scenario: isAdmin reports admin membership

- **WHEN** a caller queries `isAdmin('alice')` and Nextcloud's group
  manager reports Alice in the admin group
- **THEN** the helper returns `true`

#### Note

`isValidSlug` (private) duplicates the slug pattern enforced by the
existing `SlugValidator` service. TODO: collapse onto `SlugValidator`
in a follow-up so the pattern lives in exactly one place.

### Requirement: Application resolution and uniform response mapping

Tools that operate on a single virtual app SHALL resolve the supplied
slug to an `Application` object via the `built-app-route` index in the
`openbuild` register: the provider SHALL call
`ObjectService::searchObjectsBySlug` to locate a matching route, then
`ObjectService::find` to load the Application by its `applicationUuid`.
A missing route SHALL surface as `{ isError: true, error: 'not_found'
}`; a route present without a matching Application (orphaned index
row) SHALL surface as `{ isError: true, error: 'inconsistent_state' }`.
The compact response shape used by `listApps` SHALL include
`{ uuid, slug, name, description, status, version }`. Each MCP
response SHALL carry an OpenBuild `source` descriptor of shape
`{ type: 'openbuild.application', uuid, url, label }` where `url` is
a Nextcloud deep link of the form `/apps/openbuild/builder/{slug}`
(or `/apps/openbuild` when no slug is bound). OR entities, arrays, and
`jsonSerialize`-able objects SHALL all be accepted as input to the
mapping pipeline (`toArray`); UUIDs SHALL be extracted from the
`uuid`, `id`, `@self.uuid`, or `@self.id` fields in that fallback
order (`extractUuid`).

**ID:** REQ-OBR-MCP-003

#### Scenario: Slug resolves to its Application

- **GIVEN** a published virtual app with slug `hello-world` and a
  matching `built-app-route` row pointing at its Application UUID
- **WHEN** a tool resolves the slug via `resolveApplicationBySlug`
- **THEN** the helper returns
  `{ application: { ..., slug: 'hello-world', ... } }`

#### Scenario: Missing route returns not_found

- **WHEN** a tool resolves a slug for which no `built-app-route` row
  exists
- **THEN** the helper returns `{ error: 'not_found', message: ... }`

#### Scenario: Route without Application returns inconsistent_state

- **GIVEN** a `built-app-route` row whose `applicationUuid` points at
  an Application that has been deleted
- **WHEN** a tool resolves the slug
- **THEN** the helper returns `{ error: 'inconsistent_state', message:
  ... }`

#### Scenario: Deep link uses /apps/openbuild/builder/{slug}

- **WHEN** the provider calls `buildDeepLink('hello-world')`
- **THEN** the returned URL is `/apps/openbuild/builder/hello-world`

#### Scenario: UUID extraction falls back through @self

- **GIVEN** an OR object array of shape
  `{ '@self': { uuid: 'abc-123' } }` (no top-level `uuid` or `id`)
- **WHEN** `extractUuid` is called
- **THEN** the returned UUID is `'abc-123'`

### Requirement: Draft-version manifest mutation isolation

Authoring tools that mutate a virtual app
(`openbuild.upsertSchema`, `openbuild.upsertPage`,
`openbuild.addWidget`, `openbuild.upsertMenuItem`) SHALL default the
`versionSlug` argument to `development` so a misfired tool call cannot
mutate a production version. A version row SHALL be located via
`loadVersion(objectService, appSlug, versionSlug)`, which SHALL look
up the row in the `application-version` schema under
`{appSlug}-{versionSlug}` slug composition; missing rows SHALL surface
as `{ error: 'not_found' }` so the orchestrator can return a
structured error envelope. Manifest writes SHALL be performed
exclusively through `saveVersionManifest`, which SHALL deep-merge the
mutated manifest blob back onto the located version row and persist it
via `ObjectService::saveObject`; partial writes that bypass this
helper SHALL be considered a violation of this requirement.

**ID:** REQ-OBR-MCP-004

#### Scenario: Authoring tools default versionSlug to development

- **WHEN** a caller invokes `openbuild.upsertPage` with `appSlug:
  hello-world` and omits `versionSlug`
- **THEN** the mutation targets the `hello-world-development` version
  row, not any production version

#### Scenario: Unknown version returns not_found

- **WHEN** an authoring tool resolves `loadVersion(_, 'hello-world',
  'staging')` and no `application-version` row exists with slug
  `hello-world-staging`
- **THEN** the helper returns `{ error: 'not_found', message: ... }`
  and the calling tool surfaces an MCP error envelope

#### Scenario: Manifest persistence routes through saveVersionManifest

- **WHEN** an authoring tool persists a mutated manifest
- **THEN** the persistence path is `saveVersionManifest(...)` and the
  underlying `ObjectService::saveObject` call carries the merged
  manifest on the located version row
