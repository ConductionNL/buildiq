---
kind: code
---

# Proposal: apps-support-note-editor-saves

## Why

**buildiq matrix, row `app-support-note`**, "Write the support and donation note
a user sees the first time an app opens.", rated `partial`, `built.state`
`specified`. Drawn on board **BqSupportBewerken** (canvas part 2,
`QAAxpcsFKBCvDUGbQbwtCa`). Added by decision 99 (8 Oct).

The editor is there. The app detail page has "Support & donation" in its
actions menu (`src/components/ApplicationDetailActions.vue:369-375`), which
loads the app's manifest and opens the shared `CnEditSupportModal` on a working
copy (`ApplicationDetailActions.vue:803-818`). Every field the board draws is
in that dialog: show on first open, title, body, signatory name and role,
avatar URL with upload and reset, avatar link, and per button show, label,
link, style and icon.

The edits are not saved. `CnEditSupportModal` saves through
`manifestModalDoneMixin.onDone()`, which calls the `cnManifestEditor` that
`CnAppRoot` injects. On the app detail page that is buildiq's own shell
(`src/App.vue`), which passes no `persistManifestDelta`, so the save is a
no-op on buildiq's own manifest. The dialog then emits `close`, and
`onSupportClose()` drops the working copy
(`ApplicationDetailActions.vue:828-831`). Nothing calls
`PUT /api/applications/{slug}/manifest` (`appinfo/routes.php:65`). A maker
fills in the note, clicks Done, and the note is gone.

The runtime side works: a virtual app shows the note only when
`manifest.support.enabled === true` (`src/utils/virtualAppSupportDialog.js:22-26`,
wired at `src/builder.js:394`).

## What changes

- Done in the support editor on the app detail page saves the working copy's
  `support` block to the app's manifest with `PUT /api/applications/{slug}/manifest`.
- A failed save keeps the dialog open with the error and keeps the working copy.
- Cancel (closing the dialog without Done) drops the working copy, as today.
- The action stays behind `canEditVersions`, as today.

## Rows covered

- `app-support-note`

## Out of scope

- The note's content defaults and its look at runtime: owned by
  nextcloud-vue's `CnSupportDialog` (`nextcloud-vue/add-cn-support-dialog`).
- Showing the note for a hybrid app. A hybrid app's note is the installed
  app's own `CnSupportDialog`; whether it reads the admin layer's `support`
  block is a question for that app, not for this editor.
