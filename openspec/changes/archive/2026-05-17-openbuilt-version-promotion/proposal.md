---
kind: code
depends_on: ["openbuilt-versioning-model"]
---

## Why

ADR-002 and the foundation spec `openbuilt-versioning-model` (spec C) define the two-object
versioning model — `Application` + `ApplicationVersion` with a per-version register and an
admin-defined linear chain via `promotesTo`. That spec ships the schema, the deletion
endpoint, and the cycle/back-reference guards, but **the actual promotion flow** — moving
a manifest + schema set + (optionally) data from a source `ApplicationVersion` to its
single downstream `promotesTo` neighbour — is intentionally out of scope there. This spec
fills that gap: it lands the promotion dialog, the backend endpoint, and the three
admin-chosen data strategies (`start-with-source-data | migrate-existing-data |
empty-start`) so admins can iterate development → staging → production safely. Without
this spec the chain has structure but no flow; admins can create versions but cannot
move work between them.

## What Changes

- **NEW** `VersionPromotionController` at
  `POST /api/applications/{appUuid}/versions/{versionUuid}/promote` — accepts a
  `{strategy}` JSON body, delegates to the service, returns the updated target
  ApplicationVersion. Annotated `#[NoAdminRequired]` (auth is per-application RBAC, not
  Nextcloud admin).
- **NEW** `VersionPromotionService` — owns the three-way strategy switch
  (`start-with-source-data | migrate-existing-data | empty-start`), the OR-lock
  acquisition on the target ApplicationVersion, the schema-set forwarding to OR's
  schema-import / register-merge API, the data copy / wipe per strategy, the semver
  copy (target inherits source's semver), and the on-failure `archived` flip.
- **NEW** `src/dialogs/PromoteVersionDialog.vue` — modal dialog (`<NcDialog>`-based per
  ADR-004 modal-isolation rule) opened from a "Promote" action somewhere on the
  ApplicationVersion detail surface. Receives `{sourceVersion, targetVersion}` as props
  (target is the version pointed at by `sourceVersion.promotesTo`), emits
  `confirm({strategy})` / `cancel`. Defaults the strategy radio per ADR-002 chain-position
  rule (terminal/production target → `migrate-existing-data`; mid-chain → `start-with-source-data`;
  `empty-start` never default). For `empty-start`, requires the admin to type the
  app slug to enable the Confirm button (destructive-confirmation pattern).
- **NEW** `appinfo/routes.php` entry registering the promote endpoint.
- **NEW** Unit tests `tests/Unit/Service/VersionPromotionServiceTest.php` and
  `tests/Unit/Controller/VersionPromotionControllerTest.php`.
- **NEW** Newman/Postman integration test `tests/integration/promotion.postman_collection.json`
  exercising the happy path plus the 409 lock-contention and 422 missing-target edge
  cases.
- **CONTRACT** Schema changes inside the source's schema set are NOT pre-flighted by
  openbuilt — the promotion endpoint forwards the source's schema set to OR's
  schema-import / register-merge API for the target register, and OR's own breaking-change
  handling drives the outcome. No openbuilt-side schema-diff UI or dry-run step.
- **CONTRACT** Concurrency is enforced via OR object locking on the target
  ApplicationVersion row. If the lock is held, the endpoint returns `409 Conflict` with
  `{code: "version_locked", lockedBy, expiresAt}`. No openbuilt-side lock table.
- **CONTRACT** Permission check uses the existing `permissions.{owners,editors}`
  resolution on the parent Application; viewers and non-members get `403`. Nextcloud
  admins are NOT auto-granted (deliberate: admin power is for NC app management, not
  per-virtual-app promotion).
- **CONTRACT** Semver: on promotion to any target (production or mid-chain), the target's
  `semver` is replaced with the source's `semver`. The post-promotion minor-bump on the
  upstream source is an existing rule owned by `openbuilt-versioning-model` (semver
  auto-bump on next manifest change) — this spec does not introduce a new bump.

## Capabilities

### New Capabilities

- `version-promotion`: Move a manifest + schema set + (optionally) data from a source
  ApplicationVersion to its single `promotesTo` downstream, with admin-chosen data
  strategy and OR-lock-backed concurrency. Owns `VersionPromotionService`,
  `VersionPromotionController`, the `POST .../promote` route, and the
  `PromoteVersionDialog.vue` modal.

### Modified Capabilities

None. The `ApplicationVersion` schema is owned by `openbuilt-versioning-model` (spec C)
and is consumed here unchanged. The promotion endpoint reads + writes existing
ApplicationVersion fields (`manifest`, `register`, `semver`, `status`) via OR's standard
API; it adds no new schema properties.

## Impact

- **New PHP**:
  - `lib/Controller/VersionPromotionController.php`
  - `lib/Service/VersionPromotionService.php`
- **Modified PHP**:
  - `appinfo/routes.php` — register `POST /api/applications/{appUuid}/versions/{versionUuid}/promote`
- **New Vue**:
  - `src/dialogs/PromoteVersionDialog.vue`
- **New tests**:
  - `tests/Unit/Service/VersionPromotionServiceTest.php`
  - `tests/Unit/Controller/VersionPromotionControllerTest.php`
  - `tests/integration/promotion.postman_collection.json` (Newman runnable)
- **OpenRegister dependency** — uses OR's existing object-locking API, schema-import /
  register-merge API, and bulk object operations. Floor remains the `^v0.2.10` declared
  in `lib/Settings/openbuilt_register.json` from spec C.
- **No schema delta** — this spec does not edit `lib/Settings/openbuilt_register.json`.
- **Out of scope** (covered by sibling specs in the chain):
  - The "Promote" button surface on the detail page / version switcher — owned by
    `openbuilt-app-detail-overview` (spec B). For spec D the dialog component itself
    is shipped; call sites are deferred.
  - The `?version=<slug>` URL routing — owned by `openbuilt-version-routing` (spec E).
  - The creation wizard — owned by `openbuilt-app-creation-wizard` (spec F).
  - OR's schema-import / register-merge implementation — owned by openregister; this
    spec consumes the existing contract.
  - The post-promotion upstream-source semver minor-bump — owned by the existing
    auto-bump rule in `openbuilt-versioning-model` (spec C, REQ-OBV-103).
- **No backward compatibility concerns** — this is additive on top of the new versioned
  model. There is no legacy promotion endpoint to deprecate.
