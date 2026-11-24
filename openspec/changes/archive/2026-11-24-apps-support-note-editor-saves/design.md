# Design: apps-support-note-editor-saves

## Board

**BqSupportBewerken** on canvas part 2 (`QAAxpcsFKBCvDUGbQbwtCa`) draws the
dialog "Support en donatie bewerken" over the app detail page of a hybrid app
(Pipelinq). Top to bottom: the intro line, the switch "De supportnotitie tonen
bij de eerste keer openen", title, body ("een alinea per regel"), the
signature block (name, role, avatar URL with "Afbeelding uploaden" and
"Standaard herstellen", avatar link), then "Knoppen" with three buttons
(Doneren, Functie voorstellen, Hulp vragen), each with a show switch, label,
link URL, style and icon. One button at the bottom: "Klaar".

The live dialog already matches the board field for field. This change only
makes "Klaar" do what the board implies: the note is saved.

## D1. Save in buildiq, not in the shared dialog

`CnEditSupportModal` is written for the in-app edit shell, where `CnAppRoot`
owns the manifest and its `persistManifestDelta` writes it (`src/builder.js:418`
does that for a running virtual app). On buildiq's app detail page the manifest
belongs to another app, so buildiq saves it. `ApplicationDetailActions` passes
its own `useManifestEditor`-shaped object to the dialog through `provide`
under `cnManifestEditor`, scoped to the dialog, whose `save()` does the PUT.
That way the shared dialog stays unchanged and its Saving state and Done
button keep working.

If providing a scoped editor turns out to be awkward in Vue 2 (provide is
resolved at the dialog's creation), the fallback is a thin wrapper component
`src/modals/SupportEditorModal.vue` that provides it and renders the
library dialog. Either way the request is the same.

## D2. What is saved

The PUT body is the full working manifest, as `builder.js` already sends for
in-app edits: `{ manifest }`. Only the `support` block differs from what was
loaded. The save goes to the version the detail page resolves (the same
`/api/applications/{slug}/manifest` the editor loaded from), so it lands in
the draft or the admin layer the existing endpoint writes to.

## D3. Failure

On a non-2xx answer the dialog stays open, the working copy stays, and the
page's existing error line shows "Could not save the support note: {reason}".
The dialog's own `saving` flag is reset by the mixin's `finally`, so the
maker can retry.

## Risks

- `saveManifest` may reject a manifest whose other parts changed on the
  server between load and save. The endpoint's existing conflict behaviour
  applies; this change does not add merging.
