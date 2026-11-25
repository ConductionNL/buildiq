## ADDED Requirements

### Requirement: A rule set's decision table is held in OpenRegister's decision-tables register

A rule set's decision table SHALL be stored in OpenRegister's `decision-tables`
register (schema `decision-table`, owned by OpenRegister, decision 196) under the
key `buildiq.<organisation>.<rule-set-slug>`, or `buildiq.<rule-set-slug>` for a
rule set without an organisation and for a shipped seed. The stored table SHALL
carry `owner: buildiq`, the input columns with their expression paths, the output
columns with their defaults, and rules as positional entries in OpenRegister's
cell grammar. Evaluating a rule set SHALL read its table through OpenRegister's
DecisionTableStore and SHALL answer what the same table answered when it was a
buildiq row.

@e2e exclude the mapping and the read are covered by StoredDecisionTablesTest and RuleEngineServiceTest; equivalence with the old answers is cross-checked against OpenRegister's real evaluator in the PR

#### Scenario: A rule set evaluates its stored table

- GIVEN rule set `loan-eligibility` in organisation A and a table under `buildiq.<A>.loan-eligibility`
- WHEN the rule set is evaluated with an eligible applicant
- THEN the result is the table's `approve` row, as before the move

#### Scenario: Two organisations do not share a table

- GIVEN rule sets called `loan-eligibility` in organisations A and B
- THEN their tables are held under two different keys

### Requirement: The table editor writes OpenRegister's row

`PUT /api/rules/{ruleSetSlug}/decision-table` SHALL accept the editor's authoring
shape, SHALL be refused with 403 for a caller who is not an administrator, 404 for
a rule set the caller cannot resolve, and 422 with OpenRegister's reason when
OpenRegister refuses the table, and SHALL otherwise replace the row the key names.

@e2e exclude the editor's save is covered by RulesControllerTest; a journey needs an instance with openregister#4572 installed

#### Scenario: An administrator saves a table

- GIVEN an administrator editing rule set `event-fee`
- WHEN the table is saved
- THEN OpenRegister holds it under the rule set's key in the stored shape

#### Scenario: A non-administrator cannot save a table

- GIVEN a signed-in user who is not an administrator
- WHEN they save a table
- THEN the answer is 403 and nothing is written

### Requirement: Existing tables move on upgrade

Repair step `MoveDecisionTablesToOpenRegister` SHALL move every table of buildiq's
old `decision-table` schema into OpenRegister's register under its rule set's key,
SHALL leave a key OpenRegister already holds untouched, and only when every table
moved SHALL rename the old schema's slug to `buildiq-decision-table-moved`. When
OpenRegister's register is not readable it SHALL move nothing and rename nothing.

@e2e exclude a repair step has no UI; covered by MoveDecisionTablesToOpenRegisterTest

#### Scenario: Every table moves and the old slug is released

- GIVEN two old tables and OpenRegister's register present
- WHEN the repair step runs
- THEN both are in OpenRegister and the old schema is called `buildiq-decision-table-moved`

#### Scenario: A refused table keeps the old slug

- GIVEN an old table OpenRegister's validator refuses
- WHEN the repair step runs
- THEN that table stays where it was and the old schema keeps `decision-table`
