# Design: logic-approval-task-form

## Boards

- **BqAutomatiseringBewerken** draws "Actie 1: goedkeuring vragen" with "Soort
  actie", "Groep die goedkeurt", "Bij goedkeuren", "Bij afwijzen". The fields
  list is not drawn. It goes directly under "Groep die goedkeurt", as the
  label "Velden die de goedkeurder invult (optioneel)", a field picker, and per
  chosen field a row with the field name, a "Verplicht" switch, move up and
  move down, and remove. Below it a grey line: "Leeg laten: de goedkeurder
  kiest alleen goedkeuren of afwijzen."
- **BqMijnGoedkeuringen** draws "My approvals" as rows with a title, a line
  "Approval step 1 of 1 · requested by ... · group ...", a deadline flag, and
  "Reject | Approve". The board draws no form. This change keeps the row
  exactly as drawn; "Approve" on a row with a form opens the form dialog. A
  row whose task has a form gets one extra line, "2 fields to fill in", so the
  approver knows before clicking.
- **BqAutomatiseringTesten** draws "Goedkeuringsstatus: in afwachting,
  goedgekeurd of afgewezen". The dry run lists the declared fields under the
  approval step ("vraagt 2 velden: bedrag, motivering"); nothing else changes.

## D1. The declaration lives on the automation action

`actions[].form` on the `automation` object:
`{fields: [{field: "bedrag", required: true}, {field: "motivering", required: false}]}`.
The automation object stays the decompiled source of truth (design D1 of
automation-designer). An empty list or absent `form` means no form, and the
approval completes exactly as today.

## D2. Checked when the automation is saved

The compiler's validation (`AutomationCompilerService::compile`) refuses,
naming the field:

- a field not in the trigger schema's properties;
- a field the schema marks `readOnly`, or hides (`visible: false`);
- a relation or array field (v1 renders scalar fields only, mirroring what
  `CnFormDialog` renders from `fieldsFromSchema`);
- a form on a trigger without a subject object (`schedule`, `manual` are
  already blocked for `approval`).

This mirrors flow-task-forms' "A field that cannot be rendered is refused when
the step is saved". A field that becomes unrenderable later is OpenRegister's
`broken` state, shown at completion time (D4).

## D3. Compiled into the approval chain

`applyApprovalChain()` writes the declaration as
`form: {kind: "fields", fields: [...]}` on the chain's single position. The
compiled hash includes it, so changing the fields shows as drift until
recompiled. Open tasks keep the form they were opened with: OpenRegister
copies it into `metadata.form` at task creation, and a run-less task's record
is its declaration.

This needs OpenRegister to accept `form` on a chain position and copy it into
the task it opens (cross-repo). Until it does, the compiler refuses a non-empty
`form` with "Approval forms need OpenRegister to carry a form into the task",
so a maker never saves a form that silently does nothing.

## D4. Completion in "My approvals"

For each pending task the widget already loads, it reads `GET
/apps/openregister/api/flow-tasks/{uuid}` when the inbox row says the task has
a form, and:

| form state | row | Approve |
| --- | --- | --- |
| no form | as today | completes with `outcome: approved` |
| `ready` | extra line "{n} fields to fill in" | opens the nextcloud-vue task form component; its confirm completes with `outcome: approved` and `data` |
| `broken` | the reason, for example "Field bedrag no longer exists" | disabled |
| `unresolvable` | the reason OpenRegister gives | disabled |

A 400 naming fields keeps the dialog open with the typed values (the
component's job). "Reject" never sends `data`; the form is for approving.
Nothing in buildiq validates the values: OpenRegister's allowlist and the
subject schema do (ADR-022, and the "no new approval authorization logic"
requirement of automation-approval-action).

## D5. What the follow-up actions see

`ApprovalOutcomeListener` runs `onApprove` after the task completes, and
`TaskFormCompletion` writes the values onto the subject before completing. So
an `onApprove` document action renders the object with the approver's values
already on it. No extra wiring.
