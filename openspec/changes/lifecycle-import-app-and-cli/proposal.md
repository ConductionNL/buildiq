---
kind: code
---

# Proposal: lifecycle-import-app-and-cli

## Why

Two rows of the buildiq matrix meet at one service: taking an app package in,
and doing it (and the rest of an app's upkeep) without the browser. No tender,
featureRequest or roadmap row carries either; the competitor cells are the
demand.

buildiq matrix, row `lc-import-app`, "Import an app package into another
instance.", rated `no`. Four competitors rated `yes`:

- NocoBase: "packages/plugins/@nocobase/plugin-backups/src/client-v2/components/RestoreFromLocal.tsx:128
  "Restore backup from local" uploads a backup from another instance, with
  missing-plugin warnings".
- Budibase: "CreateWorkspaceModal.svelte:290 'Import workspace' from an export
  file (with password step :202-217) and ... general.svelte:230 'Import
  workspace' to update an existing one".
- Appsmith: "app/client/src/ce/pages/Applications/index.tsx:990 ImportModal
  takes an exported JSON file, entered from ... WorkspaceAction.tsx:113 Import
  menu item".
- Mendix: "Importing App and Solution Packages: a .mpk package holding a
  complete app is imported into Studio Pro"
  (https://docs.mendix.com/refguide/import-and-export/).

buildiq matrix, row `ops-cli`, "Manage apps and templates from the command
line.", rated `partial`, built. Three competitors rated `yes` on what buildiq
lacks:

- NocoBase: "packages/core/cli/src/commands holds app start, stop, upgrade
  (app/start.ts:261), backup create and restore, env management (env/add.ts:123)
  and plugin import (plugin/import.ts:25)".
- Mendix: "mx tool: create-project, check, translate and more"
  (https://docs.mendix.com/refguide/mx-command-line-tool/).
- Microsoft Power Apps: "Power Platform CLI, e.g. pac pipeline command group"
  (https://learn.microsoft.com/en-us/power-platform/alm/pipelines).

What buildiq does today, per the matrix `built.evidence`:

- `lc-import-app`: "No route or controller accepts an uploaded ZIP/ package to
  recreate an app; lib/Service/AppRepoParser.php only parses a GitHub repo URL
  (github-shop-catalogue / github-app-sync), not an uploaded file."
- `ops-cli`: "appinfo/info.xml:272-273 registers two commands:
  OCA\Buildiq\Command\SeedHelloWorldFixture ('buildiq:seed-hello-world-fixture',
  description says 'test/dev only') and OCA\Buildiq\Command\PublishTemplates
  ('buildiq:templates:publish', ...)". The matrix note: "there is no CLI to
  manage (list/create/delete/export) a built application itself". The missing
  half this change builds is managing apps: list, export, import and delete.

The export side is already there, and it already promises the import. Every
exported archive carries `openbuild-app.json`, `manifest.json`, `schemas/` and
`data/` at its root, "the app repository layout AppRepoParser reads"
(`lib/Service/ExportAppContentBundler.php:42-44`), and its README tells the
reader: "In Buildiq: open Applications, choose Import application and pick this
archive." (line 357). There is no such button. `AppRepoParser::parse()` takes a
`path => contents` map (`lib/Service/AppRepoParser.php:177`), so the parser does
not care whether the map came from GitHub or from a file.

## What changes

- The Applications page gets "Import app" next to "Add app": pick an archive,
  see what it holds, choose a slug, import.
- A new route takes the upload, reads only the package files from it, parses
  them with `AppRepoParser`, and creates the app through the same install seam
  the GitHub shop uses. The caller becomes the owner.
- Records in the archive's `data/` folder come along only when the maker ticks
  "Import records", through OpenRegister's register import.
- Four `occ` commands manage apps: `buildiq:app:list`, `buildiq:app:export`,
  `buildiq:app:import` and `buildiq:app:delete`. Import and export use the same
  services as the browser.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | lc-import-app | Import an app package into another instance. | no | an upload that recreates an app from an exported archive |
| buildiq | ops-cli | Manage apps and templates from the command line. | partial | commands to list, export, import and delete apps (templates already publish from the CLI) |

## Existing work it builds on

- `openspec/specs/github-app-repo-format`: the package layout, "AppRepoParser
  maps a repo file set onto the clone seam", and "Strict, all-or-nothing,
  actionable import validation". The archive is that layout, so the rules hold
  unchanged.
- `github-shop-catalogue` (open): `ShopController::githubInstall()`
  (`lib/Controller/ShopController.php:194`) fetches repo files, parses them, and
  calls `ApplicationsController::installFromTemplateArray()`
  (`lib/Controller/ApplicationsController.php:1670`). The upload route follows
  the same three steps.
- `openspec/specs/openbuild-exporter`: the ZIP target and its archive layout.
- `openspec/specs/data-import-wizard`: records reach OpenRegister through its
  register import, never through a buildiq write.
- `openspec/specs/application-detail-ui`: app deletion through
  `ApplicationDeletionService::deleteApplication()`
  (`lib/Service/ApplicationDeletionService.php:107`), which the delete command
  calls.

## Sibling halves

None. OpenRegister's register import already takes the records; nothing new is
asked of it.

## Out of scope

- Updating an existing app from an archive (import always makes a new app).
- Importing an archive made by another product than buildiq.
- Commands for versions, promotion or templates beyond the existing publish.
- Running any code shipped in the archive: its `lib/` and `src/` are ignored.
