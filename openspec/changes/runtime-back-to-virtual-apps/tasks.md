## 1. Link

- [ ] 1.1 Add `src/components/runtime/BackToVirtualApps.vue`: a link labelled "Back to virtual apps" to `generateUrl('/apps/buildiq/applications')`, plus an exported `showBackToVirtualApps({ versionSlug, manifest })` that answers true for a version preview or when `manifest.runtime.user.isOwner` is true, and `declaresPrimaryAction(manifest, routeName)`.
- [ ] 1.2 In `src/builder.js`, when `showBackToVirtualApps()` answers true, render CnAppNav through CnAppRoot's `menu` slot with the link in its `primary-action` slot, unless the page on screen declares a primary action. Nothing is added to the manifest.

## 2. Docs

- [ ] 2.1 Update step 5 of `docs/tutorials/user/06-preview-app.md` to the link's label.

## 3. Tests

- [ ] 3.1 Vitest: the link's target and label, and the visibility rule (preview, owner, other user).
- [ ] 3.2 Playwright: a version preview of an app shows the link, and it points at Buildiq's app list.

## Acceptance criteria

- A builder in a version preview can go back to Buildiq's app list in one click.
- A user who is not the owner, on the production URL, sees no link.
- Saving an in-app edit never stores the link in the manifest.
