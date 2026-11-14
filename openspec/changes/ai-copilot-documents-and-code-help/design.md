# Design: ai-copilot-documents-and-code-help

Read at buildiq development `d21e42f`.

## Where it sits

- Language model call: `lib/Service/CopilotService.php` runs every copilot
  prompt as a `TextToText` task through `OCP\TaskProcessing\IManager`
  (`resolveTaskProcessingManager()` at line 1256, `runTextToTextTask()` at line
  1398). Availability is `health()` at line 198, exposed as
  `GET /api/copilot/health` (`appinfo/routes.php:319`).
- Plan request: `POST /api/copilot/plan` (`appinfo/routes.php:320`) reaches
  `CopilotController::plan()` (`lib/Controller/CopilotController.php:121`,
  `#[NoAdminRequired]`, `#[UserRateLimit(limit: 20, period: 3600)]`), then
  `CopilotService::plan()` (line 249). The brief is refused outside 1 to 2000
  characters by `assertValidBrief()` (lines 1023-1032). Write access to a target
  app is `assertWriteRoleOnApp()` (line 1176).
- Prompt and validation: `lib/Service/Copilot/CopilotPromptBuilder.php`
  (`build()` at line 73, `buildRepairPrompt()` at line 100) and
  `lib/Service/Copilot/CopilotPlanValidator.php` (`validate()` at line 55).
- Tools a plan may call: `lib/Mcp/BuildiqToolProvider.php`. `buildiq.createApp`
  (lines 110-126) takes `slug`, `name`, `description` and `preset`.
- Wizard dialog: `src/dialogs/CopilotGenerateDialog.vue`. The brief is an
  `NcTextArea` (lines 30-40); the review groups steps as schemas, pages and menu
  items (lines 63-97). State lives in `src/composables/useCopilot.js`
  (`generatePlan()` at line 145); HTTP calls in `src/services/copilot.js`
  (`requestPlan()` at line 71).
- Expression inputs:
  - condition of a condition-action rule: `NcTextArea` "Condition (FEEL)" in
    `src/dialogs/ConditionActionRuleEditor.vue:36-39`;
  - decision table rows: raw `<input>` cells in
    `src/dialogs/DecisionTableEditor.vue:68-82`, checked by
    `isCellConditionValid()` (`src/utils/feelCell.js:22`);
  - record step field mapping: `NcTextArea` "Field mapping (JSON)" in
    `src/dialogs/AutomationEditDialog.vue:225-230`;
  - webhook payload template: `NcTextArea` "Payload template (JSON)" in
    `src/dialogs/AutomationEditDialog.vue:240-245`.
- FEEL grammar on the server: `lib/Service/FeelParser.php`, `parse()` at line
  119. The header (lines 5-18) lists the subset and forbids function calls.

## D1. One assist endpoint, four targets

`POST /api/copilot/assist` in `CopilotController`, `#[NoAdminRequired]` and
`#[UserRateLimit(limit: 60, period: 3600)]`. Body:
`{target, mode, request, current, appSlug, context}`.

- `target`: `feel-condition`, `decision-rule`, `field-mapping` or
  `payload-template`.
- `mode`: `write` (turn `request` into a value) or `explain` (describe
  `current` in plain words).
- `context`: names only. The rule set's input column names and payload paths,
  the target schema slug, the trigger schema slug. Never record values.

The route performs zero writes. It is registered next to the other copilot
routes, before the SPA catch-all.

## D2. The server validates before it answers

A new `lib/Service/Copilot/CopilotAssistService.php` builds a target-specific
prompt, runs it, and checks the answer:

- `feel-condition`: `FeelParser::parse()` must accept it.
- `decision-rule`: an object `{conditions, decision}` whose keys are the table's
  input column names, and each cell passes a PHP mirror of
  `isCellConditionValid()`. The mirror and the JavaScript check share one fixture
  table so they cannot drift.
- `field-mapping`: valid JSON whose keys are properties of the target schema.
- `payload-template`: valid JSON.

A failed check gets one repair round-trip, as the plan does (REQ-OBAIC-002).
A second failure answers 422 `assist_invalid` with the checker's message. The
maker never sees a value the checker refused.

## D3. One text task seam for both services

`resolveTaskProcessingManager()` and `runTextToTextTask()` move out of
`CopilotService` into `lib/Service/Copilot/CopilotTextTask.php`, which
`CopilotService` and `CopilotAssistService` both call. Behaviour, timeout
(`LLM_TIMEOUT_SECONDS`, line 98) and error envelopes stay as they are. This is
the only refactor, and it keeps the language model path in one place.

## D4. Scope comes from the server

`appSlug` is required. The service loads the app and runs the same
`assertWriteRoleOnApp()` check as the plan, so only an owner or editor gets
help. The target schema for `field-mapping` resolves by slug among that app's
own schemas; a slug outside the app is refused, so the assist cannot read another
app's property names.

## D5. Accept or reject in a dialog

`src/components/copilot/CopilotAssistButton.vue` renders an icon button with
the accessible name "Ask AI". It renders nothing while `useCopilot()` reports
the copilot unavailable, the same probe the wizard uses. It opens
`src/dialogs/CopilotAssistDialog.vue` (an `NcDialog`, per the modal isolation
gate): a request box, then the proposal with its explanation, and Accept or
Reject. Accept writes the value into the editor's staged state. The editor's
own Save still decides whether anything persists.

## D6. A document from Files, read as the maker

`CopilotGenerateDialog.vue` gains "Add a document", which opens the Nextcloud
file picker (`@nextcloud/dialogs` `getFilePickerBuilder`). The plan request
carries `documentFileId`. The server opens it through
`IRootFolder::getUserFolder($userId)->getById()`, so a file the maker cannot
read answers 404 and nothing else. Plain text and Markdown are read directly.
PDF, Word and OpenDocument text come from OpenRegister once it publishes a text
read (see Sibling halves); until then those types are refused with a message
that names the accepted types. Document text is capped at 60,000 characters and
a longer document is refused, never cut silently. The typed brief keeps its
2000 character limit.

## D7. Stories are part of the plan

The plan response gains `stories[]`, each `{id, role, goal, benefit,
servedBy[]}`. `servedBy` holds indexes into `steps[]`. `CopilotPlanValidator`
checks that every story has a role and a goal and that every index points at an
`upsertSchema` or `upsertPage` step. The review shows "User stories" first,
each with the schemas and pages that serve it. On execute the
`buildiq.createApp` step carries the stories, and the app keeps them in a new
`stories` property on `Application`, declared in a new fragment
`lib/Settings/register.d/81-application-stories.json`. A new
`src/components/applicationDetail/widgets/StoriesWidget.vue` lists them on the
app detail page.

## Risks

- A proposal can be valid and still wrong. The dialog says the maker checks it,
  and nothing saves without the editor's Save.
- Long documents make slow prompts. The 60,000 character cap and the existing
  120 second task budget bound it.
- The PHP mirror of the cell grammar can drift from `feelCell.js`. The shared
  fixture table in D2 is the guard.

## What it does not do

- It adds no AI help to the raw manifest editor.
- It reads no images.
- It runs no proposed expression against records.
- It calls no model vendor; the call stays a TaskProcessing task.
