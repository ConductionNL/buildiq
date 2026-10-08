## ADDED Requirements

### Requirement: FEEL expression evaluation MUST be bounded against resource exhaustion

The business-rules engine SHALL enforce hard limits before and during FEEL
expression parsing and evaluation, so that no single expression can exhaust the
PHP call stack or process memory. The engine MUST enforce a maximum source
length in `FeelParser::parse()`, a maximum recursion depth threaded through the
recursive-descent parse methods and through `ExpressionEvaluator::evaluate()`,
and a maximum AST node count. Exceeding any limit SHALL raise an
`InvalidArgumentException` (surfaced as HTTP 422), not a fatal error. The
existing post-evaluation 500 ms timer is retained for reporting but is NOT relied
upon as the resource bound.

#### Scenario: Over-length expression is rejected before parsing
- **WHEN** a stored FEEL expression exceeds the maximum source length
- **THEN** `FeelParser::parse()` throws `InvalidArgumentException` and no
  recursive descent is attempted

#### Scenario: Over-deep expression is rejected during parsing
- **WHEN** an expression nests parentheses or unary operators beyond the maximum
  recursion depth
- **THEN** the parser throws `InvalidArgumentException` before the PHP call stack
  is exhausted

#### Scenario: A well-formed expression within the limits evaluates normally
- **WHEN** an expression is within the length, depth, and node-count limits
- **THEN** it parses and evaluates to its correct result with no behaviour change

### Requirement: `call-rule-set` dispatch MUST prevent unbounded re-entry

The engine SHALL prevent unbounded re-entry when a condition-action rule
dispatches a `call-rule-set` action. It MUST track the evaluation chain (a
recursion-depth counter and the set of rule-set slugs already active in the
current chain, threaded through `RuleEngineService::evaluate()`) and MUST refuse
re-entry that exceeds a maximum depth or that re-enters a rule-set already active
in the chain. A refused re-entry SHALL fail closed with an error result and MUST
NOT recurse, write further execution logs, or fire further side effects for the
refused level.

#### Scenario: Self-referential rule set is refused
- **WHEN** a rule set contains a `call-rule-set` action targeting itself
- **THEN** the re-entry is refused and evaluation returns an error result without
  a stack-overflow fatal

#### Scenario: Mutually-referential rule sets are refused past the cap
- **WHEN** rule set A calls B and B calls A
- **THEN** the chain is stopped at the depth cap or on the repeated slug, and no
  further side effects fire for the refused level

#### Scenario: Legitimate distinct-slug nesting within the cap succeeds
- **WHEN** a rule set calls a distinct rule set nested within the depth cap
- **THEN** the nested evaluation completes normally

### Requirement: The evaluate payload MUST be size-bounded before it is logged

`RulesController::evaluate` SHALL reject a request payload that exceeds a maximum
size before it is passed to evaluation or written to a `RuleExecutionLog`, and
the PII-masking traversal SHALL enforce a maximum recursion depth. This prevents
unbounded log rows and a secondary recursion vector.

#### Scenario: Oversized payload is rejected before logging
- **WHEN** an evaluate request carries a payload larger than the maximum size
- **THEN** the request is rejected (413/422) and no `RuleExecutionLog` row is
  written