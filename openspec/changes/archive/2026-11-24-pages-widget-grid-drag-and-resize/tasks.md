# Tasks: pages-widget-grid-drag-and-resize

- [x] 1. Add `@spec openspec/specs/openbuild-page-designer/spec.md#REQ-OBPD-012` to the `persistManifestDelta` handler in `src/builder.js` (verified: on `manifestPersister()`, which builds the handler)
- [x] 2. `tests/e2e/page-grid-drag.spec.ts`: open a seeded built app with a dashboard page, switch to Edit with Buildiq, drag the first widget one column to the right and widen it by one column, Save, reload, and assert the widget's `gs-x` and `gs-w` kept the new values; then move it back with the keyboard (focus the handle, arrow key, Save) and assert the stored position (written, runs in nightly: the spec sits in tests/e2e and is not verified against a live instance)
- [x] 3. Same test for a detail page with a widget grid body (written, runs in nightly: the detail-page case is the fourth test in `tests/e2e/page-grid-drag.spec.ts`, not verified against a live instance)
- [x] 4. Archive this change once 1 to 3 are done; the requirement syncs into `specs/openbuild-page-designer` (done: archived with the requirement synced)
