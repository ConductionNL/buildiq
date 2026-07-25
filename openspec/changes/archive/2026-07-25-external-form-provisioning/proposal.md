---
kind: code
depends_on: []
---

## Why

PR #20 reverted `public-forms-runtime` (PR #8): a public/citizen form surface, a
`ShareToken` model, and an anonymous-write service built entirely inside
OpenBuild. The revert commit (`f8b6612a2`) says why — "Citizen-facing rendering
is Portaliq's domain, and external-user object creation already exists in
OpenRegister (CaseTokenController + FormLinkService). Removed entirely; will be
rebuilt correctly later as a thin leaf." This change is that rebuild.

The three primitives OpenBuild needs already exist, owned elsewhere:

- **OpenRegister anonymous object create** — `POST /api/objects/{register}/{schema}`
  (`ObjectsController::create()`, `lib/Controller/ObjectsController.php:2598`) already
  carries `#[PublicPage]` + `#[AnonRateLimit(limit: 30, period: 60)]`. It is reachable
  anonymously today, gated purely by the target schema's `authorization.create` array
  containing the `public` group (`lib/Db/Schema.php:1007-1157`; see e.g.
  `lib/Settings/bag_register.json:126` for the `{"read": ["public"]}` shape). A
  successful create fires `ObjectCreatedEvent` (`lib/Event/ObjectCreatedEvent.php`), so
  anything a schema declares via `x-openregister-flows`/notifications already runs —
  automatic, not OpenBuild's concern.
- **Portaliq's `portalPage` provisioning** (`portaliq/openspec/changes/portal-page-provisioning`,
  in progress, not yet merged) — a `portalPage` OpenRegister object (register `portaliq`,
  schema `portalPage`) with `collections[]`/`actions[]` entries that can carry
  `anonymous: true`. Once shipped, `PortalContributionRegistry::aggregateAnonymous()`
  and an anonymous branch on `ContributionController::create()`
  (`POST /apps/portaliq/portal/api/collections/{register}/{schema}`) render the page at
  Portaliq's single SPA entry point (`GET /apps/portaliq/portal`,
  `appinfo/routes.php:27`) with zero Portaliq PHP per page.
- **OpenRegister CaseToken "track your case" links** — `CaseTokenService::mint()`
  (`lib/Service/CaseTokenService.php:124`), reachable today via the generic Tier-2
  integration route `POST /api/objects/{register}/{schema}/{id}/integrations/shares`
  with body `{type: "public-token", label?, ttlSeconds?}`
  (`SharesProvider::create()`, `lib/Service/Integration/Providers/SharesProvider.php:393-421`).
  `mint()` throws unless `IUserSession::getUser()` resolves — it is an **authenticated,
  owner-context** write by design (`CaseTokenService.php:136-139`); the resulting token
  resolves anonymously and RBAC-scoped at `GET /api/public/case-tokens/{token}`
  (`CaseTokenController::resolve()`).

None of this exists inside OpenBuild today, and none of it should be rebuilt there.
What's missing is the **glue**: a builder-facing toggle that provisions these three
primitives for a schema OpenBuild already manages, and surfaces the resulting public
URLs. That is a configuration action, not a runtime one.

## What Changes

- **NEW** manifest declaration: `runtime.externalForms[]` — an array of provisioning
  records, one per page/schema a builder has made externally fillable, carried in the
  app's manifest so it versions, promotes, and exports with the app exactly like
  `runtime.theme` does today (`nldesign-theme-selection` REQ-NTS-004 precedent).
- **NEW** `src/dialogs/ExternalFormAccessDialog.vue` — standalone dialog (modal-isolation
  rule) opened from a new "External access" section in
  `src/components/page-editor/FormPageEditor.vue`, offered only when the page's
  `submitEndpoint` resolves to an OR `/api/objects/{register}/{schema}` target. Lets the
  builder enable/disable public create (+ optional public read, + optional
  `_organisation` scoping), preview the Portaliq page that will render the form, and
  toggle whether a "mint track-link" action is exposed later on submitted objects.
- **NEW** `src/services/externalFormProvisioningService.js` — the provisioning logic:
  reads the target schema, merge-patches its `authorization.create` (and optionally
  `read`) to include `public` via OR's existing `PATCH /api/schemas/{id}`, then
  creates/updates a `portalPage` object via OR's existing objects API
  (`POST`/`PUT /api/objects/portaliq/portalPage`) with an `anonymous: true` `type:create`
  action bound to the same `(register, schema)`. Both calls ride the builder's own NC
  session (owner-context), exactly how `src/dialogs/ThemePickerDialog.vue` already calls
  nldesign's endpoints directly from Vue.
- **NEW** `src/composables/useTrackLinkAction.js` — a thin, owner-context wrapper around
  `POST /api/objects/{register}/{schema}/{id}/integrations/shares` (`{type:
  "public-token"}`) that a data-register object list/detail view can call to mint a
  "track your case" link for one already-created object and copy it for the builder/staff
  member to relay to the citizen. **Not** invoked anonymously and **not** invoked
  automatically per citizen submission — `CaseTokenService::mint()` structurally cannot
  run without an authenticated session (see design.md Decision 4 / Open Question OQ-1).
- **NEW** revoke/disable path: turning the toggle off removes `public` from the schema's
  `authorization.create`/`read` arrays (leaving every other group untouched) and sets the
  linked `portalPage` object's `status` to `draft`.
- **NO** new OpenBuild PHP controller of any kind. **NO** `#[PublicPage]` route in
  OpenBuild. **NO** `ShareToken` model (deleted for good in the PR #20 revert; not
  reintroduced). **NO** submission handling, rendering, or anonymous-write logic inside
  OpenBuild — every anonymous surface is OR's or Portaliq's.

### Capabilities

#### New Capabilities

- `external-form-provisioning`: the manifest `runtime.externalForms[]` declaration, the
  `ExternalFormAccessDialog` builder UI, the provisioning service that PATCHes an OR
  schema's authorization and writes a Portaliq `portalPage` object, the revoke/disable
  path, and the owner-context track-link minting action.

#### Modified Capabilities

_None._ `openbuild-page-designer`'s `FormPageEditor.vue` gains an additive "External
access" section; no existing requirement's behaviour changes.

## Impact

- **New frontend code only** — dialog, provisioning service, track-link composable,
  manifest-validation module, FormPageEditor section. Zero new PHP, zero new OpenBuild
  routes.
- **External dependency (not yet merged, flagged not assumed)**: this change's Portaliq
  leg depends on `portaliq/openspec/changes/portal-page-provisioning` shipping the
  `portalPage` schema + `anonymous: true` action support. Until it merges, OpenBuild's
  provisioning service degrades to the OR-only leg (raw public POST URL) with the
  Portaliq write skipped and a clear "Portaliq rendering not yet available" state in the
  dialog (see design.md Decision 5).
- **Integration contract (pinned to existing/flagged surfaces, no invented API)**:
  1. `GET`/`PATCH /api/schemas/{id}` (OpenRegister, existing) — read-merge-write the
     `authorization` block.
  2. `POST`/`PUT /api/objects/portaliq/portalPage` (OpenRegister objects API against
     Portaliq's register, existing generic path; the `portalPage` schema itself ships in
     the flagged Portaliq change).
  3. `POST /api/objects/{register}/{schema}/{id}/integrations/shares` (OpenRegister,
     existing, `SharesProvider`) — track-link minting, owner-context only.
  4. Read-only reference URLs surfaced to the builder: the raw public
     `POST /api/objects/{register}/{schema}` endpoint, `GET /apps/portaliq/portal`, and
     `GET /api/public/case-tokens/{token}` — OpenBuild never calls these itself; they are
     for the builder to copy/relay.
- **Security**: OpenBuild writes configuration only (schema authorization, a `portalPage`
  object). Every anonymous-reachable surface — the public create endpoint's
  `#[AnonRateLimit]`, the public-safe RBAC read path, Portaliq's anonymous-action
  whitelist, the CaseToken resolve endpoint's fail-closed 404 — is enforced entirely
  inside OR/Portaliq, not duplicated here.

## Open Questions

- **OQ-1**: How does a citizen actually receive a track-link for an object they created
  anonymously, given `CaseTokenService::mint()` requires an authenticated session? This
  change scopes the answer narrowly: a builder/staff member mints one manually from an
  object they can already see (via `useTrackLinkAction.js`) and relays it out-of-band
  (email, phone). Automatic per-submission minting would require either an OR-side
  system-context minting path or a Portaliq-side auto-mint-on-anonymous-create feature —
  both out of scope here; flagged for a follow-up change on whichever side is judged
  correct to own it.
- **OQ-2**: Should the dialog block Save until the Portaliq leg is confirmed reachable
  (`portal-page-provisioning` shipped), or allow OR-only provisioning as a valid interim
  state? This change chooses the latter (design.md Decision 5) so OpenBuild is not
  blocked on a dependency it does not control merging first.
- **OQ-3**: Multiple external-form toggles targeting the *same* `(register, schema)` from
  different pages/apps — do they share one `portalPage` object or each get their own?
  Deferred; v1 assumes one `portalPage` object per toggle (1:1 with the manifest's
  `externalForms[]` entry), duplication accepted as the simple, unsurprising default.
