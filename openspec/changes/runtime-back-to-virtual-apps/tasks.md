## 1. Link

- [x] 1.1 Add `src/components/runtime/BackToVirtualApps.vue`: a link labelled "Back to virtual apps" to `generateUrl('/apps/buildiq/applications')`, plus an exported `showBackToVirtualApps({ versionSlug, manifest })` that answers true for a version preview or when `manifest.runtime.user.isOwner` is true, and `declaresPrimaryAction(manifest, routeName)`.
  - Built in #811: `src/components/runtime/BackToVirtualApps.vue`.
- [x] 1.2 In `src/builder.js`, when `showBackToVirtualApps()` answers true, render CnAppNav through CnAppRoot's `menu` slot with the link in its `primary-action` slot, unless the page on screen declares a primary action. Nothing is added to the manifest.
  - Built in #811: `src/builder.js` renders the link in the navigation's primary-action slot; nothing is added to the manifest.

## 2. Docs

- [x] 2.1 Update step 5 of `docs/tutorials/user/06-preview-app.md` to the link's label.
  - Step 6 of the tutorial (the step that leaves the preview) names the link by its label; the Dutch string is added.

## 3. Tests

- [x] 3.1 Vitest: the link's target and label, and the visibility rule (preview, owner, other user).
  - `tests/components/runtime/BackToVirtualApps.spec.js` and `BackToVirtualApps.edges.spec.js`.
- [x] 3.2 Playwright: a version preview of an app shows the link, and it points at Buildiq's app list.
  - `tests/e2e/runtime-back-to-virtual-apps.spec.ts`.

## Acceptance criteria

- A builder in a version preview can go back to Buildiq's app list in one click.
- A user who is not the owner, on the production URL, sees no link.
- Saving an in-app edit never stores the link in the manifest.
