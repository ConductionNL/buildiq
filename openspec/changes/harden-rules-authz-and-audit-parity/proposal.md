---
kind: code
depends_on: []
---

# Proposal: harden-rules-authz-and-audit-parity

## Why

The remediation-verification pass left a cluster of **backend** findings that fell
between the two other hardening changes (`harden-app-ownership-authz`,
`harden-xss-dos-csrf`): the rules-engine authorization/IDOR gap, and several
small authorization- and audit-consistency defects. Individually most are
Medium/Low, but two of them are not cosmetic:

- **M1** is a Medium that ships a **false security claim** — the rules routes and
  service docblocks assert "multi-tenant isolation is enforced… a foreign slug
  resolves to 404 (no IDOR)," which is untrue. Resolution goes through OpenRegister
  `findAll`, whose isolation (now that OR defaults `_rbac`/`_multitenancy` to true)
  is **organisation-level at best, not per-owner** — any same-org authed user can
  evaluate/read/test another user's rule-set, decision-table, or test-case by slug.
  A wrong "no IDOR" comment is worse than none: it invites callers to trust a
  boundary that does not exist.
- **L8** is a real (if low-severity) **latent authorization bug** discovered during
  verification: the export download authz check reads a `submittedBy` key that is
  never persisted (the broker migration persists `requestedBy` instead), so the
  check always silently falls back to `@self.owner`.

The rest (L2, L4, L9) are small consistency fixes that keep authorization and
audit behaviour honest with what the code claims.

## What Changes

### M1 — rules-engine authorization + honest documentation
- Add a per-RuleSet ownership/authorization gate to rule-set resolution in
  `RuleEngineService` (`loadBundle`/`findMany`, ~`:353-360`) and
  `RulesController::findRuleSet`/`findTestCases` (~`:240,286`) — resolve through an
  RBAC/tenant-scoped path (e.g. `searchObjects`) or an explicit owner check, so a
  caller cannot reach a rule-set/decision-table/test-case they do not own or share.
- **Correct the false docblocks** in `RulesController` (~`:13-19`),
  `RuleEngineService::loadBundle` (~`:188-191`), and `appinfo/routes.php`
  (~`:172-180`) to state the isolation actually enforced (organisation-level, and
  now per-owner after this change) — remove the untrue "no IDOR" wording.

### L8 — export download authz field mismatch
- Reconcile the identity the download check reads with the one persisted: either
  read `requestedBy ?? @self.owner` in `ExportsController::isAuthorisedForJob`
  (~`:235`), or persist a `submittedBy` field in `ExportJobService::queue`
  (~`:141`) and the export-job schema. One source of truth, no silent fallback.

### L2 — MCP admin-bypass audit parity
- Inject `AuditTrailMapper` into `AbstractToolHandler` and reuse the HTTP path's
  `recordAdminBypass()` in the MCP admin-bypass branch (~`:181-186`), so an
  LLM-driven admin edit lands in the OR per-object audit trail (REQ-OBRBAC-007),
  not just the PSR log. (The same PSR-only gap exists in `CopilotService`; fix
  alongside.)

### L9 — insights role check consistency
- Make `ApplicationInsightsService::callerInAnyRole` (~`:668`) handle `group:`
  principals — ideally by reusing `PermissionResolver::matchesCaller` — so a
  group-only-authorised user is not wrongly denied insights (fail-closed today).

### L4 — icon endpoint documentation honesty
- Either add the per-app viewer check the docblock claims, or correct the
  `IconController` docblock/`Cache-Control` rationale (~`:19-20,:68`) to stop
  asserting icons are "personalised to the caller's app access" when the endpoints
  enforce session-only. Low sensitivity (SVG, CSP-neutral) — documentation
  correctness, not a control change, unless a viewer gate is desired.

## Explicitly out of scope / noted
- **L3** (MCP write-tool rate limiting) — pairs conceptually with L2 but may belong
  at the OpenRegister MCP-dispatch layer rather than in these handlers; deferred to
  a decision on where MCP throttling lives.
- **L6** (PAT scrub regex) — mooted by the credential-broker migration (no PAT in
  process); tighten the regex as hygiene only if convenient, not tracked here.
- **L10** (decision-table FEEL string injection) — bounded and safe by construction
  (FEEL has no callable grammar); no action beyond keeping the quote-strip.
- **I1 / I2** (WIP re-audit now that `RuleActionDispatcher` merged; ADR-023
  divergence documentation) — documentation/process follow-ups, not code.

## Capabilities

### Modified Capabilities
- **business-rules-engine** — per-RuleSet authorization on evaluate/schema/test-all;
  documentation corrected to describe the real isolation boundary.
- **openbuild-rbac** — MCP admin-bypass recorded to the OR audit trail at parity
  with the HTTP path (REQ-OBRBAC-007).
- **openbuild-exporter** — download authorization reads the persisted requester
  identity; no silent owner-fallback.
- **application-insights** — insights role check honours `group:` principals.
- **app-icon-management** — icon endpoint documentation matches enforced behaviour
  (or gains the claimed viewer check).

### Referenced (no change here)
- **OpenRegister `findAll` RBAC/multitenancy** — the org-level isolation this
  change documents honestly and augments with a per-owner gate.

## Impact
- Rules routes stop being reachable across owners within an org; a foreign slug now
  genuinely denies (matching the corrected docs). Behaviour change for any caller
  currently relying on cross-owner rule access (should be none in single-tenant use).
- Export download authorization is unchanged in the happy path (owner still allowed)
  but no longer rests on an accidental fallback.
- MCP admin edits become visible in the permission-history panel.
- Tests (per repo policy): foreign-slug rule evaluate/schema/test-all → 403/404;
  export download by the persisted requester allowed, by a stranger denied; MCP
  admin bypass writes an audit-trail entry; a group-only-authorised caller gets
  insights.