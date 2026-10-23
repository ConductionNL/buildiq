---
kind: code
---

# Proposal: pages-action-buttons

## Why

buildiq matrix, row `pg-actions-buttons` ("Add buttons to a page that run an
action, such as changing a status or starting a flow", `partial`):
"src/components/page-editor/IndexPageEditor.vue:77-79 authors config.actions
via src/components/page-editor/fields/ActionBuilder.vue, which authors {id,
label, icon, target} per action with target restricted to a 3-option <select>:
navigate | emit | none ... DetailPageEditor.vue has no
actions/headerActions/lifecycleActions fields at all".

All five competitors rate it yes, quoted from the matrix:

- NocoBase: "UpdateRecordActionModel.tsx:74 update-record button that assigns
  field values (for example a status); packages/plugins/@nocobase/plugin-workflow-custom-action-trigger
  ... adds a button that starts a workflow".
- Budibase: "ButtonActionEditor/manifest.json:4-188 lists 29 button actions,
  among them Save Row, Update Field Value, Trigger Automation, Row Action, Execute
  Query and Navigate To".
- Appsmith: "ButtonWidget/widget/index.tsx:170 onClick with controlType
  ACTION_SELECTOR ... AppsmithFunction list (run query, navigate, show modal,
  alert, store value, download)".
- Mendix: "widgets trigger microflows and nanoflows"
  (https://docs.mendix.com/refguide/common-widget-properties/).
- Power Apps: "create and edit modern commands in the command bar with the
  command designer and Power Fx; canvas buttons run formulas in OnSelect"
  (https://learn.microsoft.com/en-us/power-apps/maker/model-driven-apps/use-command-designer).

No tender, featureRequest or roadmap row carries it. The renderer already does
all of it: nextcloud-vue 2.57.1's manifest `action` has twelve typed behaviours,
among them `object-op` (patch a record) and `run-node` (run an OpenRegister flow
node), and `CnDetailPage` renders `headerActions` and status transitions
(`lifecycleActions`). Buildiq's editor authors none of them, and the
`target: navigate` it writes is not what the schema's `navigate` means.

## What changes

- The action editor becomes typed: a maker picks what the button does (change
  fields on this record, move it to another status, start a flow, open a form,
  go to a page, open a link, export) and fills in only what that type needs.
- The detail page editor gets a "Buttons" section authoring `headerActions` and
  a "Status buttons" section authoring `lifecycleActions`.
- An action can be shown only when a condition on the record holds
  (`visibleWhen`), with the predicate editor the form editor already uses.
- Existing `{target: navigate | emit | none}` actions are read and rewritten to
  their typed form on save, so no saved app breaks.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| buildiq | pg-actions-buttons | Add buttons to a page that run an action, such as changing a status or starting a flow. | partial | typed actions (status, fields, flow, form, export) and any action on a detail page |

## Existing work it builds on

- `openspec/specs/openbuild-page-designer/spec.md` REQ-OBPD-004: the actions
  authoring this change replaces.
- `openspec/specs/form-editor-logic/spec.md`: the `visibleWhen` predicate
  builder (`VisibleWhenBuilder.vue`).
- `openspec/specs/procest-workflow-attachments/spec.md` and the app's declared
  `flows` (`AppSettingsModal.vue:105`): the flows a "start a flow" button can
  pick from.

## Sibling halves

None. nextcloud-vue 2.57.1 renders every action type this change authors, and
OpenRegister serves the flow nodes and the available transitions.

## Out of scope

- Custom JavaScript on a button (`pages-custom-code`).
- Automations triggered by a button that are not flows; a manual-trigger
  automation is `logic-automation-actions-that-run`.
