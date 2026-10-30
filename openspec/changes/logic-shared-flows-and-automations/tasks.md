# Tasks: logic-shared-flows-and-automations

- [ ] **T01**: Add `runOn[]` to the flow binding in a new fragment `lib/Settings/register.d/82-flow-binding-run-on.json`, and `shared` to `Automation` (REQ-BQSF-001, REQ-BQSF-003). Verify: PHPUnit on the register import accepting both.
- [ ] **T02**: Add the "Run on" editor per bound flow to `src/modals/AppSettingsModal.vue`, listing the app version's schemas, saved through `setFlows()` in `ApplicationDetailActions.vue` (REQ-BQSF-001). Verify: vitest cases for the editor.
- [ ] **T03**: Save, bind and remove wrapper flows per `runOn` entry through `FlowService::save()` and `FlowService::delete()`, marking them with `sourceUuid`, and keep export from duplicating them (REQ-BQSF-002). Verify: PHPUnit `tests/Unit/Service/FlowBindingWrapperServiceTest.php` for create, idempotent re-save, remove, and an untouched bound flow; a case in `tests/Unit/Service/FlowAndAgentExportBundlerTest.php` for wrappers.
- [ ] **T04**: Enforce `shared` as owner-only, refuse sharing an automation that writes a schema of its app, and list shared automations in the flow picker (REQ-BQSF-003). Verify: PHPUnit cases in `tests/Unit/Service/AutomationWriteServiceTest.php`, and vitest for the picker group.
- [ ] **T05**: Add the `run-automation` action kind to the composer and compile it to `openregister.sub-flow` with `wait: true`, refusing a self reference (REQ-BQSF-004). Verify: PHPUnit cases in `tests/Unit/Service/AutomationCompilerServiceTest.php`.
- [ ] **T06**: Show `runOn` and the last run per bound flow in `src/components/applicationDetail/widgets/FlowsWidget.vue` (REQ-BQSF-005). Verify: a case in `tests/components/applicationDetail/widgets.spec.js`.
- [ ] **T07**: Playwright `tests/e2e/app-flow-bindings.spec.ts`: bind a flow with a "Run on" entry, update a record, see the run on the app detail page (REQ-BQSF-002, REQ-BQSF-005).
- [ ] **T08**: Strings and docs: English and Dutch for "Run on", "Shared automations", "Run another automation" and the refusals (`l10n/en.json`, `l10n/nl.json`), and a section in `docs/` on attaching flows and sharing automations.
- [ ] **T09**: Run `openspec validate logic-shared-flows-and-automations --strict`.
