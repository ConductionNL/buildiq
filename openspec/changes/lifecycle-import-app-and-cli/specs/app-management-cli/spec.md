# Spec: app-management-cli

## Purpose

An administrator manages buildiq apps from the server's command line: lists
them, exports one to a file, imports one from a file, and deletes one. The
commands use the same services as the browser, so an app handled from the shell
is the same as one handled on screen.

## ADDED Requirements

### Requirement: List apps from the command line (REQ-BQIC-004)

`occ buildiq:app:list` SHALL print every app with its slug, name, and versions
with their status, and SHALL offer JSON output for scripts.

#### Scenario: An administrator lists the apps on an instance

- **GIVEN** an instance with the apps `vergunningen` (development draft, production published) and `meldingen`
- **WHEN** an administrator runs `occ buildiq:app:list`
- **THEN** the output has a row for each app with its versions and their status, and the command exits with 0

### Requirement: Export an app to a file (REQ-BQIC-005)

`occ buildiq:app:export <slug>` SHALL write the same archive the export dialog
produces to the given output path, for the given version (the newest published
one by default), with seed data only when asked. An unknown slug or an
unwritable path SHALL exit non-zero with a message and write nothing.

#### Scenario: An administrator exports the production version

- **GIVEN** the app `vergunningen` with a published production version
- **WHEN** an administrator runs the export command for `vergunningen` with the output path `/srv/backup/vergunningen.zip`
- **THEN** that file exists, holds `openbuild-app.json` with slug `vergunningen`, and the command exits with 0

### Requirement: Import an app from a file (REQ-BQIC-006)

`occ buildiq:app:import <file>` SHALL import an archive through the same import
service as the browser, SHALL require the UID of the user who will own the new
app, and SHALL refuse an unknown UID. A package the parser refuses SHALL exit
non-zero and print the parser's code and the file at fault.

#### Scenario: An administrator imports an archive for a colleague

- **GIVEN** the archive `/srv/backup/vergunningen.zip` and a user `sanne`
- **WHEN** an administrator runs the import command for that file with owner `sanne`
- **THEN** the app `vergunningen` is created with `sanne` as its owner and the command exits with 0

#### Scenario: An unknown owner is refused

- **GIVEN** the same archive
- **WHEN** an administrator runs the import command with owner `nobody-here`
- **THEN** the command exits non-zero, says the user does not exist, and creates nothing

### Requirement: Delete an app from the command line (REQ-BQIC-007)

`occ buildiq:app:delete <slug>` SHALL ask for confirmation unless forced, SHALL
keep the app's registers and records unless asked to delete them, and SHALL list
anything it tried and failed to remove.

#### Scenario: An administrator deletes an app and keeps its data

- **GIVEN** the app `meldingen` with records
- **WHEN** an administrator runs the delete command for `meldingen` and confirms
- **THEN** the app and its versions are gone, its registers and records remain, and the command exits with 0
