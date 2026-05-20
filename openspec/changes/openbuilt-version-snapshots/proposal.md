---
kind: code
depends_on: ["openbuilt-versioning-model"]
---

## Why

Chain spec `openbuilt-versioning` originally described a snapshot model (REQ-OBV-001 –
REQ-OBV-006) where every `draft → published` transition of an `Application` spawned a
sibling `ApplicationVersion` row. ADR-002 retired that append-only-snapshot model
entirely: OR object time-travel on `ApplicationVersion` rows replaces snapshot history,
`Application.productionVersion` replaces the denormalised `currentVersion` pointer, and
the writeback listener is deleted. The versioning-model change (`openbuilt-versioning-model`)
ships the new two-object schema and the green-field migration; it also documents which
original requirements are retired or modified.

The one original requirement that **remains deliverable** after ADR-002 is the diff
endpoint (REQ-OBV-005). The archived versioning-model spec updates its shape: instead of
diffing two ApplicationVersion UUIDs from a snapshot collection, the endpoint now diffs
two `ApplicationVersion` rows by their admin-defined version slug (e.g. `development`
vs `production`) **or** two historical OR object-history revisions of a single version
(e.g. `history:staging:r5` vs `history:staging:r9`). This capability lets builders and
admins compare manifests across versions or over time without a second round-trip, driving
the diff-viewer component that sibling UX specs depend on.

Without this spec, `openbuilt-versioning-model` leaves the diff surface completely
unimplemented: the URL is undefined, the controller method does not exist, and the
frontend diff component has no API to call. This spec closes that gap.

## What Changes

- **NEW** `ApplicationVersionsController::diffVersions()` — backs
  `GET /api/applications/{slug}/versions/diff?from={fromRef}&to={toRef}` where `{fromRef}`
  and `{toRef}` are each one of:
  - A bare version slug (e.g. `staging`) — resolves to the current saved state of that
    `ApplicationVersion`'s manifest.
  - `current:<versionSlug>` — syntactic alias for the bare-slug form; reserved for forward
    compatibility.
  - `history:<versionSlug>:<revisionId>` — resolves to the named OR object-history
    revision of that version's manifest.
  The endpoint returns `{ from: { manifest, semver, savedAt }, to: { manifest, semver,
  savedAt } }` on `200`, `404` on any missing reference, and enforces the parent
  Application's `permissions` RBAC block (viewers may diff). Registered in
  `appinfo/routes.php` with `#[NoAdminRequired]`.
- **NEW** `DiffResolverService` — thin service that translates the two `fromRef`/`toRef`
  strings (slug or history reference) into manifest payloads via OR's `ObjectService` and
  OR's object-history API (ADR-022). Keeps the controller thin.
- **DOCUMENTED** REQ-OBV-001 (legacy snapshot schema), REQ-OBV-004 (version history
  retention), and REQ-OBV-006 (`currentVersion` pointer) are **retired** — their
  replacements are owned by `openbuilt-versioning-model`.
- **DOCUMENTED** REQ-OBV-002 (snapshot on publish) and REQ-OBV-003 (rollback via
  snapshot) are **retired** — history is now OR object time-travel on the
  `ApplicationVersion` row, not sibling snapshot rows.
- **NO** `ApplicationVersionSnapshotListener` — the file was deleted by
  `openbuilt-versioning-model`. This spec does not re-introduce it.

## Capabilities

### New Capabilities

- `version-diff`: A single `GET` endpoint that returns two manifest blobs (+ semver +
  savedAt) in one call, allowing client-side diff rendering without a second round-trip.
  Supports cross-version diffing (by slug) and within-version history diffing (by OR
  object-history revision ID). Owns `DiffResolverService` and the `diffVersions` method
  on `ApplicationVersionsController`.

### Modified Capabilities

- `openbuilt-version-snapshots`: Snapshot/writeback semantics retired. REQ-OBV-001,
  REQ-OBV-002, REQ-OBV-003, REQ-OBV-004, REQ-OBV-006 are superseded as documented below.
  REQ-OBV-005 is retained in modified form as the `version-diff` capability above.

## Impact

- **New PHP**:
  - `lib/Service/DiffResolverService.php` (slug/history-ref → manifest payload; thin OR
    delegator; no app-local DB)
  - `lib/Controller/ApplicationVersionsController::diffVersions()` (new method on
    existing controller — extend, do not create a new controller file)
- **Modified PHP**:
  - `appinfo/routes.php` — add `GET /api/applications/{slug}/versions/diff` entry
- **New tests**:
  - `tests/Unit/Service/DiffResolverServiceTest.php`
  - `tests/Unit/Controller/ApplicationVersionsControllerDiffTest.php`
  - Newman integration test cases under `tests/integration/openbuilt-version-diff.postman_collection.json`
- **No schema delta** — `ApplicationVersion` is owned by `openbuilt-versioning-model`;
  this change adds no new properties.
- **No seed data** — the diff endpoint operates on existing ApplicationVersion records
  provisioned by the creation wizard (spec F). No install-time fixtures needed.
- **OpenRegister dependency** — uses OR's `ObjectService` for slug resolution and OR's
  object-history API for `history:<slug>:<revisionId>` references. Requires the
  `^v0.2.10` OR floor already declared by `openbuilt-versioning-model`.
- **Out of scope**:
  - Diff-viewer UI component — owned by `openbuilt-app-detail-overview` (spec B),
    which calls this endpoint.
  - Version switching, `?_version=` URL routing — `openbuilt-version-routing` (spec E).
  - Promotion flow — `openbuilt-version-promotion` (spec D).
  - OR's object-history API implementation — OR-side; this spec is a consumer.
