---
kind: code
depends_on: []
---

# Proposal: harden-xss-dos-csrf

## Why

A per-category attack-surface sweep of Buildiq's own code (full enumeration in
`attack-surface-map.md`) found a small number of concrete, fixable weaknesses in
three categories, plus a large majority of surfaces that are already correctly
defended. The exploitable items concentrate in the business-rules engine (two
denial-of-service Highs), the settings controller (a supply-chain-relevant CSRF
hole), and one cross-user XSS sink. This change fixes those and leaves the
already-correct surfaces untouched.

Two items matter beyond their category label:
- The `call-rule-set` recursion vector lives in `RuleActionDispatcher`, which the
  original architectural audit recorded as "WIP-branch only" (finding I1). It has
  since merged into `development`, so its "re-audit before merge" note is overdue.
- The `SettingsController::create` CSRF hole is a **supply-chain lever**: a forged
  request against an admin session can rewrite `registry_url` / `registry_token`
  and point the remote template store at an attacker-controlled catalogue.

## What Changes

### DoS — bound the rule-evaluation stack (highest value)
- **FEEL parser/evaluator caps.** Add a max source-length check at the top of
  `FeelParser::parse()` and a shared recursion-depth counter threaded through the
  `parseX` methods and `ExpressionEvaluator::evaluate()`, throwing
  `InvalidArgumentException` past a cap. Model the bounds on the existing
  `AppRepoParser` (`MAX_FILE_BYTES`, `MAX_JSON_DEPTH`).
- **`call-rule-set` re-entry guard.** Thread a depth counter + visited-slug set
  through `RuleEngineService::evaluate()` and refuse re-entry past a small cap (or
  on a repeated slug), so a self/mutually-referential rule set cannot recurse
  without bound. Cross-reference the FEEL cap so a single evaluate cannot crash a
  worker or amplify webhook/DB side effects.
- **Evaluate payload bound.** Reject an oversized `payload` in
  `RulesController::evaluate` before it is logged; cap `maskPii` recursion depth.
- **`createFromTemplate` parity.** Add `#[UserRateLimit]` and mirror the wizard's
  admin gate on `ApplicationsController::createFromTemplate` so it matches the
  same fan-out already gated in `ApplicationCreationController`.

### CSRF — remove the three unjustified `#[NoCSRFRequired]`
- Remove `#[NoCSRFRequired]` from `SettingsController::create` and `::load`
  (the SPA already sends the NC `requesttoken`, so nothing legitimate breaks).
- Drop the `@NoCSRFRequired` docblock from `PreferencesController::setPreference`
  (keep `@NoAdminRequired`); the sibling `getPreference` GET may keep its stance.
- Leave `ExportsController::download`'s justified GET `#[NoCSRFRequired]` as-is.

### XSS — sanitize the one cross-user sink (and share the fix)
- Add `dompurify` as a dependency and route
  `DocumentTemplateAttachmentDialog.vue`'s `previewContent` through
  `DOMPurify.sanitize(...)` (or render it in a sandboxed iframe).
- Sanitize the verbatim-SVG branch in `iconCatalogues.js::resolveAppIcon` with an
  SVG profile (`USE_PROFILES: { svg: true, svgFilters: true }`), hardening the
  four self-only icon previews and the value before it is persisted/served. Lower
  priority than the Docudesk sink.

## Explicitly out of scope
- Lower-ranked DoS items 6–8 (AppOverride delta rate limit, MCP-dispatch
  throttling, egress-endpoint rate limits) — recorded in the map; defer unless a
  follow-up prioritizes them. MCP throttling in particular may belong at the
  OpenRegister MCP-dispatch layer, not here.
- The SSRF redirect fix (audit H2) — already applied ahead of this change
  (`RemoteTemplateStoreService`, `allow_redirects => false`); noted in the map for
  completeness.

## Capabilities

### Modified Capabilities
- **business-rules-engine** — input-length + recursion-depth + node-count caps on
  the FEEL parser/evaluator; `call-rule-set` re-entry guard; evaluate-payload
  bound. (Interruptibility remains out of reach; caps prevent the crash instead.)
- **settings-and-observability** — CSRF enforced on `settings#create` / `#load`,
  and on `preferences#setPreference`.
- **buildiq-template-catalogue** — `createFromTemplate` gains the creation
  wizard's rate limit + authorization gate (DoS #4 parity).
- **docudesk-document-templates** — the document-template preview is sanitized
  before render.
- **app-icon-management** — author-supplied SVG is sanitized before preview and
  before persistence.

### Referenced (no change here)
- **buildiq-remote-template-store** — the SSRF redirect fix (H2) already landed
  against this surface.
- Preferences per-user config — `setPreference` CSRF posture corrected; no
  dedicated spec capability, tracked here.

## Impact
- No behaviour change for well-formed rule sets/expressions under the caps, for
  admins, or for any of the 30+ already-CSRF-protected mutations.
- FEEL authors hitting a cap get a clear `InvalidArgumentException` (422) instead
  of a worker crash — a deliberate, observable behaviour change at the boundary.
- Frontend gains a `dompurify` dependency; the preview dialog and icon previews
  render sanitized output.
- Tests (per repo policy): FEEL parser rejects an over-length / over-deep
  expression; `call-rule-set` self-reference is refused; `settings#create`/`#load`
  reject a request without a valid CSRF token; the Docudesk preview strips an
  injected `<script>`/`<iframe>`; `createFromTemplate` enforces the rate limit.