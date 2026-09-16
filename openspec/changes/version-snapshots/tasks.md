## 1. Storage

- [ ] 1.1 Add the `version-snapshot` schema to `lib/Settings/openbuild_register.json` and bump the register version.

## 2. Backend

- [ ] 2.1 `VersionSnapshotService`: list, take, restore (restore keeps a `previous-draft` snapshot first).
- [ ] 2.2 `VersionSnapshotsController` + routes, with role checks (read: owners/editors/viewers; write: owners/editors; audited admin bypass as elsewhere).
- [ ] 2.3 Unit tests for the service and the controller role checks.

## 3. Frontend

- [ ] 3.1 `TakeSnapshotDialog` (src/dialogs) with a label field.
- [ ] 3.2 Version history tab: Take snapshot button, snapshot list (label, taken by, taken at, checksum), Roll back to this version with confirmation.
- [ ] 3.3 Vitest for the dialog and the tab wiring.
- [ ] 3.4 en + nl strings.
