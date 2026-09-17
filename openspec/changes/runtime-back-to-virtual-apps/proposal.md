---
kind: code
---

## Why

The preview tutorial (`docs/tutorials/user/06-preview-app.md`, step 5) tells a builder to spot a bug in the running app, click **Back to virtual apps**, fix the page in the designer and preview again. The running app has no such link. A builder who opens a preview has to use the browser's back button or the Nextcloud app menu to get back to Buildiq, and the preview loop the tutorial describes breaks at its first step.

## What changes

- The running app (`/apps/buildiq/builder/<slug>`) shows a "Back to virtual apps" link at the top of its navigation. It opens Buildiq's app list (`/apps/buildiq/applications`). A page that declares its own primary action keeps that spot, and the link steps aside there.
- The link shows for the people who build the app: in a version preview (`?_version=` set, which only owners and editors can open) and for the app's owner on the production URL. Other users of a published app never see it, so they are not sent into a builder they cannot use.
- The tutorial text is updated to the link's label.

No stored data, routes or manifest keys change. The runtime host renders the link through the navigation's `primary-action` slot. It is never written into the manifest, so an in-app save cannot store it.

## Capabilities

### Modified capabilities

- `openbuild-runtime`: the standalone shell offers builders a way back to Buildiq's app list.

## Impact

- `src/builder.js`: renders the link at the top of the navigation, through CnAppRoot's `menu` slot and CnAppNav's `primary-action` slot.
- `src/components/runtime/BackToVirtualApps.vue`: the link.
- `docs/tutorials/user/06-preview-app.md`: label.
- Tests: a Vitest spec for the component and its visibility rule, and a Playwright check on the running app.
