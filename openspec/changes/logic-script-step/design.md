# Design: logic-script-step

Read at buildiq development `d21e42f`, openregister development `ae898b0`.

## Where it sits

- Composer: `src/dialogs/AutomationEditDialog.vue`, action kinds at lines
  568-584.
- Compiler: `lib/Service/AutomationCompilerService.php` with the flow backend of
  `logic-automation-actions-that-run` (D1, D2).
- Roles: `lib/Controller/AutomationsController.php` authorises compile, enable,
  disable and dry-run with `WRITE_ROLES` `['owners', 'editors']` (line 107) and
  `PRODUCTION_ENABLE_ROLES` `['owners']` (line 116) through
  `PermissionResolver::matchesCaller()` (line 438). Writes of the automation
  object go through `lib/Service/AutomationWriteService.php`, which authorises at
  line 370.
- Dry run: `AutomationsController::dryRun()` (line 314), REQ-AUTD-007.
- Admin settings: `lib/Settings/AdminSettings.php` (AppHost-backed, ADR-040),
  rendered by `src/views/settings/AdminRoot.vue`.
- Editor component: `CnJsonViewer` from `@conduction/nextcloud-vue` 2.57.1
  (`src/components/CnJsonViewer/CnJsonViewer.vue`), an editable CodeMirror
  editor when `readOnly` is false, with languages `json`, `xml`, `html` and
  `text`.
- Runtime: none in buildiq. OpenRegister's `FlowExpression` refuses code by
  decision and points at the sidecar of openregister issue #2066.

## D1. The step

A new action kind `script-step` with `code` (the body of a function
`(items) => items`), `timeoutSeconds` (1 to 30, default 10) and a `note` saying
what it does. The contract is the runner's: items in, items out, each item
`{json, binary}`. `MATRIX` allows it on every trigger the flow backend compiles.

## D2. What it compiles to

The flow backend emits OpenRegister's code step with the code and the timeout.
The node id and config keys are OpenRegister's to name in #2066; buildiq reads
them from the node catalogue entry at compile time and refuses to compile when
the catalogue has no code step, naming the reason.

## D3. Who may write a script

Adding, changing or removing a script step needs the `owners` role on the app,
with `allowAdminBypass: false`, in both `AutomationWriteService` and the compile
route. An editor may see the step and its code but gets "Only app owners can
change a script step." An instance setting "Allow script steps", off by default,
gates the kind entirely; an administrator turns it on in buildiq's admin
settings. Turning it off disables every automation holding a script step, with
the reason recorded.

## D4. Where the code runs, and what it can reach

It runs only in OpenRegister's code runner, a separate container with no
Nextcloud, no database, no filesystem and no network unless declared (#2066).
Buildiq declares no egress. Buildiq never evaluates the code: not on save, not
in the dry run, not in the browser.

## D5. Editing and testing

The composer shows `CnJsonViewer` in `text` mode for the code, a sample items
box (JSON), and "Test script". The test asks OpenRegister to run the code step
alone on the sample items (OpenRegister's single node run, `or-flow-run-node`)
and shows the returned items or the error. Nothing is written. The dry run of
REQ-AUTD-007 lists the script step as "runs in the code runner" and does not
execute it.

## D6. What the log keeps

The step's code and its items in and out are recorded in OpenRegister's run
trace, as for every step. Buildiq adds the automation's name and the author of
the last code change to the automation's provenance, so a run can be traced to a
person.

## Risks

- Maker code can be slow or wrong. The runner's timeout and memory cap bound it,
  and a failure goes to the step's `onError` policy.
- Items can hold personal data. They leave the Nextcloud process for the runner
  container only, which has no egress by default.
- The whole step depends on #2066. Until the runner exists, the kind is hidden
  and nothing compiles.

## What it does not do

- It does not run code in PHP, in the Nextcloud process, or in the browser.
- It does not offer Bash or other languages.
- It does not add a flow node to buildiq.
