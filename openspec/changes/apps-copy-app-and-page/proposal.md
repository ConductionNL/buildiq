---
kind: code
---

# Proposal: apps-copy-app-and-page

## Why

A maker who wants a variant of something they already built has to build it
again, or detour through a template.

- buildiq matrix, row `app-duplicate` ("Copy an existing app to start a variant
  of it", `partial`): "No copy-app action on the app list or detail page (grep
  -iE 'duplicat|clone app' over src/components/ApplicationDetailActions.vue and
  VirtualAppsActions.vue: no app copy); an app can be saved as a template
  (src/dialogs/SaveAsTemplateDialog.vue) and a new app created from it".
- buildiq matrix, row `form-copy` ("Copy an existing form as the start of a
  similar one", `partial`): "No copy action on a page or registration form
  (src/components/page-editor/PageListEditor.vue:135-143 only validates
  duplicate ids). A configured widget can be saved as a component block and
  inserted again ... and a whole app can be saved as a template".

Demand. `form-copy` carries a tender row: TenderNed 382064
(https://www.tenderned.nl/aankondigingen/overzicht/382064). Competitors, quoted
from the matrix:

- `app-duplicate`, three yes. Budibase: "DuplicateWorkspaceModal opened from the
  workspace context menu". Appsmith: "ForkApplicationModal.tsx:1 fork to a
  chosen workspace, opened from the app card". Power Apps: "Save as duplicates
  the app by saving it with a different name"
  (https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/power-apps-studio).
- `form-copy`, four yes. Budibase: "'Duplicate' screen with a new route ... so a
  form screen is copied as the start of another". Appsmith: "clonePageInit
  duplicates a page with its form". Mendix: "documents such as pages can be
  duplicated in the App Explorer and edited as the start of a similar form"
  (https://docs.mendix.com/refguide/page/). Power Apps: "model-driven main forms
  can also be saved as a copy"
  (https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/power-apps-studio).

`app-duplicate` also sits in buildiq's core area (apps).

## What changes

- "Copy app" on the app detail page and on each card in the app list. It asks
  for a name and slug and creates a new app with its own register, a copy of
  the source app's schemas and a copy of its current manifest. Records are not
  copied.
- "Copy page" on each row of the page list: a new page with the same type and
  configuration, a new id, title and route, inserted below the source.
- "Copy form" on each registration form: a new draft form with the same fields,
  steps, rules and presets, bound to the same type value, never the default.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| buildiq | app-duplicate | Copy an existing app to start a variant of it. | partial | a copy action without going through a template |
| buildiq | form-copy | Copy an existing form as the start of a similar one. | partial | a copy action on a page and on a registration form |

## Existing work it builds on

- `openspec/specs/openbuild-template-catalogue/spec.md` (REQ-OBTC-004 and
  REQ-OBTC-005): clone from template, the per-app register and the companion
  schema namespacing, implemented by `installFromTemplateArray()`.
- `openspec/specs/save-as-template/spec.md` (REQ-SAT-002, REQ-SAT-004): the
  capture and de-namespace logic in `src/services/templateCapture.js`.
- `registration-form-builder` and `forms-per-case-type` (open): the
  `registrationForm` object, its default rule and `saveRegistrationForm()`.

## Sibling halves

None.

## Out of scope

- Copying an app's records. A variant starts empty, as an app from a template
  does.
- Copying between Nextcloud instances; that is `lifecycle-import-app-and-cli`.
- Copying a single widget; component blocks already cover it.
