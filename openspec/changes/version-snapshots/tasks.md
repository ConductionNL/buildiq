## 1. Storage

- [x] 1.1 Add the `version-snapshot` schema as fragment `lib/Settings/register.d/80-version-snapshots.json` (the fragment hash re-triggers the import) and list it on the `buildiq` register.

## 2. Backend

- [x] 2.1 `VersionSnapshotService`: list, take, restore (restore keeps a `previous-draft` snapshot first).
- [x] 2.2 `VersionSnapshotsController` + routes, with role checks (read: owners/editors/viewers; write: owners/editors; audited admin bypass as elsewhere).
- [x] 2.3 Unit tests for the service and the controller role checks.

## 3. Frontend

- [x] 3.1 Label prompt: reuses `PromptTextDialog` (src/dialogs), and `ConfirmActionDialog` for the rollback question.
- [x] 3.2 Version history tab (`VersionSnapshotsPanel`): Take snapshot button, snapshot list (label, taken by, taken at, checksum), Roll back to this version with confirmation.
- [x] 3.3 Vitest for the dialog and the tab wiring.
- [x] 3.4 en + nl strings.
