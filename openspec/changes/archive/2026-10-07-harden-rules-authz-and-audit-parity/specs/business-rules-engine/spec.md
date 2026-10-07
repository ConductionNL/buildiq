## ADDED Requirements

### Requirement: Rule-set resolution MUST be authorization-scoped, and its documentation MUST be accurate

Rule-set, decision-table, and test-case resolution SHALL run through an
authorization-scoped OpenRegister query (`searchObjects`, which applies the
caller's RBAC + organisation scope) rather than raw `findAll`, so a rule-set the
caller is not authorized to access resolves to not-found instead of being
evaluated, read, or tested by slug. The docblocks in `RuleEngineService`,
`RulesController`, and the `/api/rules/*` entries in `appinfo/routes.php` MUST
describe the isolation actually enforced (organisation + schema-RBAC scope) and
MUST NOT claim a per-tenant "foreign slug → 404 / no IDOR" guarantee that is not
enforced.

#### Scenario: A caller cannot evaluate a rule-set outside their authorization scope
- **WHEN** an authenticated caller POSTs to `/api/rules/{slug}/evaluate` for a
  rule-set outside their RBAC/organisation scope
- **THEN** resolution returns not-found and the rule-set is not evaluated

#### Scenario: schema and test-all resolution are equally scoped
- **WHEN** the same caller requests `/api/rules/{slug}/schema` or
  `/api/rules/{slug}/test-all` for an out-of-scope rule-set
- **THEN** both resolve to not-found via the same authorization-scoped path

#### Scenario: An authorized caller resolves their own rule-set normally
- **WHEN** a caller evaluates a rule-set within their authorization scope
- **THEN** it resolves and evaluates as before, with no behaviour change
