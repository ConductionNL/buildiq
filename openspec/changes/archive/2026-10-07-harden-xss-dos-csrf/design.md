## Context

An attack-surface sweep (full enumeration in `attack-surface-map.md`) found a
small set of exploitable weaknesses in Buildiq's own code across three
categories, against a large majority of surfaces that are already correctly
defended. The exploitable items are: two denial-of-service Highs in the
business-rules engine (no bounds on the FEEL parser/evaluator; no re-entry guard
on `call-rule-set`), a supply-chain-relevant CSRF hole on the settings
controller (plus two smaller CSRF gaps), and one cross-user XSS sink (the
Docudesk document-template preview). The engine's only current DoS guard is a
post-hoc 500 ms timer that cannot interrupt a running parse/eval and cannot
catch a C-level stack-overflow fatal.

This design covers the implementation approach for the prioritized fixes. It is
a hardening change — no new features, no data-model changes.

## Goals / Non-Goals

**Goals:**
- Bound the rule-evaluation stack so no single request can crash an FPM worker
  or amplify webhook/DB side effects (DoS #1, #2, #3).
- Bring `createFromTemplate` to parity with the already-gated creation wizard
  (DoS #4).
- Enforce CSRF on the three unjustified state-changing endpoints.
- Sanitize the one cross-user XSS sink, and share the sanitizer with the
  self-only icon previews.

**Non-Goals:**
- Making rule evaluation *interruptible* — out of reach in PHP; we prevent the
  crash with hard caps instead of interrupting a runaway.
- Lower-ranked DoS items (AppOverride delta rate limit, MCP-dispatch throttling,
  egress rate limits) — mapped, deferred.
- The SSRF redirect fix (audit H2) — already applied separately.
- Any change to the 30+ already-CSRF-protected mutations.

## Decisions

**D1 — Bound the FEEL parser/evaluator with a max input length + a shared
recursion-depth counter, not a rewrite.**
Add a length check at the top of `FeelParser::parse()` and a depth counter
threaded through the `parseX` recursive-descent methods and
`ExpressionEvaluator::evaluate()`, throwing `InvalidArgumentException` past a
cap. Model the constants on the existing, well-bounded `AppRepoParser`
(`MAX_FILE_BYTES`, `MAX_JSON_DEPTH`). *Alternative considered:* raising the PHP
stack limit / catching the fatal — rejected: a stack-overflow fatal is
uncatchable and process-fatal. *Alternative:* an AST-node-count cap only —
kept as a secondary bound but insufficient alone (a deep-but-small expression
still overflows during parse).

**D2 — Guard `call-rule-set` with a depth counter + visited-slug set threaded
through `RuleEngineService::evaluate()`.**
Refuse re-entry past a small depth cap or on a repeated slug in the current call
chain. *Alternative:* a global per-request evaluation counter — rejected: does
not distinguish legitimate fan-out from a cycle as cleanly as a visited-slug
set.

**D3 — Bound the evaluate payload before it is logged.**
Reject an oversized `payload` in `RulesController::evaluate` (413/422) before
`persistLog`, and cap `maskPii` recursion depth. Prevents unbounded rows and a
second recursion vector.

**D4 — `createFromTemplate` mirrors the wizard's gate + rate limit.**
Add `#[UserRateLimit]` and the same admin gate the wizard already applies to the
identical register/schema fan-out. *Alternative:* leave it open — rejected: it
is an inconsistent, unthrottled amplification path.

**D5 — CSRF: delete the attributes; the SPA already sends `requesttoken`.**
Remove `#[NoCSRFRequired]` from `SettingsController::create`/`::load` and the
`@NoCSRFRequired` docblock from `PreferencesController::setPreference`. No client
change needed — `@nextcloud/axios` sends the token. `ExportsController::download`
keeps its justified GET stance.

**D6 — XSS: add `dompurify`, sanitize at the sink.**
Route `DocumentTemplateAttachmentDialog`'s `previewContent` through
`DOMPurify.sanitize(...)` (full HTML profile). Sanitize the verbatim-SVG branch
in `iconCatalogues.js::resolveAppIcon` with an SVG profile so the value is safe
both in the author preview and before it is persisted/served. *Alternative:*
sandboxed iframe for the preview — heavier; DOMPurify is sufficient and reused
for the icon path.

## Declarative-vs-imperative decision (ADR-031)

None of the changes introduce or modify a lifecycle, aggregation, derived field,
notification, declarative relation, or dashboard widget. They are **imperative by
nature**: input-validation guards inside a hand-written parser/evaluator, a
recursion guard in a dispatcher, HTTP CSRF attributes, and client-side output
sanitization. ADR-031's declarative default does not apply — there is no
schema-register (`x-openregister-*`) surface for a security bound on a parser or
a CSRF attribute. No `lib/Settings/*_register.json` edits are part of this change.

## Seed Data (ADR-001)

**Not applicable.** This change introduces and modifies no OpenRegister schemas
or registers — it edits PHP guard logic, controller attributes, and Vue
sanitization. No `_registers.json` seed entries are generated and no seed-data
task is included.

## Risks / Trade-offs

- **Caps reject a legitimately large/deep expression** → Choose generous
  defaults (model on `AppRepoParser`: KB-scale length, depth ~64) and return a
  clear `InvalidArgumentException` (422) so authors see a boundary, not a crash.
- **`call-rule-set` cap breaks a legitimately deep rule chain** → Set the depth
  cap above realistic nesting; the visited-slug set only blocks true cycles, not
  legitimate distinct-slug fan-out.
- **CSRF removal breaks a non-SPA caller** → None known; every legitimate caller
  is the SPA using `@nextcloud/axios` (sends `requesttoken`). Verify in a live
  instance before merge.
- **New `dompurify` dependency** → Widely used, already transitively present via
  `@nextcloud/vue`; pin and let the SBOM/CI pick it up.

## Migration Plan

Pure code change; no data migration. Deploy is a standard app update. Rollback is
reverting the change — the caps and CSRF attributes are independent edits, so an
individual fix can be reverted without the others.

## Open Questions

- Exact numeric caps (max FEEL length, max recursion depth, max AST nodes, max
  payload bytes, `call-rule-set` depth) — to be finalized against realistic
  authored rule sets during implementation; defaults from `AppRepoParser`.
- Whether `createFromTemplate` should be admin-gated (matching the wizard) or
  only rate-limited — depends on the non-admin-ownership decision tracked in
  `harden-app-ownership-authz/discovery.md`; until that lands, mirror the
  wizard's admin gate for consistency.