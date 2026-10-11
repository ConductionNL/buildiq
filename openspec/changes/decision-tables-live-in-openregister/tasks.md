# Tasks

- [x] `StoredDecisionTables`: key, authoring shape to stored shape and back, read through DecisionTableStore with the shared seed key as fallback, write replacing the keyed row.
- [x] `RuleEngineService` and `RulesController::schema` read the table through it; `DECISION_TABLE_SCHEMA` removed.
- [x] `PUT /api/rules/{ruleSetSlug}/decision-table` (admin) and the editor saves through it.
- [x] `DecisionTable` schema removed from the register fragment and `openbuild_register.json`; seed table moved to OpenRegister's register.
- [x] Repair step `MoveDecisionTablesToOpenRegister`, before InitializeSettings.
- [x] Unit tests; cross-check of the old and new answers against OpenRegister's real evaluator and validator.
- [ ] Live: after openregister#4572 and this PR are installed, `occ upgrade`, then `GET /apps/openregister/api/objects/decision-tables/decision-table` lists the moved tables and `POST /apps/buildiq/api/rules/loan-eligibility/evaluate` answers as before.
