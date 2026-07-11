## ADDED Requirements

### Requirement: Copilot availability is probed and the feature degrades gracefully

The system SHALL expose `GET /api/copilot/health` returning **200** when the
server supports Task Processing (NC ≥ 30, `OCP\TaskProcessing\IManager`
available) **and** a `TextToText` provider is configured, and **503** with a
machine-readable `reason` (`unsupported_server` | `no_provider`) otherwise.
Every copilot entry point (wizard button, builder panel toggle) SHALL be
hidden when health is not 200. When health is 503 and the current user is an
NC admin, the wizard's Step 1 SHALL show a hint pointing at the Nextcloud AI
provider settings; non-admins see no copilot trace at all.

**ID:** REQ-OBAIC-001

#### Scenario: Health reports 200 with a configured provider

- **WHEN** the server runs NC 30+ and a `TextToText` TaskProcessing provider
  is configured
- **THEN** `GET /api/copilot/health` returns 200 with `{ "status": "ok" }`

#### Scenario: Health reports 503 without a provider

- **WHEN** no `TextToText` provider is configured (or the server predates
  `OCP\TaskProcessing`)
- **THEN** `GET /api/copilot/health` returns 503 with
  `reason: "no_provider"` (or `"unsupported_server"`)
- **AND** no copilot write endpoint performs any work in this state —
  `POST /api/copilot/plan` and `/api/copilot/execute` also return 503

#### Scenario: Entry points hidden and admin hint shown when unavailable

- **WHEN** `GET /api/copilot/health` returns 503 and a user opens the
  Create-application wizard and the page designer
- **THEN** neither the "Generate with AI" button nor the copilot panel
  toggle is rendered
- **AND** an NC admin additionally sees a hint in wizard Step 1 linking to
  the Nextcloud AI provider settings, while a non-admin sees no hint

### Requirement: A natural-language brief produces a validated builder-operation plan

`POST /api/copilot/plan` SHALL accept `{ brief, appSlug? }` (brief 1–2000
chars; `appSlug` optional kebab-case slug of an existing target app), call
the LLM through `OCP\TaskProcessing` (`TextToText`) with a constrained
system prompt embedding the tool catalogue, and return a plan
`{ summary, steps[] }` where every step is
`{ tool, arguments }` with `tool` restricted to the eight allow-listed
operations (`openbuild.createApp`, `openbuild.upsertSchema`,
`openbuild.upsertPage`, `openbuild.addWidget`, `openbuild.upsertMenuItem`,
`openbuild.promoteVersion`, `openbuild.listApps`,
`openbuild.getAppManifest`) and `arguments` valid against that tool's
`inputSchema` from `OpenBuildToolProvider::getToolDescriptors()`. Unparsable
LLM output SHALL trigger exactly one repair round-trip; a second failure
SHALL return **422** `plan_invalid` with a user-safe message. Planning SHALL
perform **zero writes**.

**ID:** REQ-OBAIC-002

@e2e exclude nondeterministic-LLM backend contract — plan parsing, the
single repair retry, allow-list enforcement and the zero-writes guarantee
are verified by PHPUnit with a mocked TaskProcessing manager
(`tests/Unit/Service/CopilotServiceTest.php`,
`tests/Unit/Service/CopilotPlanValidatorTest.php`); the user-visible
plan-render path is covered by the wizard and panel Playwright specs under
REQ-OBAIC-006/007.

#### Scenario: A brief yields an allow-listed, schema-valid plan

- **WHEN** a user posts `{ "brief": "A tool library: members borrow tools" }`
  and the mocked LLM returns a plan containing `createApp`, two
  `upsertSchema`, two `upsertPage` and one `upsertMenuItem` step
- **THEN** the response is 200 with that `summary` and `steps[]`, every step
  passing the corresponding tool `inputSchema`
- **AND** no Application, ApplicationVersion, schema or manifest was written

#### Scenario: A step outside the allow-list is rejected

- **WHEN** the LLM output contains a step `{ "tool": "openbuild.deleteApp" }`
  (not in the catalogue) or `upsertPage` arguments missing the required
  `route`
- **THEN** the endpoint returns 422 `plan_invalid` naming the offending step
  index and nothing is applied

#### Scenario: Unparsable output gets exactly one repair retry

- **WHEN** the LLM returns non-JSON prose twice in a row
- **THEN** the service issues exactly one repair re-prompt, then returns 422
  `plan_invalid` — never a third LLM call

### Requirement: The plan response carries a predicted manifest for review and validation

For every ApplicationVersion a plan would mutate, the plan response SHALL
include the **predicted manifest** (the current manifest with all
manifest-mutating steps applied in memory) alongside the current one. The
server SHALL enforce the manifest caps (256 KB, 100 pages, 30 menu items,
50 widgets per page) on the predicted manifest at plan time. The frontend
SHALL validate each predicted manifest with the canonical manifest v2
validator (`validateManifest` from `@conduction/nextcloud-vue`) and SHALL
keep the Approve action disabled while any predicted manifest is invalid —
failed validation means nothing can be applied.

**ID:** REQ-OBAIC-003

#### Scenario: Review shows the operations and a manifest diff

- **WHEN** a plan targeting an existing app returns with a predicted
  manifest
- **THEN** the review UI lists every proposed step (tool + key arguments)
  and renders a before/after manifest diff

#### Scenario: A v2-invalid predicted manifest blocks approval

- **WHEN** the predicted manifest fails the canonical manifest v2 validator
- **THEN** the Approve action is disabled and the validation errors are
  shown to the user, and no execute request can be sent for that plan

#### Scenario: A cap-busting plan is rejected server-side

- **WHEN** a plan's predicted manifest would exceed 100 pages
- **THEN** `POST /api/copilot/plan` returns 422 naming the violated cap
  (verified by PHPUnit; no UI surface renders a plan that was never
  returned)

### Requirement: An approved plan executes atomically through the MCP handler layer

`POST /api/copilot/execute` SHALL accept the reviewed plan verbatim,
re-validate it server-side (allow-list, per-tool `inputSchema`, predicted
caps — the server never trusts the client's review), snapshot the manifest
of every ApplicationVersion the plan touches, and dispatch each step in
order through `OpenBuildToolProvider::invokeTool()` — the same handler
classes, RBAC checks, OR object locks and caps as the MCP surface, with no
duplicated builder logic. On any step failure the service SHALL restore all
snapshotted manifests, delete an application created by this plan (via
`ApplicationDeletionService`), and return **422** with the failed step index
and the handler's error envelope — a failed plan leaves no plan-created
state behind. On success it SHALL return the ordered per-step results.

**ID:** REQ-OBAIC-004

@e2e exclude backend atomicity contract — snapshot/rollback, created-app
compensation, step ordering and the invokeTool dispatch (asserting the same
handler instances run) are verified by PHPUnit with mocked
ObjectService/handlers (`tests/Unit/Service/CopilotServiceTest.php`); the
happy execute path is exercised end-to-end by the wizard and panel
Playwright specs under REQ-OBAIC-006/007, which create and mutate real apps.

#### Scenario: A successful plan applies every step and reports per-step results

- **WHEN** an approved 5-step plan executes and every handler succeeds
- **THEN** the response lists 5 ordered step results (each the handler's
  success payload) and the target app's manifest contains the new pages,
  menu items and schemas

#### Scenario: A mid-plan failure rolls everything back

- **WHEN** step 4 of a 5-step plan returns `isError` from its handler
- **THEN** the manifests of all touched versions are restored to their
  pre-plan snapshots, an app created in step 1 is deleted, and the response
  is 422 carrying the failed step index and the handler's error message

#### Scenario: Execution reuses the handlers, not a copy

- **WHEN** an `upsertPage` step executes with a `javascript:` route
- **THEN** it is rejected by `UpsertPageHandler`'s own route-injection guard
  (issue #167) — proving the copilot path runs the identical handler
  validation

### Requirement: Copilot writes are RBAC-guarded like the wizard and the MCP tools

Plan and execute requests targeting an **existing** application SHALL
require the caller to hold an owners or editors role on that Application
(admin bypass permitted and logged, matching
`AbstractToolHandler::requireWriteRole`); otherwise the endpoint SHALL
return 403 with a user-safe message. Plans containing `createApp` SHALL
require the same permission as the creation wizard: any authenticated user
(`#[NoAdminRequired]`), with the caller becoming owner of the created
application. Both controller methods SHALL declare their auth posture via
Nextcloud attributes, and the endpoints SHALL reject **hybrid** target apps
with 422 `unsupported_target` (copilot edits virtual apps only).

**ID:** REQ-OBAIC-005

@e2e exclude authorization matrix — role-denied 403, admin-bypass logging,
caller-becomes-owner and the hybrid-app rejection are verified by PHPUnit
(`tests/Unit/Controller/CopilotControllerTest.php`,
`tests/Unit/Service/CopilotServiceTest.php`) with role fixtures that
Playwright's single-admin global-setup cannot represent; the authed happy
path is implicitly covered by the REQ-OBAIC-006/007 e2e specs.

#### Scenario: A viewer cannot execute against someone else's app

- **WHEN** a user holding only a viewers role on app `tool-library` posts an
  execute request targeting it
- **THEN** the response is 403 and no step runs

#### Scenario: Creation requires only authentication and grants ownership

- **WHEN** an authenticated non-admin user executes a plan whose first step
  is `createApp`
- **THEN** the app is created with that user in its owners permission
  bucket, mirroring the wizard's behaviour

#### Scenario: A hybrid app is not a valid target

- **WHEN** a plan request names a hybrid application as `appSlug`
- **THEN** the response is 422 `unsupported_target` and no LLM call is made

### Requirement: The creation wizard offers a prompt-to-app path

Step 1 of `CreateApplicationWizard.vue` (`Step1Basics.vue`) SHALL render a
health-gated **"Generate with AI"** button that opens the standalone
`CopilotGenerateDialog` (`NcModal` in `src/dialogs/`, per the
modal-isolation gate). The dialog flow SHALL be: describe the app (brief
textarea) → generating state → review the proposed plan (summary, proposed
schemas / pages / menu items as a step list, canonical-validator verdict) →
**Confirm & create** → execute → route to the created application, closing
the wizard. Cancel/discard at any stage SHALL apply nothing.

**ID:** REQ-OBAIC-006

#### Scenario: Generate with AI creates the described app after confirmation

- **WHEN** a user opens the wizard, activates "Generate with AI", enters a
  brief, and the plan review shows a `createApp` step plus schemas and pages
- **AND** the user activates "Confirm & create"
- **THEN** the app is created with those schemas and pages, the wizard
  closes, and the browser navigates to the new application

#### Scenario: Cancelling the review applies nothing

- **WHEN** a user generates a plan in the dialog and then cancels instead of
  confirming
- **THEN** no execute request is sent and no application is created

#### Scenario: The button is absent without a provider

- **WHEN** `GET /api/copilot/health` returns 503
- **THEN** Step 1 renders the manual wizard exactly as today, with no
  "Generate with AI" button

### Requirement: The builder offers a copilot side panel with approve-before-apply

`PageDesignerHost.vue` SHALL render a health-gated toolbar toggle that opens
`CopilotPanel.vue` — a chat-style side panel scoped to the currently edited
virtual application and version. Each user message SHALL produce one
assistant turn whose proposed operations render as a `CopilotProposal` card:
the step list plus a manifest diff (reusing `ManifestDiff.vue`) with
**Approve** and **Discard** actions. The panel SHALL send an execute request
only on Approve — the copilot SHALL NOT mutate any manifest, schema or
application without an explicit approval (no silent mutations), and a
Discarded proposal SHALL leave no trace in the app.

**ID:** REQ-OBAIC-007

#### Scenario: Approving a proposal applies it to the open app

- **WHEN** the user asks the panel to "add a suppliers page with a table
  widget" and approves the proposed operations
- **THEN** an execute request runs those steps and the designer's manifest
  now contains the new page and widget

#### Scenario: Discarding a proposal changes nothing

- **WHEN** the user discards a rendered proposal
- **THEN** no execute request is sent and the app's manifest is unchanged

#### Scenario: No write happens before approval

- **WHEN** a proposal is rendered in the panel and the user has not yet
  acted on it
- **THEN** no request to `/api/copilot/execute` (nor any manifest PUT) has
  been issued since the proposal was requested
