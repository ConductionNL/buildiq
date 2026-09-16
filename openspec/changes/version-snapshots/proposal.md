---
kind: code
---

## Why

The user tutorial "Snapshot and roll back a version" (`docs/tutorials/user/07-version-snapshots.md`)
promises two things the app does not do:

- **Take snapshot** on the Version history tab, with a label, listed with who took it and when.
- **Roll back** to a snapshot keeps the state it replaces as a *Previous draft* snapshot, so a
  rollback can itself be undone.

Today the Version history tab only lists the app's versions, and "Roll back" copies another
version's manifest over the current one with nothing kept.

## What changes

- New schema `version-snapshot` in the buildiq register: a named, frozen copy of one version's
  manifest (`application`, `version`, `label`, `manifest`, `checksum`, `takenBy`, `takenAt`,
  `kind`). A snapshot is not an ApplicationVersion, so ADR-002 (no sibling version rows) holds.
- New endpoints, owners and editors only (viewers may list):
  - `GET  /api/applications/{appSlug}/snapshots?version={versionSlug}`
  - `POST /api/applications/{appSlug}/snapshots` with `{label, version}`
  - `POST /api/applications/{appSlug}/snapshots/{snapshotUuid}/restore`
- Restoring first saves the version's current manifest as a `previous-draft` snapshot labelled
  "Previous draft", then writes the snapshot's manifest onto the version.
- The Version history tab gets a **Take snapshot** button (label prompt) and a snapshot list with
  label, who took it, when, the checksum, and **Roll back to this version**.

Out of scope: picking a snapshot in the Diff tab (tutorial step 4) and snapshotting records.

## Capabilities

### Added

- `openbuild-version-snapshots`: named snapshots and undoable rollback.
