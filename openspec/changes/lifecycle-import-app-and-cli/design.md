# Design: lifecycle-import-app-and-cli

Read at buildiq development `d21e42f`.

## Where it sits

- Package layout, written: `ExportAppContentBundler` puts `openbuild-app.json`,
  `manifest.json`, `schemas/<slug>.json` and `data/<slug>.jsonl` at the archive
  root (`lib/Service/ExportAppContentBundler.php:42-45`) and writes the README
  that promises an "Import application" choice (line 357).
- Package layout, read: `AppRepoParser::parse(array $files, ?array $repo)`
  (`lib/Service/AppRepoParser.php:177`) turns a `path => contents` map into the
  install payload. It reads `openbuild-app.json` (line 54), `manifest.json`
  (line 59), `schemas/` (line 64) and the v2 channels `data-registers/`,
  `connectors/`, `automations/`, `skills/`, `flows/` and `agents/` (lines
  87-116). It caps JSON depth (`MAX_JSON_DEPTH`, line 151) and fails all or
  nothing with an error code and the offending path. It does not read `data/`.
- Install seam: `ShopController::githubInstall()`
  (`lib/Controller/ShopController.php:194`) is `#[NoAdminRequired]` with a 401
  guard, fetches the repo files, parses them (line 239) and calls
  `ApplicationsController::installFromTemplateArray()`
  (`lib/Controller/ApplicationsController.php:1670`), which refuses a taken slug
  with 409 (lines 1687-1698) and makes the caller the owner. Route:
  `appinfo/routes.php:298`.
- Applications page: `src/components/VirtualAppsActions.vue:33-43`, the "Add
  app" button and the wizard it opens.
- Records: OpenRegister's `POST /apps/openregister/api/registers/{id}/import`,
  already used by `src/composables/useDataImport.js` (header, lines 10-18).
- CLI: `appinfo/info.xml:271-274` registers two commands.
  `lib/Command/PublishTemplates.php` shows the house style (options, dry run).
  Export runs through `ExportJobService::queue()` (line 113),
  `ExportJobService::runNow()` (line 180) and `resolveDownload()` (line 434).
  Deletion is `ApplicationDeletionService::deleteApplication()`
  (`lib/Service/ApplicationDeletionService.php:107`), which keeps data unless
  asked.

## D1. One import service for the browser, the CLI and the README's promise

A new `lib/Service/AppPackageImportService.php` takes an archive path, a slug, an
owner and the records choice. It unpacks the package files into a
`path => contents` map, calls `AppRepoParser::parse()`, installs through
`installFromTemplateArray()`, and then hands records to OpenRegister. The upload
route and `buildiq:app:import` both call it, so they cannot drift.

## D2. The archive is read, never run

An uploaded archive is untrusted. The service:

- refuses an upload over 50 MB or with more than 5,000 entries, before unpacking;
- reads only entries whose path is `openbuild-app.json`, `manifest.json`, or
  starts with one of `AppRepoParser`'s prefixes or `data/`, and ignores `lib/`,
  `src/`, `appinfo/` and everything else in the archive;
- refuses an entry with an absolute path, a `..` segment or a symlink, and stops
  when the unpacked size passes 200 MB (a zip bomb check);
- unpacks into memory, never onto disk under a name taken from the archive.

No PHP or JavaScript from the archive is loaded. The parser's existing rules
(slug pattern, format version, secret stripping on the v2 channels) apply as they
do for GitHub.

## D3. Same gate as the GitHub shop

`POST /api/applications/import` is `#[NoAdminRequired]` with the same in-body 401
guard as `githubInstall()`: any signed-in user may create an app they own from a
package, exactly as they may from a GitHub repository or the store. It creates
and reads no existing object, so it has no IDOR surface, and it takes a
`#[UserRateLimit]` like the wizard route.

## D4. Records only on request, through OpenRegister

`data/<slug>.jsonl` holds one record per line. When the maker ticks "Import
records", the service turns each file into a JSON array and posts it to
OpenRegister's register import for the new version's register, schema by schema.
Without the tick the app arrives empty. A records failure does not undo the app;
the result names the schema and the count that failed.

## D5. Commands run as a named user

`occ` runs with shell access, which is already the highest trust on the server,
but the objects still need an owner. `buildiq:app:import` and
`buildiq:app:delete` take a required owner or acting user UID and refuse an
unknown UID. `buildiq:app:export` writes the same ZIP the dialog produces, by queueing an
export job and running it at once with `runNow()`, then copying the archive to
the output path. Every command exits non-zero on failure and prints the parser's error
code for a bad package.

## Risks

- A large records import can be slow. It goes through OpenRegister's import, which
  already batches; the dialog shows progress per schema.
- An archive exported by a newer buildiq can carry a format version this one does
  not know. The parser refuses it with its existing message.

## What it does not do

- It never updates an existing app from an archive.
- It never executes or installs the exported Nextcloud app code in the archive.
