# Design: apps-standalone-deploy

Read at buildiq development `d21e42f`.

## Where it sits

- Export job: the `exportJob` schema in `lib/Settings/openbuild_register.json`
  (`includeSeedData` at line 859), extended through `register.d` fragments
  (`30-export-job-data-registers.json`, `32-export-job-flows.json`, whose comment
  records that OpenRegister silently drops an undeclared property and that the
  schema `version` must be bumped with each fragment).
- Queue and run: `ExportJobService::queue()` (`lib/Service/ExportJobService.php:113`)
  copies `includeSeedData` onto the job (line 151); `RunExportJob` loads the job
  (`lib/BackgroundJob/RunExportJob.php:149`, 186), reads `includeSeedData`
  (line 291) and calls `ExportService::generateAppZip()` (line 226).
- Tree: `ExportService::generateAppZip()` (`lib/Service/ExportService.php:181`)
  copies the template, resolves placeholders (`resolvePlaceholders()`, line 461)
  and bundles data registers, content, flows and agents;
  `buildScaffoldMap()` (line 297) builds the same tree as a path map for the
  GitHub target. The template is `lib/Resources/template/`, which already ships
  release workflows (`.github/workflows/release-stable.yml`).
- README: `ExportAppContentBundler::writeReadme()`
  (`lib/Service/ExportAppContentBundler.php:328-363`).
- Dialog: `src/dialogs/ExportDialog.vue`, switch at lines 31-35, default at line
  222, payload at line 439.
- Model to follow: `buildiq-compose.yaml` installs release tarballs into an
  `apps` volume before Nextcloud starts (lines 83-104, script from line 154) and
  pins `nextcloud:34-apache` (line 107). It also sets demo passwords (lines 73,
  119, 122), which the bundle must not copy.

## D1. "Its own server" is its own Nextcloud

A built app is a Nextcloud app: `CnAppRoot` renders its manifest and
OpenRegister holds its data (ADR-022, ADR-024). Running it without Nextcloud
would need a second renderer and a second data layer. So the bundle runs a
Nextcloud with OpenRegister and the app, and without buildiq. That is what
Mendix's cell describes too: the app in a container, on a cluster the customer
owns.

## D2. The image builds from the exported source

The `Dockerfile` has three stages: `composer install` without dev packages on the exported
PHP, `npm ci && npm run build` on the exported frontend, and a final stage from
the pinned Nextcloud image with the built app copied into `custom_apps/`. This
works for a ZIP export with no repository and no release, and for a GitHub export
before its first release. A release tarball would be faster, but it does not
exist for a ZIP export.

## D3. No default credentials, fail closed

`compose.yaml` reads `NEXTCLOUD_ADMIN_PASSWORD` and `POSTGRES_PASSWORD` with the
`${VAR:?}` form, so `docker compose up` stops with a message naming the missing
variable. `.env.example` lists them empty. The demo rig's `admin` and
`nextcloud` defaults are never copied.

## D4. OpenRegister pinned to the builder's version

`RunExportJob` reads the installed OpenRegister version from `IAppManager` (not injected there today) at
export time and writes it into the bundle as `OPENREGISTER_VERSION`. The
installer fetches that release tarball the way `buildiq-compose.yaml` does. The
administrator can raise it; the README says which version the app was built on.

## D5. One template folder, the existing placeholders

The files live in a new template folder, `lib/Resources/deploy/`, copied into
`deploy/` of the exported tree only when the job asks for it, then resolved by
the same `resolvePlaceholders()` pass (app id, name, OpenRegister version). The
GitHub path gets them through `buildScaffoldMap()`, so both targets produce the
same folder. A new optional `exportJob.includeServerBundle` boolean, declared in
a `register.d/33-export-job-server-bundle.json` fragment with the schema version
bumped, carries the choice.

## D6. The app's data arrives by its own install step

The exported app imports its registers and schemas on install (the template's
`info.xml` says so). Seed records and data-register rows come along only when the
existing switches chose them. The bundle adds no data path of its own.

## Risks

- The image build needs network access to Packagist and npm. The README says so.
- An OpenRegister release can be withdrawn. The installer fails loudly with the
  version it looked for, rather than falling back to another.
- A Nextcloud major drift on an old volume puts the instance in maintenance mode.
  The bundle pins the major, as the demo rig does.

## What it does not do

- It adds no buildiq route and no runtime service.
- It does not operate the server after it starts.
