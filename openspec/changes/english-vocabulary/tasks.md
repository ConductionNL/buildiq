# Tasks — english-vocabulary (openbuild)

Scope measured by the token-aware scan: **5 schemas / 14 Dutch properties**, all in
`lib/Settings/register.d/10-business-rules.json`. Code layer is **clean** — 0 Dutch
file, class or method names.

## 1. Measure before changing

- [ ] 1.1 Count stored `RuleSet` / `DecisionTable` / `ConditionActionRule` /
      `RuleExecutionLog` / `TestCase` objects on the dev instance, remembering that
      OpenRegister `deleteObject()` is a soft delete — every count needs
      `where _deleted is null`, and objects live in the
      `oc_openregister_table_<reg>_<schema>` shards, not in `oc_openregister_objects`.
      Record the number; it decides whether task 4 runs.
- [ ] 1.2 Enumerate every read of the 14 Dutch keys across `lib/`, `src/` and the
      register's own `x-openregister-*` expression strings. Save the list — it is the
      diff target for task 3.

## 2. Rename the schema properties

- [ ] 2.1 Rename the 8 top-level properties: `naam`→`name`, `beschrijving`→`description`,
      `ingangsdatum`→`effectiveDate`, `einddatum`→`endDate` (RuleSet);
      `regels`→`rules` (DecisionTable); `geraaktRegels`→`triggeredRules`
      (RuleExecutionLog); `naam`/`beschrijving` on ConditionActionRule and TestCase.
- [ ] 2.2 Rename the 6 nested properties: `inputColumns[].naam`→`name`,
      `outputColumns[].naam`→`name`, `outputColumns[].defaultwaarde`→`defaultValue`,
      `regels[].waardes`→`values`. These sit under `items.properties` and are the ones a
      top-level-only pass silently leaves behind.
- [ ] 2.3 Verify each new name against that property's own `title` — the `title` is the
      evidence, not a fresh translation. `geraaktRegels` becomes `triggeredRules`
      (its title reads `Triggered Rules`), **not** `matchedRules` as the proposal drafted.

## 3. Update consumers

- [ ] 3.1 Update every read site from the task 1.2 list, in the same commit as the schema.
- [ ] 3.2 Update property names appearing inside `x-openregister-calculations` and
      `-aggregations` expression strings. Static analysis cannot see these.
- [ ] 3.3 Grep the whole app for each of the 14 old names. A surviving hit is a defect
      even if every gate is green.

## 4. Migrate stored data

- [ ] 4.1 If task 1.1 counted more than zero objects, write and run a migration
      rewriting the Dutch keys to English. If it counted zero, record the measurement
      and skip — an evidenced skip, not an assumed one.

## 5. Translations

- [ ] 5.1 Add the 14 Dutch words to `l10n/nl.json`, re-pointing existing keys rather
      than re-extracting, so the Dutch UI is unchanged.
- [ ] 5.2 Run `check-l10n`.

## 6. Verify

- [ ] 6.1 Re-run the token-aware scan against openbuild; require **0 schemas / 0
      properties**, with nested levels walked.
- [ ] 6.2 Run the full test suite plus hydra gates 46 / 53 / 54 / 55 / 57 / 61.
- [ ] 6.3 Exercise a decision table through the UI, not only through the API — an
      evaluation that silently returns every row's default is exactly what a renamed key
      read with `??` produces, and an API-shaped assertion will not show it.

## Acceptance criteria

- Token-aware scan reports openbuild at 0/0, nested levels included.
- No old Dutch key survives anywhere in the repo.
- Dutch UI labels unchanged; `check-l10n` passes.
- Stored-object count recorded, and migrated if non-zero.
- A decision table evaluated through the UI produces the same result as before the rename.
