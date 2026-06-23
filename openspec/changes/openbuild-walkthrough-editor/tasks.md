# Tasks — openbuild-walkthrough-editor

## 1. Edit-shell mode
- [ ] Add an **Edit walkthrough** entry to the OpenBuild edit-mode switcher (ADR-041).
- [ ] Recorder overlay: hover-highlight resolvable targets; click to create a step.

## 2. Target resolution
- [ ] `walkthroughTargetResolver` — walk up to the nearest manifest identity
      (`nav-item`/`widget`/`action`/`page`); else inject/use `data-walkthrough-id`
      (`element`); else CSS `selector` (flagged brittle).

## 3. advanceOn recording
- [ ] `walkthroughRecorder` — observe router + OR object store during recording;
      suggest `route-match` (+ `:id` capture) / `object-created` (register/schema);
      owner confirms/edits.

## 4. Step + tour editor
- [ ] `WalkthroughStepPanel` — title/body/task/placement/advanceOn/optional/
      allowManualNext/sinceVersion (default = current ApplicationVersion).
- [ ] `WalkthroughTourList` — add/rename/delete tours; drag-reorder steps.

## 5. Persistence + versioning
- [ ] Merge the authored `walkthrough` block into the app's manifest delta via the
      existing persistence path; assign to the chosen `ApplicationVersion`.
- [ ] Validate against the canonical v2 schema before save; block invalid, warn on
      brittle selector / missing sinceVersion.
- [ ] Live-preview reload so the tour runs immediately after save.

## 6. Setup-block reuse
- [ ] Alternate field set in `WalkthroughStepPanel` for `manifest.setup` steps;
      write to the `setup` block of the same delta.

## 7. Validate
- [ ] `openspec validate openbuild-walkthrough-editor --strict` passes.
