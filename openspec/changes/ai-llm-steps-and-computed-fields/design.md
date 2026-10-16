# Design: ai-llm-steps-and-computed-fields

Read at buildiq development `d21e42f`.

## Where it sits

- Composer: `src/dialogs/AutomationEditDialog.vue`. Triggers at lines 484-495,
  action kinds at lines 568-584 (`send-notification`, `run-synchronization`,
  `object-op`, `webhook`, `approval`, `generateDocument`). Per-kind forms sit in
  the template around lines 195-250.
- Compiler: `lib/Service/AutomationCompilerService.php`. The trigger to action
  matrix is `MATRIX` (lines 149-156); `compile()` at line 296, `apply()` at line
  355, `remove()` at line 440, `status()` at line 480. Compiled artefacts carry
  an `aut-` prefix and are listed in the automation's `provenance`.
- Automation schema: `lib/Settings/register.d/40-automations.json`, `Automation`
  `1.0.0` with `trigger` (line 71), `condition` (line 114), `actions` (line 140)
  and `provenance` (line 287).
- Schema designer: `src/views/SchemaDesigner.vue` mounts the field list and,
  under it, `AggregationEditor`, `CalculationEditor` and `NotificationEditor`
  (lines 161-163). Field types are `SUPPORTED_TYPES` in
  `src/components/schema-editor/FieldEditor.vue:319-327`.
- Flow backend: specified by `logic-automation-actions-that-run` D1 to D3. It
  compiles an automation to one OpenRegister flow named `aut-<slug>`, binds it in
  `Application.flows`, and enables or disables it with the automation.
- Node catalogue: `@conduction/nextcloud-vue` 2.57.1 `useFlowStore.js`
  `loadNodeCatalog()` (line 889) reads
  `GET /apps/openregister/api/flow/node-catalog`
  (openregister `appinfo/routes.php:789`).

## D1. One action kind, five presets

A new action kind `ai-step` with `preset` one of `classify`, `summarise`,
`extract`, `translate`, `prompt`, and:

- `agent`: the uuid of a Hermiq agent, picked from the `agent` objects in the
  `hermiq` register through OpenRegister's REST surface (the register and schema
  `lib/Service/FlowAndAgentExportBundler.php` already reads as a fallback);
- `sourceFields[]`: the record fields the model reads, at least one;
- `targetField`: the record field the answer goes to;
- `labels[]` for `classify`, `fields[]` for `extract`, `language` for
  `translate`, `prompt` for `prompt`.

`MATRIX` allows `ai-step` on `object-created` and `manual`. `object-updated`
waits for OpenRegister's changed-fields trigger (see proposal).

## D2. What it compiles to

Through the flow backend, an automation with an `ai-step` becomes:

1. the trigger node the backend maps the trigger to;
2. `hermiq.agent-step` with `agentId`, a prompt rendered by buildiq from the
   preset (a fixed English instruction plus `{{field}}` placeholders for the
   source fields only), `output` set to a private key `aiAnswer`, and
   `expectJson` for `classify` and `extract`;
3. `openregister.object-write` in update mode on the triggering record, writing
   `targetField` (or the extracted fields) from `aiAnswer`.

The prompt text is built from the preset in `AutomationCompilerService`, not
typed into the flow by hand, so a recompile gives the same flow.

## D3. Only what the maker named goes to the model

The rendered prompt holds the placeholders of `sourceFields[]` and nothing else.
The whole record is never sent. The composer lists the fields the step will send
before the maker saves.

## D4. A classification can only land on a label

For `classify` the compiler requires `targetField` to be an `enum` whose values
are exactly `labels[]`, and refuses the automation otherwise. A model answer
outside the labels then fails OpenRegister's schema validation in the write
step, the run log records the failure, and the record keeps its old value.

## D5. An AI field is an automation the schema designer writes

A new `src/components/schema-editor/AiFieldEditor.vue`, mounted in
`SchemaDesigner.vue` next to `CalculationEditor`, lists the schema's AI fields.
Adding one declares the property on the schema with `readOnly: true` and a
description saying the AI fills it, and saves an `Automation` with
`origin: ai-field`, trigger `object-created`, and one `ai-step` action. The
operations are the five presets plus `sentiment` (a `classify` with the labels
`positive`, `neutral`, `negative`) and `clean` (a `prompt` preset with a fixed
instruction). The automation list hides `origin: ai-field` rows; the schema
designer is where they are edited. Removing the field removes its automation
and, through provenance, its flow.

## D6. Offered only when the node exists

The composer and the AI field editor read the node catalogue once. Without
`hermiq.agent-step` in it, the "Ask AI" kind and the AI field button are
disabled with the reason "Install Hermiq to use AI steps.", the same posture
`generateDocument` takes when the document app is absent. The compiler checks
again at apply time and fails closed.

## Risks

- Every run is a model call and costs time. Runs are asynchronous flow runs, off
  the save that fired them.
- An agent with tools could do more than answer. The composer lists only Hermiq
  agents without enabled tools for this step, and says so.
- Until OpenRegister ships the changed-fields trigger, an AI field is filled
  once, at creation, and does not follow later edits. The field editor says so.

## What it does not do

- It adds no node to any engine.
- It does not call a model from buildiq for these steps; Hermiq does.
- It does not recompute existing records when an AI field is added.
