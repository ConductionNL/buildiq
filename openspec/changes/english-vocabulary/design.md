## Context

openbuild's business-rules register (`lib/Settings/register.d/10-business-rules.json`)
declares five schemas whose property names are Dutch while their `title` fields are
already English. The scan (token-aware, walking nested `properties` and
`items.properties`) finds **5 schemas / 14 Dutch properties**, and **zero** Dutch in
class, method or file names — openbuild is the only non-trivial app in the fleet whose
PHP/Vue layer is already clean.

This makes openbuild the cheapest change in the programme and a useful pilot: it
exercises the full rename → l10n → gate pipeline with no cross-app coordination and no
adapter-layer ambiguity.

### The schema disagrees with itself, and the `title` is the intent

Every Dutch property here already carries an English `title`:

| property | its own `title` |
|---|---|
| `naam` | `Name` |
| `beschrijving` | `Description` |
| `ingangsdatum` | `Effective Date` |
| `einddatum` | `End Date` |
| `regels` | `Rules` |
| `geraaktRegels` | `Triggered Rules` |
| `defaultwaarde` | `Default Value` |
| `waardes` | `Output Values` |

The rename therefore does not require a translation judgement. It requires copying the
word the schema author already wrote one line below.

## Goals / Non-Goals

**Goals:**

- Rename all 14 Dutch properties to the English word each schema's own `title` states.
- Move the Dutch words to `l10n/nl.json` so the Dutch UI is unchanged for users.
- Prove the rename pipeline (schema → consumers → l10n → gates) on the smallest surface
  before it runs against procest and shillinq.

**Non-Goals:**

- No schema renames. All five schema names (`RuleSet`, `DecisionTable`,
  `ConditionActionRule`, `RuleExecutionLog`, `TestCase`) are already English.
- No code identifier renames. The scan found none to make.
- No change to stored rule *content* — the DMN-style expressions inside a rule's
  condition strings are user data, not vocabulary.

## Decisions

### 1. `regels` → `rules`, not `lines` — RESOLVED from the schema, not from Dutch

The fleet policy flagged `regel` as ambiguous: Dutch `regel` means both *rule* and
*line*, and shillinq's `regels` meant **lines** (renamed to `CommitmentLine` in
shillinq#495). Guessing wrong here produces a plausible-but-wrong name that outlives
the rename and misleads every future reader.

It is not a guess. `DecisionTable.regels` carries `"title": "Rules"` and
`RuleExecutionLog.geraaktRegels` carries `"title": "Triggered Rules"`. The containing
schemas are `RuleSet` and `ConditionActionRule`. openbuild means **rules**.

**Decision:** `regels` → `rules`, `geraaktRegels` → `triggeredRules`.

⚠️ The per-app proposal originally wrote `matchedRules`. That was invention; the schema
says `Triggered Rules`. **`triggeredRules` supersedes it** — this design is the
authority.

### 2. Nested properties are in scope, and openbuild is why we know that

`DecisionTable` holds Dutch keys that the fleet's first scan never saw:

- `inputColumns[].naam`
- `outputColumns[].naam`
- `outputColumns[].defaultwaarde`
- `regels[].waardes`

These live under `items.properties`, one and two levels below `schema.properties`. The
original fleet scan walked only the top level, which is how the fleet figure of
"92 schemas / 653 properties" came to be an undercount. openbuild is the concrete case
that exposed it.

**Decision:** the rename walks `properties.*.properties` and `items.properties` to
arbitrary depth. Six of openbuild's fourteen properties are nested; a top-level-only
rename would silently leave them Dutch and the change would look complete.

### 3. Renamed keys fail silently, so consumers are diffed rather than trusted

Every consumer of these properties reads with `??` or optional chaining. A key that no
longer exists therefore yields `null`, not an error — no test fails, no log line
appears, and the decision table quietly evaluates every row to its default.

**Decision:** before the rename lands, enumerate every read of the 14 names across
`lib/`, `src/` and the register's own `x-openregister-*` expressions, and diff that list
against the new schema. This is the same silent-absence class that produced the
shillinq `'bron'`/`'source'` idempotency defect, where a filter looked up a key the
writer had already renamed and matched nothing forever.

⚠️ `x-openregister-calculations` / `-aggregations` reference property names **inside
expression strings**. A string is invisible to PHPStan and to `php -l`. These must be
enumerated by reading the register, not by relying on static analysis.

## Risks / Trade-offs

- **A renamed key read with `??` returns null instead of failing** → diff every read
  site against the new schema before landing; do not rely on the test suite, which
  passes either way.
- **Nested keys are missed and the change looks done** → the acceptance check re-runs the
  token-aware scan and requires openbuild to report 0/0, walking nested levels.
- **Expression strings in `x-openregister-*` still name the Dutch key** → grep the
  register for each old name after the rename; a surviving hit is a defect even though
  every gate is green.
- **Stored objects keep the Dutch keys** → a property rename is a data migration. Existing
  `RuleSet` / `DecisionTable` objects in OpenRegister hold `naam`, not `name`. Either a
  migration rewrites them or the rename orphans live data. **This is the one genuinely
  risky part of an otherwise trivial change** and must not be waved through because the
  schema diff looks small.

## Migration Plan

1. Rename the 14 properties in `10-business-rules.json`, including nested levels.
2. Diff every consumer read against the new schema; update in the same commit.
3. Add the 14 Dutch words to `l10n/nl.json`, re-pointing existing keys rather than
   re-extracting.
4. Migrate stored objects, or confirm from the instance that none exist yet.
5. Re-run the token-aware scan; require 0/0.

**Rollback:** the schema change is a single JSON file and reverts cleanly. Once step 4
has rewritten stored objects, rollback also requires the inverse migration — so step 4
is the point of no easy return, and the object count must be known before it runs.

## Open Questions

- How many `RuleSet` / `DecisionTable` / `ConditionActionRule` objects exist on the dev
  instance and in any customer instance? The migration's cost and risk are entirely a
  function of this number, and it has not been measured. If the answer is zero, steps 4
  and its rollback disappear.
