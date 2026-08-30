---
kind: reference
status: informational
scope: Buildiq app own code (branch development)
method: three read-only enumeration sweeps (XSS / DoS / CSRF), findings spot-verified
---

# Attack-surface map — XSS · DoS · CSRF

Evidence backing `proposal.md`. Enumerates every surface found in each category,
ranked by real-world risk. Line numbers are from `development` at the time of the
sweep; re-locate by symbol if they drift.

**Framing:** app/rule *authoring* is effectively admin-only today (see the sibling
change `harden-app-ownership-authz/discovery.md`), so several "any authed user"
vectors require an admin-planted artifact to arm. The real caller pool is noted
per row.

## XSS — 5 `v-html` sinks, 1 genuinely dangerous

No HTML/SVG sanitizer is a dependency of the app at all (`dompurify` /
`sanitize-html` absent from `package.json`); every `v-html` relies on Nextcloud's
page CSP. No `innerHTML` / `insertAdjacentHTML` / `document.write` /
`dangerouslySetInnerHTML` anywhere in `src/`. No server-side HTML emission.

| Rank | Sink | Source (traced) | Cross-user? | CSP mitigation | Risk |
|---|---|---|---|---|---|
| 1 | `src/dialogs/DocumentTemplateAttachmentDialog.vue:78` (`previewContent`, set :308) | `data.html \|\| content \|\| preview` from the Docudesk `templates/{id}/preview` endpoint, unsanitized | **Yes** — template authored by user A renders in user B's authenticated session | Inline script/handlers blocked; HTML/CSS/`<iframe>`/`<form>`/`javascript:` phishing + defacement survive | **High** (relative) |
| 2 | `src/dialogs/CreateApplicationWizard/Step1Basics.vue:120` (`lightPreview`) | own upload/picker → `iconCatalogues.js:108` returns verbatim `<svg…>` | No — self only | inline SVG script blocked | **Low** (self-XSS) |
| 3 | `…/Step1Basics.vue:125` (`darkPreview`) | same | No | same | **Low** |
| 4 | `…/Step4Review.vue:56` (`lightIconSvg`) | same | No | same | **Low** |
| 5 | `…/Step4Review.vue:61` (`darkIconSvg`) | same | No | same | **Low** |

**Why 2–5 are self-only:** the persisted icon is served to other users as
`<img src="/apps/buildiq/icons/{slug}.svg">` (never `v-html`), and
`IconController::buildIconResponse` serves it with `Content-Security-Policy:
default-src 'none'` + `X-Content-Type-Options: nosniff`. The `v-html` exposure is
confined to the author's own preview. (This corrects an earlier note that treated
the wizard SVG sinks as a wider cross-user regression.)

## DoS — two Highs, one net-new vs. the original audit

The FEEL parser, AST evaluator, and `call-rule-set` dispatcher recurse with **no**
depth counter, cycle detection, input-length cap, or node-count cap. The only
guard is `RuleEngineService::TIMEOUT_MS = 500`, measured *after* evaluation
(`RuleEngineService.php:159-163`) — it cannot interrupt, and `catch(Throwable)`
cannot catch a C-level stack-overflow fatal.

| Rank | Vector | Trigger (real pool) | Existing bound | Worst case |
|---|---|---|---|---|
| 1 | **`call-rule-set` infinite recursion** — `RuleActionDispatcher.php:293` ↔ `RuleEngineService::evaluate` (`:150`) ↔ `ConditionActionExecutor::runActions`; no depth/visited-slug guard | any authed user calls `evaluate`, but needs an editor-authored self/mutually-referential rule set | `#[UserRateLimit(60/60)]` — one request triggers it | worker crash + per-level `RuleExecutionLog` writes + outbound webhook amplification |
| 2 | **FEEL parse/eval: no length/depth/node cap** — `FeelParser.php:281-501` (parens re-enter `parseOr` :481), `ExpressionEvaluator.php:94-137` | rule author (editor ≈ admin today), then any evaluate caller | 500 ms post-hoc timer (cannot interrupt) | stack fatal / memory exhaustion |
| 3 | Unbounded `payload` logged every evaluate — `RulesController.php:103`, `RuleEngineService.php:242,258`; recursive `maskPii` :301 uncapped | **any authed user** | rate 60/60 | DB storage / write load |
| 4 | `createFromTemplate` no admin gate, no rate limit — `ApplicationsController.php:1078` (the wizard doing the same fan-out *is* gated + `#[UserRateLimit(10/3600)]`) | **any authed user** | none | register/schema sprawl |
| 5 | `testAll` fetches unbounded test cases — `RulesController.php:258` (`limit: null`) | any authed user | rate 20/60 | CPU burn (×#2) |
| 6 | AppOverride delta writes: no rate limit + uncapped recursive validator — `AppOverrideController.php:234,447`, `AppOverrideDeltaValidator.php:144-245` | any Buildiq user | framework json depth 512 | CPU/stack + write load |
| 7 | MCP write tools bypass `#[UserRateLimit]` — `BuildiqToolProvider.php:290` (known L3) | admin/editor | manifest caps (256 KB / 100 pages / 30 menu / 50 widgets) + authz | fan-out (capped) |
| 8 | GitHub/Store/Shop egress endpoints, no rate limit — `GitHubSyncController.php:142` etc. | owner/authed | client timeouts | egress amplification |

**Notes.** Vector 1's `RuleActionDispatcher` was "WIP-branch only" in the original
audit (finding I1) but has since merged into `development` — the "re-audit before
merge" note is now overdue. Confirmed **non-issues**: `CopilotPlanValidator.php:239`
regex is not user-controlled (no ReDoS); `AppRepoParser` is properly bounded
(`MAX_FILE_BYTES = 1 MB`, `MAX_JSON_DEPTH = 64`, `JSON_THROW_ON_ERROR`) — the model
to copy.

## CSRF — 3 unjustified `#[NoCSRFRequired]` on state-changing endpoints

Every other non-GET mutation (30+ endpoints across AppOverride, Rules, Store,
Shop, Dashboard, GitHubSync, Automations, ApplicationPublish, VersionPromotion,
ApplicationVersions, saveManifest, wizard, setup) is CSRF-protected.
`ExportsController` is confirmed correct: `submit` protected, `download` GET
justified (idempotent `<a href>`, auth + IDOR-gated with 404 masking).

| Rank | Endpoint | Why it's a hole | Forged-request impact |
|---|---|---|---|
| 1 | `SettingsController::create` — POST `/api/settings`, `:104` | `#[NoCSRFRequired]` on a config write taking all request params; admin body-gate ≠ CSRF defense (forged request rides the admin's own session) | Silently rewrite `registry_url` / `registry_token` → **point the template store at an attacker catalogue** (supply-chain lever). Worst of the three |
| 2 | `SettingsController::load` — POST `/api/settings/load`, `:143` | `#[NoCSRFRequired]` on register/schema re-provision | Disruptive re-import against an admin session (no body params → lower integrity impact) |
| 3 | `PreferencesController::setPreference` — PUT, docblock `@NoCSRFRequired` `:104` (honored on NC 35) | net-new (not in the audit) | Low — flip the victim's own per-user UI flags; no cross-user/config reach |

## Related (already applied, separate)

- **SSRF redirect follow (audit H2)** — `RemoteTemplateStoreService::fetch` now sets
  `allow_redirects => false`; test `testFetchDisablesRedirectFollowing` added.
  Applied ahead of this change; listed here for a complete security picture.