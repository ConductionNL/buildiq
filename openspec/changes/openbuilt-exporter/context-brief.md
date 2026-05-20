# openbuilt-exporter Specification

## Purpose
TBD - created by archiving change openbuilt-export-to-real-app. Update Purpose after archive.
## Requirements
### Requirement: ExportJob schema declaration

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
`errorMessage` (string, optional), `log` (array of strings,
optional, append-only progress notes). The schema SHALL declare
`x-openregister-lifecycle` with the
`queued → running → succeeded|failed` state machine (no terminal
re-entry; `failed → queued` permitted only via explicit retry).

#### Scenario: Schema validates a well-formed ExportJob object

- **WHEN** an integrator POSTs an ExportJob with
  `applicationUuid`, `applicationVersion: "1.0.0"`, `target: "zip"`
  to OR REST
- **THEN** OR creates the object with `status: "queued"` and a
  fresh `uuid`, and the OR audit trail records the creation event.

#### Scenario: Schema rejects an invalid target

- **WHEN** an integrator POSTs an ExportJob with
  `target: "ftp"`
- **THEN** OR returns a 4xx validation error referencing the enum
  constraint on `target` and the ExportJob is NOT created.

#### Scenario: Disallowed lifecycle transition rejected

- **WHEN** the system attempts to transition an ExportJob from
  `succeeded` back to `running`
- **THEN** the OR lifecycle engine rejects the transition with a
  4xx error and the audit trail records the attempt.

---

### Requirement: Export targets a specific Application version

The export pipeline SHALL operate on a **specific published version**
of an Application — never on the in-flight draft. The frontend dialog
SHALL default the version field to the Application's current
`published` version (per `openbuilt-versioning`). The system SHALL
reject an export request whose `applicationVersion` does not match any
known published version of the referenced Application.

#### Scenario: Default version is the current published version

- **WHEN** the user opens the Export dialog for an Application whose
  current published version is `1.2.0`
- **THEN** the dialog's version field is pre-filled with `1.2.0`.

#### Scenario: Reject export of an unknown version

- **WHEN** the user submits an export with
  `applicationVersion: "9.9.9"` and no such published version exists
- **THEN** the controller returns 422 with an error message naming
  the unknown version and no ExportJob is created.

#### Scenario: Reject export of a draft

- **WHEN** the user submits an export with an `applicationVersion`
  that resolves to a `draft` (not `published`) snapshot
- **THEN** the controller returns 422 with an error message
  indicating drafts cannot be exported.

---

### Requirement: Exported tree shape conforms to the nextcloud-app-template baseline

The exported archive SHALL contain a directory tree matching the
snapshot of `nextcloud-app-template` embedded under
`lib/Resources/template/`, with every placeholder
(`{{appId}}`, `{{appNamespace}}`, `{{appName}}`,
`{{appDescription}}`, `{{appVersion}}`, `{{authorName}}`,
`{{authorEmail}}`, `{{license}}`) replaced by values derived from
the source Application's manifest + ExportJob inputs. The tree
SHALL include at minimum:

- `appinfo/info.xml` carrying the new id, namespace, version,
  navigation entry, and dependencies declared by the source
  manifest.
- `lib/AppInfo/Application.php` with the new namespace.
- `lib/Settings/<newapp>_register.json` carrying the companion
  schemas referenced by the manifest, slug-prefixed where the
  source uses the shared `openbuilt` namespace.
- `lib/Repair/InitializeSettings.php` invoking
  `ConfigurationService::importFromApp()` against the new
  register.
- `src/manifest.json` — the source Application's manifest blob,
  with its `version` field set to the exported `applicationVersion`.
- `src/main.js` mounting `<CnAppRoot>` via
  `useAppManifest('<newapp>', bundledManifest)` (Tier-4 pattern).
- `src/App.vue` shell.
- `package.json` with deps (Vue 2.7, `@conduction/nextcloud-vue`,
  `@nextcloud/vue`, build tooling) carried over from the snapshot.
- `composer.json` with PHP deps + the Conduction PHPCS / PHPMD /
  Psalm / PHPStan / PHPUnit toolchain carried over.
- `.github/workflows/code-quality.yml`,
  `.github/workflows/release-stable.yml`,
  `.github/workflows/release-beta.yml` — Conduction-standard
  pipelines from the snapshot, with `{{appId}}` placeholders
  resolved.
- `README.md`, `LICENSE` (defaulting to EUPL-1.2; user-overridable
  per Decision 6 of `design.md`), `phpcs.xml`, `phpmd.xml`,
  `psalm.xml`, `phpstan.neon`, `phpunit.xml`.

#### Scenario: Tree shape matches the snapshot

- **WHEN** an export against a minimal manifest completes
- **THEN** unzipping the archive yields every path listed in the
  embedded template's path manifest, with no unresolved
  `{{placeholder}}` tokens remaining in any text file.

#### Scenario: info.xml carries the manifest's navigation entry

- **WHEN** the source manifest declares a menu entry
  `{ id: "Things", label: "...", route: "Things" }`
- **THEN** the exported `appinfo/info.xml` contains a corresponding
  `<navigations><navigation>` declaration whose `id` matches the
  exported appId and whose `name` matches the manifest entry's
  label.

---

### Requirement: Companion schemas migrate into the exported app's own namespace

The exporter SHALL emit a `lib/Settings/<newapp>_register.json`
declaring a fresh OR register namespace named after the exported
appId, and SHALL relocate every companion schema referenced by the
source manifest from OpenBuilt's `openbuilt` namespace into that
new namespace. The exporter SHALL rewrite every
`config.register` / `config.schema` reference inside the embedded
`src/manifest.json` so the exported app reads from its own
register, not from `openbuilt`. The exporter SHALL NOT copy the
`Application`, `BuiltAppRoute`, or `ExportJob` schemas into the
new register (those are OpenBuilt's internal machinery).

#### Scenario: Manifest references rewritten to the new namespace

- **WHEN** the source manifest references
  `{ register: "openbuilt", schema: "hello-message" }` on a page
  config
- **THEN** the exported `src/manifest.json` references
  `{ register: "hello-world", schema: "hello-message" }` (assuming
  exported appId `hello-world`).

#### Scenario: OpenBuilt internals excluded from the exported register

- **WHEN** the exporter writes
  `lib/Settings/<newapp>_register.json`
- **THEN** the file contains the companion schemas referenced by
  the manifest but contains NO `Application`, `BuiltAppRoute`, or
  `ExportJob` schema entries.

---

### Requirement: Exported manifest is bundled and Tier-4

The exported `src/manifest.json` SHALL be the **sole** manifest
source for the exported app — there SHALL NOT be a per-slug manifest
endpoint, an `options.fetcher` redirect, or any other runtime
indirection (the workaround documented in bootstrap-openbuilt
Decision 4 collapses for the exported app because it owns exactly
one manifest). The generated `src/main.js` SHALL call
`useAppManifest('<newapp>', bundledManifest)` with the bundled blob
directly. The exported app SHALL NOT mount a nested `CnAppRoot` —
its `CnAppRoot` is the top-level mount (the nested-mount
arrangement of bootstrap-openbuilt Decision 5 collapses for the
same reason).

#### Scenario: Generated main.js mounts CnAppRoot at top level

- **WHEN** an export completes and `src/main.js` is inspected
- **THEN** the file contains `useAppManifest('<newapp>',
  bundledManifest)` and the `<CnAppRoot>` mount is on
  `#content`, with no parent `<CnAppRoot>` wrapper.

#### Scenario: No manifest endpoint exists in the exported app

- **WHEN** the exported `appinfo/routes.php` is inspected
- **THEN** the file contains NO route mapping to a
  `getManifest` controller method.

---

### Requirement: Export target — ZIP archive

When the user selects target `zip`, the system SHALL produce a
single `.zip` file containing the full exported tree, store it in
Nextcloud's app-data area under
`appdata_<instance>/openbuilt/exports/<jobUuid>/`, set the
ExportJob's `downloadUrl` to
`/index.php/apps/openbuilt/api/exports/{uuid}/download`, set
`downloadExpiresAt` to 24 hours after job completion, and transition
the job to `succeeded`. After expiry, the download endpoint SHALL
return 410 Gone and the archive SHALL be purged by a daily
cleanup background job.

#### Scenario: ZIP download succeeds within 24h

- **WHEN** the user requests an export with target `zip` and the
  job completes 5 minutes ago
- **THEN** GETting `downloadUrl` returns a 200 with
  `Content-Type: application/zip` and a body whose unzip is
  byte-equivalent to the produced archive.

#### Scenario: ZIP download expires after 24h

- **WHEN** the user requests the same `downloadUrl` 25 hours after
  job completion
- **THEN** the endpoint returns 410 Gone and the archive has been
  removed from app-data.

---

### Requirement: Export target — GitHub repository

When the user selects target `github`, the system SHALL:

1. Create a new GitHub repository under the user-supplied org with
   the user-supplied name and visibility (`public` or `private`).
2. Push the exported tree as an initial commit on a `bootstrap`
   branch.
3. Open a pull request from `bootstrap` to the repo's default
   branch (`development` if the org's standard ruleset prescribes
   it, otherwise `main`) with a placeholder title
   `"chore: bootstrap from OpenBuilt"` and a body linking back to
   the source OpenBuilt Application.
4. Populate the ExportJob's `downloadUrl` field with the resulting
   PR URL.

The GitHub PAT SHALL be provided once by the user in the export
dialog and SHALL be stored exclusively via Nextcloud's
`ICredentialsManager`. The PAT SHALL NOT be persisted on the
ExportJob object, in plaintext logs, or in any
`x-openregister-lifecycle` audit field. Token usage SHALL be
scoped to the single export run; the credential record SHALL be
deleted on job terminal state (succeeded or failed).

#### Scenario: GitHub export creates repo + PR

- **WHEN** the user submits an export with `target: github`, org
  `acme-co`, repo `hello-world`, visibility `public`, and a valid
  PAT
- **THEN** the job completes with `status: succeeded`,
  `downloadUrl` set to the PR URL, the repo exists at
  `github.com/acme-co/hello-world`, the `bootstrap` branch
  contains the exported tree, and a PR is open against the
  default branch.

#### Scenario: PAT is wiped on job terminal state

- **WHEN** an ExportJob reaches `succeeded` or `failed`
- **THEN** no record of the PAT exists in
  `ICredentialsManager` for that job's key.

#### Scenario: Auth failure surfaces in errorMessage

- **WHEN** the user submits an export with an invalid PAT
- **THEN** the job transitions to `failed`, `errorMessage`
  contains a human-readable auth-failure summary (without echoing
  the PAT), and no repo is created.

---

### Requirement: Export is asynchronous via Nextcloud's IJob

The exporter SHALL run as a Nextcloud background job
(`lib/BackgroundJob/RunExportJob.php` implementing
`OCP\BackgroundJob\IJob`) registered in `appinfo/info.xml`. The
`POST /api/applications/{slug}/exports` endpoint SHALL return 202
Accepted immediately with the ExportJob's UUID, and the background
job SHALL pick up the queued job on its next tick (or sooner if
Nextcloud's job scheduler is configured for immediate dispatch).
The frontend SHALL poll the ExportJob via OR REST every 2 seconds
until terminal state.

#### Scenario: POST returns 202 immediately

- **WHEN** the user submits an export
- **THEN** the controller returns 202 in under 500ms with the
  ExportJob UUID in the response body.

#### Scenario: Background job advances the ExportJob

- **WHEN** the background job runs against a `queued` ExportJob
- **THEN** the job transitions through `running` to
  `succeeded` (or `failed`) and the `log` array gains entries
  describing the major phases (`template-copy`,
  `placeholder-replacement`, `manifest-bundling`,
  `schema-emission`, `archive-or-push`, `complete`).

---

### Requirement: Re-exports are idempotent

The system SHALL ensure that re-exporting the same Application version with the same
`includeSeedData` flag produces a byte-equivalent ZIP archive.
The exporter SHALL NOT embed creation timestamps, random UUIDs, or
the running OpenBuilt instance's identity into any text file
committed to the exported tree. The PHP `composer.json` and JS
`package.json` SHALL pin dependency versions identically across
runs.

#### Scenario: Two ZIPs of the same version match byte-for-byte

- **WHEN** the user exports `applicationVersion: 1.0.0` twice in
  a row with the same `includeSeedData` value
- **THEN** the two resulting ZIPs are byte-equivalent (or, if a
  modern ZIP tool's timestamp encoding precludes byte equality,
  their unzipped trees produce identical SHA-256 file digests).

#### Scenario: GitHub re-export against an existing repo fails fast

- **WHEN** the user re-exports to GitHub with the same
  `githubOrg` + `githubRepo` that already exist
- **THEN** the job transitions to `failed` with
  `errorMessage: "Repository <org>/<repo> already exists"` and no
  destructive push is attempted.

---

### Requirement: Optional seed-data inclusion

When `includeSeedData: true`, the exporter SHALL include a
`lib/Repair/SeedSampleData.php` step in the exported tree that
seeds the sample objects currently held in the source
Application's namespace into the exported app's namespace on
first install. The repair step SHALL guard on existing-object
identity to remain idempotent across re-installs.

#### Scenario: Seed data appears in the exported tree when toggled on

- **WHEN** the user exports an Application whose namespace
  contains three sample `hello-message` objects with
  `includeSeedData: true`
- **THEN** the exported tree contains
  `lib/Repair/SeedSampleData.php` carrying those three objects'
  payloads, registered as a `<post-migration>` step in
  `appinfo/info.xml`.

#### Scenario: Seed data omitted when toggled off

- **WHEN** the user exports the same Application with
  `includeSeedData: false`
- **THEN** the exported tree contains NO `SeedSampleData.php`
  file and no `<post-migration>` reference to it in
  `appinfo/info.xml`.

---

### Requirement: Exported app boots standalone with zero OpenBuilt dependency

The system SHALL ensure that the exported app, when installed in a Nextcloud
instance that does NOT have OpenBuilt installed, boots to a working
`CnAppRoot`-rendered surface using only its bundled
`src/manifest.json` + companion register + standard Conduction
runtime dependencies. The exported `composer.json`,
`package.json`, and `appinfo/info.xml` SHALL NOT reference
`openbuilt` as a dependency, peer dependency, or required app.

#### Scenario: Exported app installs without OpenBuilt

- **WHEN** the exported app is enabled on a Nextcloud instance
  that has OpenRegister installed but NOT OpenBuilt
- **THEN** the app's top-bar entry appears, navigating to it
  renders the manifest-driven index page, and no error logs
  reference a missing `openbuilt` dependency.

#### Scenario: No openbuilt string in exported dependency files

- **WHEN** the exported `composer.json`, `package.json`, and
  `appinfo/info.xml` are inspected
- **THEN** none of them contains the substring `openbuilt`
  (case-insensitive) as a dependency reference.

