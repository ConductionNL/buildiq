# Decision tables live in OpenRegister

## Why

Decision 196: OpenRegister owns the schema slug `decision-table` and ships a
`decision-tables` register that any app and any flow step reads by key
(openregister#4572). buildiq kept its own `decision-table` schema, so two apps
declared the same slug on one shared OpenRegister (gate 106) and a table
authored in buildiq could not be used by a flow, and the other way round.

## What changes

- A rule set's decision table is stored in OpenRegister's `decision-tables`
  register, in the shape OpenRegister evaluates, under the key
  `buildiq.<organisation>.<rule-set-slug>`. `StoredDecisionTables` maps
  buildiq's authoring shape onto that shape and back; the evaluator, the
  `schema` endpoint and the test gate read the table through it.
- `RuleEngineService::DECISION_TABLE_SCHEMA` and buildiq's `DecisionTable`
  schema are gone. The seed table of `loan-eligibility` targets OpenRegister's
  register under the shared key `buildiq.loan-eligibility`.
- The table editor saves through `PUT /api/rules/{ruleSetSlug}/decision-table`
  (admin only, as before), which writes OpenRegister's row; OpenRegister's
  validator refuses a table it cannot run, and that comes back as 422.
- Repair step `MoveDecisionTablesToOpenRegister` moves every existing table
  into OpenRegister's register, then renames the old schema's slug to
  `buildiq-decision-table-moved`. Idempotent, never deletes, never throws.

## Impact

- Lands together with openregister#4572 and the `fleet-schema-slugs.json`
  ownership change in ConductionNL/.github. Without #4572 the
  `DecisionTableStore` class does not exist and the rule engine cannot be built.
- Tenant isolation (REQ-BRE-007) moves from the object filter into the key: a
  table is only reached through a rule set the caller's organisation resolves.
- A rule set kept at most one evaluated table before (the engine read the
  first); a second table of the same rule set stays on the old schema.
