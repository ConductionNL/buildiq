## Context

The remediation-verification pass over the OpenBuild architectural audit left a
cluster of backend findings that fell between the two other hardening changes:
the rules-engine authorization/IDOR gap with its false documentation (M1), and a
set of small authorization- and audit-consistency defects (L2, L4, L8, L9). This
change addresses them together because they are all "the code claims a control it
does not enforce, or enforces one inconsistently."

Current state, per finding:
- **M1** — `RuleEngineService`/`RulesController` resolve rule-sets via OpenRegister
  `findAll`. OR now defaults `_rbac`/`_multitenancy` to true, so resolution is
  organisation-level + schema-RBAC scoped — but the docblocks and `routes.php`
  claim *"multi-tenant isolation enforced… foreign slug → 404 (no IDOR)"*, which
  is false: within an org, any authed user can evaluate/read/test any rule-set by
  slug.
- **L8** — the export download authz check reads a `submittedBy` key that is never
  persisted (the broker migration persists `requestedBy`), so it always falls back
  to `@self.owner`.
- **L2** — MCP admin-bypass is logged to PSR only; the HTTP path records it to the
  OR per-object audit trail (`recordAdminBypass`, REQ-OBRBAC-007).
- **L9** — `ApplicationInsightsService::callerInAnyRole` matches only `user:`/bare
  uid, not `group:` — a group-only-authorised user is wrongly denied (fail-closed).
- **L4** — `IconController` docblock/`Cache-Control` claim icons are access-scoped,
  but the endpoints enforce session only.

## Goals / Non-Goals

**Goals:**
- Make the rules-engine authorization boundary real *and* honestly documented.
- Fix the export-download authz field mismatch so it reads the persisted identity.
- Bring MCP admin-bypass to audit-trail parity with the HTTP path.
- Make the insights role check group-aware.
- Correct the icon-endpoint documentation to match enforced behaviour.

**Non-Goals:**
- Per-user (as opposed to per-app / per-org) isolation of rule-sets — see Open
  Questions; this change delivers RBAC-scoped resolution + honest docs, not a new
  per-user ownership model.
- The app-ownership / non-admin-authoring redesign (tracked in
  `harden-app-ownership-authz`).
- L3 (MCP rate limiting), L6 (PAT scrub regex), L10 (decision-table injection) —
  deferred with reasons in the proposal.

## Decisions

**D1 — M1: resolve rule-sets through an RBAC-scoped path and correct the docs.**
Replace the raw `findAll` rule-set/test-case resolution in `RuleEngineService`
(`loadBundle`/`findMany`) and `RulesController` (`findRuleSet`/`findTestCases`)
with OpenRegister `searchObjects`, which applies the caller's RBAC + org scope, so
a rule-set the caller cannot access resolves to not-found rather than being
evaluated. Correct the docblocks in `RuleEngineService::loadBundle`,
`RulesController`, and `appinfo/routes.php` to describe the *real* boundary
(organisation + schema-RBAC scope) and remove the untrue "no IDOR / foreign slug →
404" wording. *Alternative considered:* an explicit `eigenaarApp`-ownership check
per rule-set — deferred; `searchObjects` is the ADR-022-aligned primitive and
removes the raw-`findAll` bypass. If per-owner (not per-org) isolation is later
required, the `eigenaarApp` check is a follow-up (Open Questions).

**D2 — L8: read the persisted requester identity (no schema change).**
Change `ExportsController::isAuthorisedForJob` to read
`requestedBy ?? @self.owner` (the field the broker migration actually persists)
instead of the never-written `submittedBy`. *Alternative:* persist a `submittedBy`
field on the export-job schema — rejected: it adds a schema/seed surface for no
benefit when `requestedBy` already carries the identity.

**D3 — L2: inject `AuditTrailMapper` and reuse `recordAdminBypass`.**
Add `AuditTrailMapper` to `AbstractToolHandler`'s constructor and call the same
audit-trail write the HTTP path uses in the MCP admin-bypass branch, so LLM-driven
admin edits appear in the permission-history panel. Apply the same fix to
`CopilotService`'s PSR-only bypass branch.

**D4 — L9: reuse `PermissionResolver` in the insights check.**
Make `callerInAnyRole` handle `group:` principals — reuse
`PermissionResolver::matchesCaller` rather than the bespoke `user:`-only matcher,
so insights authorization is consistent with every other guard.

**D5 — L4: correct the icon docblock (documentation truth).**
Either add the per-app viewer check the docblock claims, or correct the
`IconController` docblock/`Cache-Control` rationale to match session-only
enforcement. Default: correct the documentation (icons are low-sensitivity SVG,
CSP-neutral); a viewer gate is optional and out of scope unless desired.

## Declarative-vs-imperative decision (ADR-031)

None of these changes introduce or modify a lifecycle, aggregation, derived field,
notification, declarative relation, or dashboard widget. They are **imperative by
nature**: authorization gates, an audit-trail write, a role-matcher fix, and
documentation corrections. There is no `x-openregister-*` schema-register surface
for an authorization guard or an audit-parity fix, so ADR-031's declarative default
does not apply. No `lib/Settings/*_register.json` edits are part of this change.

## Seed Data (ADR-001)

**Not applicable.** This change introduces and modifies no OpenRegister schemas or
registers (D2 deliberately reads the existing `requestedBy` field rather than adding
`submittedBy`). No `_registers.json` seed entries are generated and no seed-data
task is included.

## Risks / Trade-offs

- **`searchObjects` semantics differ from `findAll`** → verify the returned shape
  matches what `loadBundle` expects (bundle assembly), and that a legitimately
  accessible rule-set still resolves. Covered by the existing + new engine tests.
- **Corrected docs reveal org-level (not per-user) scope** → this is honest; a
  reviewer might expect stricter isolation. Open Questions records the follow-up.
- **`AuditTrailMapper` nullable in some contexts** → guard the MCP write the same
  fail-soft way the HTTP `recordAdminBypass` does (log critical on write failure,
  never abort the operation).

## Migration Plan

Pure code change; no data migration. Standard app-update deploy. Each fix is an
independent edit, so any one can be reverted without the others.

## Open Questions

- **Rules-engine isolation granularity** — this change delivers org + schema-RBAC
  scoping + honest docs. If product requires per-owner (per-`eigenaarApp`)
  isolation, add an explicit ownership check — decide alongside the
  `harden-app-ownership-authz` designer conversation.
- **L4** — correct the docs only, or also add a per-app viewer gate on icons?
  Default is docs-only; confirm during review.
