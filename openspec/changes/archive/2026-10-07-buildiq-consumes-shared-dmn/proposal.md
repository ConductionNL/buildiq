# buildiq consumes OpenRegister's shared decision-table evaluator

## Why

openregister#3186 made OpenRegister the fleet's one DMN evaluator, taking
buildiq's `PRIORITY` with it. It deliberately retired neither app's copy, and
named buildiq as the harder of the two: "openbuild's `any` tables in particular
change shape."

This change retires buildiq's copy. dossiq's equivalent is dossiq#1561, and it
was the easy one: dossiq's engine WAS the shared engine, so adoption there was a
delete. buildiq's is not, in three ways that all had to be measured rather than
assumed.

## The three differences, measured

**The table shape.** buildiq's tables are `{inputColumns, outputColumns, rules:
[{conditions, values, priority}]}` with conditions keyed by column name. The
shared evaluator takes `{inputs, outputs, rules: [{id, inputEntries,
outputEntries}]}`, positional. The adapter translates.

**The cell grammar.** This was the surprise. buildiq compiles each cell into a
FEEL expression, and the two dialects differ in eight of twenty-one cases I
probed, in both directions. buildiq accepts `==7`, a bracketless `18..65`, and
`*` / `any` as wildcards; the shared grammar rejects all four. The shared
grammar accepts `[18..65]` and `in (gold,silver)`, which buildiq errors on or
silently fails. The adapter translates the three buildiq-only spellings.

`*` is the one that mattered. Untranslated, the shared grammar reads it as a
literal, so the rule stops matching and returns a different decision with no
error anywhere.

**Unresolved columns.** The shared evaluator coerces every declared input before
matching, so one path that does not resolve in the payload refuses the entire
table with `type_mismatch`. buildiq's contract is the opposite: an unresolved
column fails the conditions that test it, and the table falls through to its
defaults. The adapter drops the null columns and the rules that test them, which
reproduces that exactly without reimplementing any matching.

I did not predict this one. It came out of a parity run, as 27 differences.

## Evidence

72 cases: 8 table shapes (every hit policy, `rule-order`, a typo'd policy, and a
table of the divergent cell dialects) across 9 payloads, run through the old
evaluator and through the adapter driving the REAL shared evaluator, comparing
outputs and triggered rule.

**72/72 identical.** The same harness reported 27 differences before the
unresolved-column fix, so it discriminates.

## What buildiq keeps

`detectIssues()` and its structural overlap analysis, which are buildiq's own and
touch no evaluation. `ExpressionEvaluator` stays too: resolving `expressionPath`
against a payload is buildiq's concept, and the shared evaluator takes named
values.
