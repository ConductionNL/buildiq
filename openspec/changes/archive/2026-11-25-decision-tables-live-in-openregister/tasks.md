# Tasks

- [x] `StoredDecisionTables`: key, authoring shape to stored shape and back, read through DecisionTableStore with the shared seed key as fallback, write replacing the keyed row.
- [x] `RuleEngineService` and `RulesController::schema` read the table through it; `DECISION_TABLE_SCHEMA` removed.
- [x] `PUT /api/rules/{ruleSetSlug}/decision-table` (admin) and the editor saves through it.
- [x] `DecisionTable` schema removed from the register fragment and `openbuild_register.json`; seed table moved to OpenRegister's register.
- [x] Repair step `MoveDecisionTablesToOpenRegister`, before InitializeSettings.
- [x] Unit tests; cross-check of the old and new answers against OpenRegister's real evaluator and validator.
- [x] Live: after openregister#4572 and this PR are installed, `occ upgrade`, then `GET /apps/openregister/api/objects/decision-tables/decision-table` lists the moved tables and `POST /apps/buildiq/api/rules/loan-eligibility/evaluate` answers as before.
  > Verified 25 Nov 2026 on a fresh Nextcloud 34.0.6 (MariaDB) with OpenRegister main (55f2aa3e) and buildiq development: `occ maintenance:repair` imports OpenRegister's decision-table register, and `GET /apps/openregister/api/objects/decision-tables/decision-table` lists `buildiq.loan-eligibility` (owner buildiq, hit policy FIRST, three rules). `POST /apps/buildiq/api/rules/loan-eligibility/evaluate` answers as the unit cross-check does: age 30 / income 4000 / score 750 gives `approve` (Standard approval), 30 / 1600 / 550 gives `review` (Marginal case), 17 / 4000 / 750 gives `deny` (Default deny). This needed buildiq#1902 (the leaves fataled repair on OpenRegister main) and openregister#11154 (a migration type hint on Nextcloud below 35).
