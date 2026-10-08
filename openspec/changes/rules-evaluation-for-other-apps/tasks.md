# Tasks: rules-evaluation-for-other-apps

- [ ] **T01**: Add `callableBy` (array of app ids, default empty) to `rule-set` and `callerApp` to `rule-execution-log` in `lib/Settings/register.d/10-business-rules.json` (REQ-BQRX-001). Verify: re-import shows no `PARTIAL IMPORT` line; PHPUnit asserting the properties exist with titles.
- [ ] **T02**: Add `RuleEngineService::resolveForApp()` per design D1, reading with `_rbac: false` and checking `callableBy` (REQ-BQRX-002). Verify: PHPUnit for an allowed app, an app not listed, an inactive rule set, and that no write uses `_rbac: false`.
- [ ] **T03**: Add `lib/Event/RuleEvaluationRequestedEvent.php` and `lib/Listener/RuleEvaluationListener.php` with the steps of design D2, registered in `Application::register()` (REQ-BQRX-002). Verify: PHPUnit for each refusal, a suppressed notification action, one log line with `callerApp`; wiring asserted from `Application::register()`.
- [ ] **T04**: Add the `callableBy` filter to `RulesController` list per design D3 (REQ-BQRX-001). Verify: PHPUnit asserting the projection carries no rules; gate `route-auth` passes.
- [ ] **T05**: Add "Mag worden aangeroepen door" to the rule set settings in `src/views/RuleSetsPage.vue` with NcSelect and `inputLabel` (REQ-BQRX-001). Verify: vitest for the stored value; gate `nc-input-labels` passes.
- [ ] **T06**: English and Dutch strings; a section in `docs/form-logic-authoring.md` on letting another app call a rule set. Verify: `npm run lint` passes on the touched files.
- [ ] **T07**: Run `openspec validate rules-evaluation-for-other-apps --strict`.
