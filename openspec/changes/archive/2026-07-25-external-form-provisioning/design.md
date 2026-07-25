## Context

OpenBuild previously built an entire public-form stack in-app (`public-forms-runtime`,
PR #8): `PublicFormController` (`#[PublicPage]`), `ShareTokenController` +
`ShareTokenService` (a home-grown token model), `PublicSubmissionService`, and a
`src/public-form.js` render entry point — 1,873 lines across PHP + JS, reverted whole in
PR #20 because every one of those concerns is already owned elsewhere: OpenRegister owns
anonymous object writes and RBAC-scoped public reads; Portaliq owns citizen-facing
rendering; OpenRegister's `CaseTokenService` already owns "track your case" links. ADR-022
("Apps Consume OpenRegister Abstractions") calls this pattern by name: an app-local
duplicate of an OR abstraction drifts, misses features, and blocks cross-app queries.

This change is the corrected shape: OpenBuild becomes a **thin configuration leaf**. It
never receives an anonymous HTTP request, never renders a citizen-facing page, and never
stores a token. It PATCHes an OR schema's `authorization` and writes one Portaliq
`portalPage` object — both through APIs that already exist (or, for the Portaliq leg, are
in active development in a sibling repo) — and shows the builder the resulting URLs.

## Goals / Non-Goals

**Goals:**

- A single toggle, per page/schema, that provisions anonymous OR create (+ optional read)
  and a Portaliq-rendered form, without OpenBuild writing or reading a single anonymous
  request itself.
- Merge-safe schema authorization edits — never clobber existing `read`/`update`/`delete`
  rules when adding `public` to `create`.
- A clean, reversible revoke path.
- An honest owner-context track-link minting action, not a pretend anonymous one.
- Manifest-carried state so the configuration versions/promotes/exports with the app
  (same home as `runtime.theme`).

**Non-Goals:**

- No `#[PublicPage]` controller, no anonymous route, no submission-handling code inside
  OpenBuild — ever. If a future requirement genuinely needs OpenBuild-side anonymous
  logic, that is a new ADR-022 exception, not an extension of this change.
- No `ShareToken` model. `CaseTokenService` is the one track-link primitive; OpenBuild
  never mints its own token format.
- No spam/abuse/rate-limit logic — `#[AnonRateLimit]` on the OR endpoint is the only rate
  limit in this contract; OpenBuild adds none.
- No automatic per-citizen-submission track-link minting (OQ-1) — out of scope, see
  Decision 4.
- No rendering of the form itself — Portaliq renders `portalPage` objects; OpenBuild never
  ships a Vue form-rendering component for anonymous use.

## Decisions

### Decision 1 — Provisioning state lives in the manifest as `runtime.externalForms[]`

```json
{
  "runtime": {
    "externalForms": [
      {
        "id": "ef-<uuid>",
        "pageId": "<page id owning the FormPageEditor config>",
        "register": "<register slug>",
        "schema": "<schema slug>",
        "status": "enabled",
        "publicRead": false,
        "organisationScope": null,
        "portalPage": { "objectId": "<portaliq portalPage uuid>", "portalPath": "/portal" },
        "trackLinkAction": { "enabled": false }
      }
    ]
  }
}
```

**Rationale**: this is app-composition state — exactly what `runtime` already holds
(`theme`, per `nldesign-theme-selection` REQ-NTS-001/004). An array (not a singular object
like `theme`) because an app can have more than one externally-fillable form across its
pages. Each entry's `id` is OpenBuild's own bookkeeping key (used for delta merge under
`app-delta-override`, and to find-or-update the linked `portalPage` object on repeat
saves); `portalPage.objectId` is the OR uuid so re-saving the toggle updates the same
Portaliq object instead of creating duplicates (closes OQ-3 for the common case — repeated
saves of the *same* toggle are idempotent; two *different* toggles on the same
register/schema still each get their own `portalPage` object per OQ-3's accepted default).

**Alternatives considered**:
- *A top-level `Application.externalForms` field (sibling to `permissions`)* — rejected:
  wouldn't travel through version snapshots/promotion/export the way `runtime` does.
- *No manifest storage; derive availability by querying the schema's live authorization at
  render time* — rejected: the dialog needs to show "this is configured" state without a
  round-trip to OR on every designer load, and delta/promotion needs an explicit,
  diffable record of intent (the schema's authorization is instance/environment state,
  not app-version state — a promoted app must be able to re-provision an equivalent
  authorization change on its target environment, which requires knowing what was
  intended).

### Decision 2 — Schema authorization is READ-MERGE-WRITE, never a partial fragment

`PATCH /api/schemas/{id}` delegates server-side to `SchemasController::update()`
(`lib/Controller/SchemasController.php`), which hydrates only the top-level keys present
in the request body (`schemaMapper->updateFromArray()` — other schema fields like
`properties`/`title` are left alone when omitted). But `authorization` itself is stored as
**one JSON column** — sending `{"authorization": {"create": ["public"]}}` REPLACES the
entire authorization object, silently deleting any existing `read`/`update`/`delete`
entries. The provisioning service MUST therefore: `GET /api/schemas/{id}` first, deep-copy
the existing `authorization` object, append `"public"` to `create` (and, if the builder
opted in, to `read`) without touching any other key or any other group already present,
then `PATCH` the **full merged** `authorization` object back. The same rule applies in
reverse on revoke: remove `"public"` from `create`/`read` if present, leave everything
else byte-identical.

**Rationale**: this is the schema-level twin of the object-level "OR saveObject is
PUT-semantic" gotcha already load-bearing elsewhere in the fleet — the failure mode
(silently deleting sibling authorization rules some other app or admin configured) is
severe and easy to introduce by accident (`{authorization: {create: [...]}}` "looks"
additive but is not). Making this an explicit, tested design decision — not an
implementation afterthought — is the point of writing it down here.

### Decision 3 — The provisioning service is a Vue module calling OR/Portaliq REST directly, not a new OpenBuild PHP proxy

`externalFormProvisioningService.js` calls `PATCH /apps/openregister/api/schemas/{id}` and
`POST`/`PUT /apps/openregister/api/objects/portaliq/portalPage` directly from the
browser, riding the builder's own NC session — exactly the pattern OpenBuild already uses
fleet-wide (`ThemePickerDialog.vue` → nldesign; `useObjectStore`/objectStore composable →
OR objects API from `ExportJobsList.vue`, `RuleSetsPage.vue`, `useDataImport.js`, etc. —
"Uses OpenRegister API directly from frontend" is an explicit repo architecture rule).

**Rationale**: a PHP proxy controller in OpenBuild would (a) be exactly the kind of
redundant pass-through ADR-022/the `redundant-controller` gate flags, (b) require its own
`#[NoAdminRequired]` route + auth posture to get right, and (c) buys nothing — the
builder's session already carries the permissions OR's own endpoints check
(`checkSchemaManagePermission()` on schemas; standard OR object-create permission on
`portalPage`). No new attack surface, no new code to secure.

**Alternatives considered**:
- *PHP-side provisioning via a new `ExternalFormProvisioningController`* — rejected per
  above; also reintroduces exactly the kind of OpenBuild-owned server logic this change
  exists to avoid.

### Decision 4 — Track-link minting is an owner-context action, not an anonymous-submission side effect

`useTrackLinkAction.js` mints a CaseToken for an object the builder/staff member can
already see (via whatever data-register object view OpenBuild renders for that schema),
calling `POST /api/objects/{register}/{schema}/{id}/integrations/shares` with
`{type: "public-token", label?, ttlSeconds?}`. It is never invoked from an anonymous
context and never invoked automatically when a citizen's object is created.

**Rationale**: `CaseTokenService::mint()` throws `InvalidArgumentException` when
`IUserSession::getUser()` is null — minting a token is *itself* a write that establishes a
public surface, and OR deliberately restricts it to authenticated callers
(`CaseTokenService.php:136-139`, comment: "Minting is a write that establishes a public
surface — it MUST be performed by an authenticated user, never anonymously"). There is
today no automatic mint-on-anonymous-create path anywhere in the fleet (verified: no
caller of `CaseTokenService::mint()` outside `SharesProvider::create()`, which itself is
only reachable via the authenticated Tier-2 integration route). Building one would be a
new OR (or Portaliq) capability, not something OpenBuild can add by calling an existing
endpoint differently — hence OQ-1 is genuinely deferred, not resolved.

**Alternatives considered**:
- *OpenBuild polls newly-created objects and auto-mints a token for each* — rejected: adds
  a background job, a "who gets emailed" decision, and duplicate-mint risk, all outside
  this change's thin-leaf scope. If this is wanted, it belongs as a declarative
  `x-openregister-flows`/notification behaviour on the schema (ADR-031), not app code.

### Decision 5 — The OR leg and the Portaliq leg provision independently; Portaliq's dependency is not a merge gate

`portal-page-provisioning` has not merged in Portaliq as of this change. The provisioning
service attempts the OR schema-authorization PATCH unconditionally; the `portalPage`
object write is attempted separately and, if the `portalPage` schema does not yet exist on
the instance (`404`/`schema not found` from OR when addressing `portaliq/portalPage`), the
dialog surfaces "Portaliq rendering not available on this instance yet" and stores
`portalPage: null` in the manifest entry — the raw OR public-create URL is still shown and
still fully functional. Re-saving the toggle later (once Portaliq's change has shipped and
the schema exists) retries the Portaliq leg and fills in `portalPage`.

**Rationale**: blocking this entire change on another repo's unmerged PR would leave
OpenBuild without *any* externally-fillable-form capability in the interim, when the
OR-only leg is independently complete and useful (a raw public POST endpoint, embeddable
from any external form/website). Degrading gracefully mirrors the exact pattern
`nldesign-theme-selection` Decision 4 established for a missing dependency (nldesign
absence → default styling, never a hard block).

## Risks / Trade-offs

- **Authorization drift**: if a schema's authorization is edited by another app/admin
  between OpenBuild's GET and PATCH (a race), the merge could silently re-add a
  since-removed group. Accepted for v1 (the same race exists for every OR schema editor
  today); mitigated by the PATCH always being a small, auditable diff
  (`AuthorizationAuditService::logSchemaAuthorizationChange()` already logs every schema
  authorization change, providing a trail).
- **Portaliq dependency**: see Decision 5 — the Portaliq leg can be temporarily
  unavailable. Mitigated by graceful degradation; not mitigated is the UX cost of a
  "half-provisioned" state the builder must re-visit later.
- **Duplicate `portalPage` objects** across independently-toggled pages targeting the same
  `(register, schema)` (OQ-3) — accepted, not merged; a future change could add
  dedup-by-target if this proves confusing in practice.
- **No spam/abuse defence beyond `#[AnonRateLimit]`**: a schema made publicly writable is
  publicly writable — OpenBuild's toggle is a loaded switch. Mitigated by the dialog
  requiring an explicit confirm step and showing the resulting public URL(s) plainly
  before save (never a silent background provision).

## Migration Plan

Purely additive — no existing manifest, schema, or object changes for apps that never use
the toggle. No data migration. Nothing to roll back beyond the new files themselves.

## Open Questions

See proposal.md's Open Questions (OQ-1/2/3) — carried here for traceability, not
duplicated.
