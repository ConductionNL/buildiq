## ADDED Requirements

### Requirement: REQ-OBEX-001 ExportJob schema declaration

The system SHALL declare an `ExportJob` schema in
`lib/Settings/openbuilt_register.json` (OpenAPI 3.0.0) carrying the
properties `uuid`, `applicationUuid` (UUID-format, required),
`applicationVersion` (semver-pattern, required), `target`
(enum `zip|github`, required), `status` (enum
`queued|running|succeeded|failed`, default `queued`, required),
`githubOrg` (string, optional), `githubRepo` (string, optional),
`githubVisibility` (enum `public|private`, optional),
`includeSeedData` (boolean, default `false`), `downloadUrl` (string,
optional), `downloadExpiresAt` (date-time, optional),
`errorMessage` (string, optional), `log` (array of strings, optional,
append-only progress notes). The schema SHALL declare
`x-openregister-lifecycle` with the
`queued → running → succeeded|failed` state machine (no terminal
re-entry; `failed → queued` is permitted only via explicit retry by
creating a new ExportJob).

No PHP `ExportJobStateMachine` or `ExportJobLifecycleService` class SHALL
be created — the lifecycle is handled entirely by OR's lifecycle engine.

#### Scenario: Schema validates a well-formed ExportJob object

- **GIVEN** an integrator supplies a valid `applicationUuid`, `applicationVersion: "1.0.0"`, and `target: "zip"`
- **WHEN** the integrator POSTs the ExportJob to the OR REST endpoint
- **THEN** OR creates the object with `status: "queued"` and a fresh `uuid`
- **AND** the OR audit trail records the creation event

#### Scenario: Schema rejects an invalid target

- **GIVEN** an integrator supplies `target: "ftp"`
- **WHEN** the integrator POSTs the ExportJob to OR REST
- **THEN** OR returns a 4xx validation error referencing the enum constraint on `target`
- **AND** no ExportJob is created

#### Scenario: Disallowed lifecycle transition rejected

- **GIVEN** an ExportJob with `status: "succeeded"`
- **WHEN** the system attempts to transition the ExportJob to `running`
- **THEN** the OR lifecycle engine rejects the transition with a 4xx error
- **AND** the audit trail records the attempted transition

---

### Requirement: REQ-OBEX-002 Export targets a specific published Application version

The export pipeline SHALL operate on a **specific published version** of an
Application — never on an in-flight draft. The frontend dialog SHALL default the
version field to the Application's current `productionVersion` semver (per
ADR-002 / `openbuilt-versioning`). The system SHALL reject an export request whose
`applicationVersion` does not match any known published version of the referenced
Application, and SHALL reject a version that resolves to a `draft` snapshot.

#### Scenario: Default version is the current published version

- **GIVEN** an Application whose current `productionVersion` carries `semver: "1.2.0"`
- **WHEN** the user opens the Export dialog for that Application
- **THEN** the dialog's version field is pre-filled with `1.2.0`

#### Scenario: Reject export of an unknown version

- **GIVEN** an Application with no published version matching `"9.9.9"`
- **WHEN** the user submits an export with `applicationVersion: "9.9.9"`
- **THEN** the controller returns 422 with an error message naming the unknown version
- **AND** no ExportJob is created

#### Scenario: Reject export of a draft

- **GIVEN** an `applicationVersion` that resolves to a `draft` (not `published`) snapshot
- **WHEN** the user submits an export with that version
- **THEN** the controller returns 422 with an error message indicating drafts cannot be exported
- **AND** no ExportJob is created

---

### Requirement: REQ-OBEX-003 Exported tree shape conforms to the nextcloud-app-template baseline

The exported archive SHALL contain a directory tree matching the snapshot of
`nextcloud-app-template` embedded under `lib/Resources/template/`, with every
placeholder (`{{appId}}`, `{{appNamespace}}`, `{{appName}}`,
`{{appDescription}}`, `{{appVersion}}`, `{{authorName}}`,
`{{authorEmail}}`, `{{license}}`) replaced by values derived from the source
Application's manifest and ExportJob inputs. The tree SHALL include at minimum:

- `appinfo/info.xml` carrying the new id, namespace, version, navigation entry, and
  dependencies declared by the source manifest.
- `lib/AppInfo/Application.php` with the new namespace.
- `lib/Settings/<newapp>_register.json` carrying the companion schemas referenced by
  the manifest, relocated to the new namespace.
- `lib/Repair/InitializeSettings.php` invoking
  `ConfigurationService::importFromApp()` against the new register.
- `src/manifest.json` — the source Application's manifest blob, with its `version`
  field set to the exported `applicationVersion`.
- `src/main.js` mounting `<CnAppRoot>` via
  `useAppManifest('<newapp>', bundledManifest)` (Tier-4 pattern).
- `src/App.vue` shell.
- `package.json` with deps (Vue 2.7, `@conduction/nextcloud-vue`, `@nextcloud/vue`,
  build tooling) carried over from the snapshot.
- `composer.json` with PHP deps and the Conduction PHPCS / PHPMD / Psalm / PHPStan /
  PHPUnit toolchain carried over from the snapshot.
- `.github/workflows/code-quality.yml`, `.github/workflows/release-stable.yml`,
  `.github/workflows/release-beta.yml` — Conduction-standard pipelines from the
  snapshot, with `{{appId}}` placeholders resolved.
- `README.md`, `LICENSE` (defaulting to EUPL-1.2; user-overridable per design.md
  Decision 6), `phpcs.xml`, `phpmd.xml`, `psalm.xml`, `phpstan.neon`, `phpunit.xml`.

When `includeSeedData` is `true`, the tree SHALL additionally contain
`lib/Repair/SeedSampleData.php` seeding the source Application's sample objects,
registered as a `<post-migration>` step in `appinfo/info.xml`. When
`includeSeedData` is `false`, no `SeedSampleData.php` file SHALL be present and no
`<post-migration>` reference to it SHALL appear in `appinfo/info.xml`.

#### Scenario: Tree shape matches the snapshot

- **GIVEN** an export against a minimal manifest completes
- **WHEN** the resulting archive is unzipped
- **THEN** every path listed in the embedded template's `.path-manifest.txt` is present
- **AND** no unresolved `{{placeholder}}` tokens remain in any text file

#### Scenario: info.xml carries the manifest's navigation entry

- **GIVEN** the source manifest declares a menu entry
  `{ id: "Things", label: "Zaken overzicht", route: "Things" }`
- **WHEN** the export completes
- **THEN** the exported `appinfo/info.xml` contains a `<navigations><navigation>`
  declaration whose `id` matches the exported appId
- **AND** the `<name>` element matches the manifest entry's label

#### Scenario: Seed data appears in the exported tree when toggled on

- **GIVEN** the source Application's namespace contains three sample objects
- **WHEN** the user exports with `includeSeedData: true`
- **THEN** the exported tree contains `lib/Repair/SeedSampleData.php` carrying those
  three objects' payloads
- **AND** `appinfo/info.xml` contains a `<post-migration>` step referencing
  `SeedSampleData`

#### Scenario: Seed data omitted when toggled off

- **GIVEN** the source Application's namespace contains sample objects
- **WHEN** the user exports the same Application with `includeSeedData: false`
- **THEN** the exported tree contains NO `SeedSampleData.php` file
- **AND** no `<post-migration>` reference to `SeedSampleData` appears in
  `appinfo/info.xml`

---

### Requirement: REQ-OBEX-004 Companion schemas migrate into the exported app's own namespace

The exporter SHALL emit a `lib/Settings/<newapp>_register.json` declaring a fresh OR
register namespace named after the exported appId, and SHALL relocate every companion
schema referenced by the source manifest from OpenBuilt's `openbuilt` namespace into
that new namespace. The exporter SHALL rewrite every `config.register` /
`config.schema` reference inside the embedded `src/manifest.json` so the exported app
reads from its own register, not from `openbuilt`. The exporter SHALL NOT copy the
`Application`, `BuiltAppRoute`, or `ExportJob` schemas into the new register.

#### Scenario: Manifest references rewritten to the new namespace

- **GIVEN** the source manifest references
  `{ register: "openbuilt", schema: "melding-bericht" }` on a page config
- **WHEN** the export completes for appId `melding-systeem`
- **THEN** the exported `src/manifest.json` references
  `{ register: "melding-systeem", schema: "melding-bericht" }`

#### Scenario: OpenBuilt internals excluded from the exported register

- **GIVEN** the exporter writes `lib/Settings/<newapp>_register.json`
- **WHEN** the file is inspected
- **THEN** the file contains the companion schemas referenced by the manifest
- **AND** the file contains NO `Application`, `BuiltAppRoute`, or `ExportJob` schema
  entries

---

### Requirement: REQ-OBEX-005 Exported manifest is bundled and Tier-4

The exported `src/manifest.json` SHALL be the **sole** manifest source for the exported
app — there SHALL NOT be a per-slug manifest endpoint, an `options.fetcher` redirect,
or any other runtime indirection. The generated `src/main.js` SHALL call
`useAppManifest('<newapp>', bundledManifest)` with the bundled blob directly. The
exported app SHALL NOT mount a nested `<CnAppRoot>`.

#### Scenario: Generated main.js mounts CnAppRoot at top level

- **GIVEN** an export completes
- **WHEN** `src/main.js` is inspected
- **THEN** the file contains `useAppManifest('<newapp>', bundledManifest)`
- **AND** the `<CnAppRoot>` mount targets `#content`
- **AND** there is no parent `<CnAppRoot>` wrapper

#### Scenario: No manifest endpoint exists in the exported app

- **GIVEN** an export completes
- **WHEN** the exported `appinfo/routes.php` is inspected
- **THEN** the file contains NO route mapping to a `getManifest` controller method

---

### Requirement: REQ-OBEX-006 Export target — ZIP archive

When the user selects `target: "zip"`, the system SHALL produce a single `.zip` file
containing the full exported tree, store it in Nextcloud's app-data area under
`appdata_<instance>/openbuilt/exports/<jobUuid>/`, set the ExportJob's `downloadUrl`
to `/index.php/apps/openbuilt/api/exports/{uuid}/download`, set `downloadExpiresAt`
to 24 hours after job completion, and transition the job to `succeeded`. After expiry,
the download endpoint SHALL return 410 Gone and the archive SHALL be purged by a daily
cleanup background job (`CleanupExpiredExports`).

#### Scenario: ZIP download succeeds within 24 hours

- **GIVEN** the user requests an export with `target: "zip"` and the job completed 5 minutes ago
- **WHEN** the user GETs the `downloadUrl`
- **THEN** the response is 200 with `Content-Type: application/zip`
- **AND** the response body, when unzipped, is byte-equivalent to the produced archive

#### Scenario: ZIP download expires after 24 hours

- **GIVEN** the export job completed more than 24 hours ago
- **WHEN** the user requests the same `downloadUrl`
- **THEN** the endpoint returns 410 Gone
- **AND** the archive has been removed from app-data

---

### Requirement: REQ-OBEX-007 Export target — GitHub repository

When the user selects `target: "github"`, the system SHALL:

1. Create a new GitHub repository under the user-supplied org with the user-supplied
   name and visibility (`public` or `private`).
2. Push the exported tree as an initial commit on a `bootstrap` branch.
3. Open a pull request from `bootstrap` to the repo's default branch (`development`
   if the org's standard ruleset prescribes it, otherwise `main`) with a placeholder
   title `"chore: bootstrap from OpenBuilt"` and a body linking back to the source
   OpenBuilt Application.
4. Populate the ExportJob's `downloadUrl` field with the resulting PR URL.

The GitHub PAT SHALL be provided once by the user in the export dialog and SHALL be
stored exclusively via Nextcloud's `ICredentialsManager`. The PAT SHALL NOT be
persisted on the ExportJob object, in plaintext logs, or in any
`x-openregister-lifecycle` audit field. The credential record SHALL be deleted on job
terminal state (succeeded or failed).

#### Scenario: GitHub export creates repo and PR

- **GIVEN** the user submits an export with `target: "github"`, `githubOrg: "gemeente-amsterdam"`, `githubRepo: "vergunning-tracker"`, `githubVisibility: "public"`, and a valid PAT
- **WHEN** the background job completes
- **THEN** the ExportJob has `status: "succeeded"`
- **AND** `downloadUrl` is set to the PR URL at `github.com/gemeente-amsterdam/vergunning-tracker`
- **AND** the `bootstrap` branch contains the exported tree
- **AND** a PR is open against the default branch

#### Scenario: PAT is wiped on job terminal state

- **GIVEN** an ExportJob reaches `succeeded` or `failed`
- **WHEN** the terminal state is reached
- **THEN** no record of the PAT exists in `ICredentialsManager` for that job's key

#### Scenario: Auth failure surfaces in errorMessage

- **GIVEN** the user submits a GitHub export with an invalid PAT
- **WHEN** the background job processes the GitHub phase
- **THEN** the ExportJob transitions to `failed`
- **AND** `errorMessage` contains a human-readable auth-failure summary (without echoing the PAT)
- **AND** no repository is created

#### Scenario: GitHub re-export against an existing repo fails fast

- **GIVEN** `githubOrg: "gemeente-rotterdam"` and `githubRepo: "klachten-beheer"` already exist
- **WHEN** the user re-exports to GitHub with the same org and repo
- **THEN** the ExportJob transitions to `failed` with `errorMessage` containing `"Repository gemeente-rotterdam/klachten-beheer already exists"`
- **AND** no destructive push is attempted

---

### Requirement: REQ-OBEX-008 Re-exports are idempotent

The system SHALL ensure that re-exporting the same Application version with the same
`includeSeedData` flag produces a byte-equivalent ZIP archive. The exporter SHALL NOT
embed creation timestamps, random UUIDs, or the running OpenBuilt instance's identity
into any text file committed to the exported tree. The PHP `composer.json` and JS
`package.json` SHALL pin dependency versions identically across runs.

#### Scenario: Two ZIPs of the same version match byte-for-byte

- **GIVEN** an Application with `applicationVersion: "1.0.0"` and `includeSeedData: false`
- **WHEN** the user exports the Application twice in a row with the same inputs
- **THEN** the two resulting ZIPs are byte-equivalent
- **OR** if a ZIP tool's timestamp encoding precludes byte equality, their unzipped
  trees produce identical SHA-256 file digests for every file

---

### Requirement: REQ-OBEX-009 Export is asynchronous via Nextcloud's IJob

The exporter SHALL run as a Nextcloud background job
(`lib/BackgroundJob/RunExportJob.php` implementing `OCP\BackgroundJob\IJob`)
registered in `appinfo/info.xml`. The `POST /api/applications/{slug}/exports` endpoint
SHALL return 202 Accepted immediately with the ExportJob's UUID, and the background
job SHALL pick up the queued ExportJob on its next tick. The frontend SHALL poll the
ExportJob via OR REST every 2 seconds until terminal state.

#### Scenario: POST returns 202 immediately

- **GIVEN** the user submits a valid export request
- **WHEN** the controller processes the request
- **THEN** the controller returns 202 in under 500 ms
- **AND** the response body contains the ExportJob UUID

#### Scenario: Background job advances the ExportJob through phases

- **GIVEN** a `queued` ExportJob
- **WHEN** the background job runs
- **THEN** the ExportJob transitions through `running` to `succeeded` (or `failed`)
- **AND** the `log` array gains entries for each major phase: `template-copy`,
  `placeholder-replacement`, `manifest-bundling`, `schema-emission`,
  `archive-or-push`, `complete`

---

### Requirement: REQ-OBEX-010 Exported app boots standalone with zero OpenBuilt dependency

The system SHALL ensure that the exported app, when installed in a Nextcloud instance
that does NOT have OpenBuilt installed, boots to a working `CnAppRoot`-rendered surface
using only its bundled `src/manifest.json`, companion register, and standard Conduction
runtime dependencies. The exported `composer.json`, `package.json`, and
`appinfo/info.xml` SHALL NOT reference `openbuilt` as a dependency, peer dependency,
or required app.

#### Scenario: Exported app installs without OpenBuilt

- **GIVEN** the exported app is enabled on a Nextcloud instance that has OpenRegister installed but NOT OpenBuilt
- **WHEN** a user navigates to the app's top-bar entry
- **THEN** the app's index page renders correctly via the manifest-driven `CnAppRoot` surface
- **AND** no error logs reference a missing `openbuilt` dependency

#### Scenario: No openbuilt string in exported dependency files

- **GIVEN** an export completes
- **WHEN** the exported `composer.json`, `package.json`, and `appinfo/info.xml` are inspected
- **THEN** none of them contains the substring `openbuilt` (case-insensitive) as a dependency reference
