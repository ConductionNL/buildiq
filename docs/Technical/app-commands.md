---
title: App commands
description: Manage buildiq apps and templates with occ, without opening the builder.
---

# App commands

Every command exits non-zero on failure. Commands that create or remove apps
run as a named user, because the apps and their objects need an owner.

## `buildiq:app:list`

Lists every app with its slug, name and versions with their status.

| Option | Meaning |
|---|---|
| `--json` | Print the list as JSON, for scripts. |

## `buildiq:app:export <slug>`

Writes the same archive the export dialog produces.

| Option | Meaning |
|---|---|
| `-o`, `--output` | Where to write the archive (required). |
| `--app-version` | The version slug to export (default: the newest published version). |
| `--seed-data` | Include the app's records as seed data (`data/<schema>.jsonl`). |
| `--user` | The UID the export runs as (default: the app's owner). |

## `buildiq:app:import <file>`

Imports an exported archive as a new app, with the same checks as the
**Import app** dialog. A refused package prints the error code and the file at
fault, for example `Import refused: schema_unparseable in schemas/permit.json`.

| Option | Meaning |
|---|---|
| `--owner` | The UID of the user who will own the new app (required; an unknown UID is refused). |
| `--slug` | The new app's slug (default: the archive's own slug). A taken slug is refused. |
| `--name` | The new app's name (default: the archive's own name). |
| `--records` | Also import the records in `data/`. One line per schema says how many arrived and how many failed; a failed schema does not fail the import. |

## `buildiq:app:delete <slug>`

Deletes an app and its versions after asking for confirmation. Its registers
and records stay unless asked.

| Option | Meaning |
|---|---|
| `-f`, `--force` | Delete without asking. |
| `--delete-data` | Also delete the app's registers and their records. |

## `buildiq:templates:publish`

Publishes the seeded application templates to GitHub as template repositories,
one line per template with its outcome.

| Option | Meaning |
|---|---|
| `--credential` | The GitHub broker credential UUID to publish with (required). |
| `--org` | The organisation to publish under (default: the credential user's account). |
| `--user` | The credential owner, the acting user UID. |
| `--slug` | Publish only this template. |
| `--visibility` | `public` (default) or `private`, for new repositories. |
| `--dry-run` | List what would be published without calling GitHub. |
