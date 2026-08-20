## 1. Confirm the shipped overload's exact contract

- [x] 1.1 Re-confirm `useAppManifest(appIdOrOptions, bundledManifest,
      options = {})` in the installed `@conduction/nextcloud-vue` and read
      its in-memory-manifest code path (does it accept a plain manifest
      object, or does it require a ref/reactive wrapper?) before wiring
      `CnAppRoot`.
- [x] 1.2 Confirm `CnAppRoot`'s prop contract for an in-memory (non-fetched)
      manifest mount — whether it accepts `:manifest` directly (as
      `App.vue`'s production mount already does) or needs an
      `appId`-scoped `useAppManifest` call internally.

## 2. Implement the preview branch

- [x] 2.1 In `src/views/PageDesigner.vue`'s right-hand `<aside>`, add a
      `v-else` (or sibling `v-if="previewAvailable"`) block next to the
      existing fallback `<div v-if="!previewAvailable" ...>` (line 74).
- [x] 2.2 Mount `CnAppRoot` in that block with the props returned by
      `previewProps(slug, manifest)`: `appId: openbuild-preview-{slug}`,
      the in-flight `manifest` object, keyed by `previewProps(...).key`
      (the content hash) so edits force a clean re-mount per REQ-OBPD-008.
- [x] 2.3 Pass the same `registry` / `pageTypes` / `custom-components`
      props the production `App.vue` mount uses, so custom-page components
      resolve identically in the preview sandbox (closes the
      `CustomPageEditor.vue` / REQ-OBPD-007 registry dependency).
- [x] 2.4 Ensure the sandboxed mount never issues a network write — verify
      no PUT/save call path is reachable from the preview's `CnAppRoot`
      instance (REQ-OBPD-008's "no PUT request is sent to OR" scenario).
- [x] 2.5 Remove or update the stale `<!-- TODO(chain-spec-2): live preview
      pane requires in-memory useAppManifest -->` comment (line 73).

## 3. Regression / fallback path

- [x] 3.1 Verify the existing `v-if="!previewAvailable"` fallback (the
      "Save & open preview" button) still renders correctly if
      `previewAvailable` is forced `false` (e.g. a unit test that stubs
      `useLivePreview` to return `available: false`).
- [x] 3.2 Vitest: mount `PageDesigner.vue` with `useLivePreview` mocked to
      `available: true` and assert the sandboxed `CnAppRoot` renders with
      the expected `appId`/`manifest`/`key` props.
- [x] 3.3 Vitest: mount with `available: false` and assert the fallback
      message + "Save & open preview" button render instead (existing
      Playwright-covered scenario, per REQ-OBPD-008's `@e2e exclude` note
      — keep the Vitest coverage current).

## 4. Verify

- [ ] 4.1 Manual: edit a page's `title` field in the Page Designer and
      confirm the right-hand preview re-renders the new title live, with
      no network PUT in the browser devtools Network tab. DEFERRED — needs a
      live instance with the arity-2 useAppManifest lib installed; the
      installed vitest stub ships arity-1. Covered structurally by the new
      Vitest preview spec (available branch + no-write invariant).
- [x] 4.2 `npm run lint`, `npm run build`, and `npm test` all pass.
- [ ] 4.3 Confirm REQ-OBPD-007's registry-backed component picker in
      `CustomPageEditor.vue` now lists real component keys when the
      preview pane is active. DEFERRED — same live-instance dependency; the
      registry is now wired into the sandbox (previewRegistry/previewFlatRegistry),
      so the live source exists, but confirming the picker's runtime read needs
      a running instance.
