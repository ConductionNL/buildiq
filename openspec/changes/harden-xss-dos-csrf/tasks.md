# Tasks — harden-xss-dos-csrf

Acceptance criteria and quality reminders are plain-text bullets (not checkboxes)
so the checkbox count stays within the supervisor cap.

## 0. SSRF (audit H2) — disable redirect following

- [x] 0.1 Set `allow_redirects => false` on the registry fetch in `RemoteTemplateStoreService::fetch` so a public host cannot redirect to a private/metadata address with the Bearer token.
- [x] 0.2 Test `testFetchDisablesRedirectFollowing` asserting the fetch options refuse redirects.

## 1. DoS — bound the rule-evaluation stack

- [x] 1.1 Add a max source-length check at the top of `FeelParser::parse()` and a shared recursion-depth counter threaded through the `parseX` methods; throw `InvalidArgumentException` past the cap (model constants on `AppRepoParser`).
- [x] 1.2 Add a recursion-depth guard and an AST-node-count cap to `ExpressionEvaluator::evaluate()`.
- [x] 1.3 Add a re-entry guard (depth counter + visited-slug set) to `RuleEngineService::evaluate()` so `call-rule-set` cannot recurse without bound; refuse fail-closed with no further side effects.
- [x] 1.4 Bound the evaluate `payload` size in `RulesController::evaluate` before logging, and cap `maskPii` recursion depth.
- [x] 1.5 Unit tests: over-length + over-depth expression rejected; self-referential and mutually-referential `call-rule-set` refused; oversized payload rejected before a `RuleExecutionLog` write.
  - Verifies business-rules-engine spec scenarios (all three requirements).

## 2. DoS — createFromTemplate parity

- [x] 2.1 Add `#[UserRateLimit]` and the creation-wizard authorization gate to `ApplicationsController::createFromTemplate`.
- [x] 2.2 Test: excess `createFromTemplate` calls are throttled; an unauthorized caller gets 403.

## 3. CSRF — remove unjustified NoCSRFRequired

- [x] 3.1 Remove `#[NoCSRFRequired]` from `SettingsController::create` and `::load`.
- [x] 3.2 Remove the `@NoCSRFRequired` docblock from `PreferencesController::setPreference` (keep `@NoAdminRequired`).
- [x] 3.3 Test: create / load / setPreference reject a request without a valid Nextcloud request token; the SPA path (token present) still succeeds.

## 4. XSS — sanitize the sinks

- [x] 4.1 Add `dompurify` as a dependency (pin; confirm it resolves and the SBOM/CI picks it up).
- [x] 4.2 Route `DocumentTemplateAttachmentDialog.vue` `previewContent` through `DOMPurify.sanitize(...)` (full HTML profile) before the `v-html` binding.
- [x] 4.3 Sanitize the verbatim-`<svg>` branch in `iconCatalogues.js::resolveAppIcon` with the SVG profile, before preview and before persistence.
- [x] 4.4 Vitest: an injected `<script>`/`onerror` in a Docudesk preview and in an author SVG is neutralized; benign markup/SVG is preserved.

## 5. Wrap-up

- [x] 5.1 Update the five capability specs (`business-rules-engine`, `settings-and-observability`, `buildiq-template-catalogue`, `docudesk-document-templates`, `app-icon-management`): add this change to their `**OpenSpec changes**` list and set status `in-progress`.
- [x] 5.2 Run the Hydra mechanical gates + PHP/JS lint and confirm green.
  - No changes to the 30+ already-CSRF-protected mutations; no OR schema/seed changes (per design.md).