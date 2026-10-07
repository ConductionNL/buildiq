---
kind: code
---

# Proposal: logic-approval-task-form

## Why

**buildiq matrix, row `logic-human-task-form`**, "Pause an automation until a
named person fills in a form or decides, then continue.", rated `partial`,
`built.state` `specified`.

The pause and the decision are built: an automation's "Require approval"
action compiles to an OpenRegister approval chain, and the "My approvals"
widget (`src/components/runtime/MyApprovalsWidget.vue`) approves or rejects
through `POST /api/flow-tasks/{uuid}/complete`. The form is not.

OpenRegister's archived change `2026-10-05-flow-task-forms` built the form
half of a task: `TaskFormResolver` resolves the form a task presents (for a
task without a flow run, from the task's own `metadata.form`), `GET
/api/flow-tasks/{uuid}` returns it with a state (`ready`, `broken`,
`unresolvable`), and `complete` accepts a `data` payload that
`TaskFormCompletion` validates against the declared fields and writes onto the
subject object. Its own tasks.md (5.2) records what is missing: no component
renders that form for a person.

buildiq has two gaps of its own on top of that:

1. A maker cannot say which fields the approver fills in. The approval action
   in `AutomationEditDialog.vue` (lines 259-281) has an assignee group and
   the approve and reject follow-ups, nothing else.
2. "My approvals" sends `outcome` and a fixed comment and never a form.

## What changes

- The "Require approval" action in the automation editor gets an optional
  list "Fields the approver fills in": fields of the trigger's schema, each
  with a "Required" switch, in the order the maker sets.
- The compiler carries that list into the approval chain as a form
  declaration `{kind: "fields", fields: [{field, required}]}`, so every
  approval task opened for the automation has it in `metadata.form`. A field
  that is read-only, hidden or no longer in the schema is refused when the
  automation is saved, naming the field.
- In "My approvals", "Approve" on a task with a form opens the nextcloud-vue
  task form component instead of completing at once. It completes with
  `outcome: approved` and the typed values in `data`. "Reject" stays as it is.
- A task whose form is `broken` or `unresolvable` shows the reason on its row
  and disables "Approve"; it does not complete with an empty form.

## Rows covered

- `logic-human-task-form` (buildiq's half)

Delivered before this change: `openregister/flow-task-forms` (form resolution
and completion).

## Depends on, cross-repo

- **nextcloud-vue**: a task form component that takes the `GET
  /api/flow-tasks/{uuid}` answer, renders the declared fields through
  `CnFormDialog`'s `includeFields`, shows broken fields as disabled rows with
  their reason, and keeps the dialog open with the typed values on a 400 that
  names fields. This is the component flow-task-forms 5.2 names.
- **openregister**: the approval-chain declaration
  (`ApprovalChainAnnotationInstaller`, positions `{order, role}`) has no form
  key, and `TaskSequenceService` opens tasks without `metadata.form`. A chain
  needs to carry a form declaration into each task it opens.

Tasks T01 and T02 can be built now. T03 waits on openregister, T04 on
nextcloud-vue.
