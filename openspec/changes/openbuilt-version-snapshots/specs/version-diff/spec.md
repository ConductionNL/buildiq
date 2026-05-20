## ADDED Requirements

### Requirement: REQ-OBV-501 Diff endpoint resolves two manifest refs in one call

The system SHALL expose
`GET /index.php/apps/openbuilt/api/applications/{slug}/versions/diff?from={fromRef}&to={toRef}`
backed by `ApplicationVersionsController::diffVersions`. Each of `{fromRef}` and
`{toRef}` SHALL accept one of three forms:

- **Bare version slug** (e.g. `staging`, `production`) — the current saved state of
  the named `ApplicationVersion`'s manifest.
- **`current:<versionSlug>`** — syntactic alias for the bare-slug form; reserved for
  forward compatibility.
- **`history:<versionSlug>:<revisionId>`** — a specific OR object-history revision of
  the named version (e.g. `history:staging:r5`).

The endpoint SHALL resolve `{slug}` to a parent `Application` via OR's `ObjectService`
(ADR-022), look up each `ApplicationVersion` by `application.slug = {slug}` and
`slug = {versionSlug}`, and for history-form references delegate to OR's object-history
API for the named revision. The endpoint SHALL return `200 application/json` with body:

```json
{
  "from": { "manifest": {…}, "semver": "x.y.z", "savedAt": "ISO-8601" },
  "to":   { "manifest": {…}, "semver": "x.y.z", "savedAt": "ISO-8601" }
}
```

where `savedAt` is OR's built-in `modified` timestamp on the object (or the
object-history revision timestamp for history-form refs).

The endpoint SHALL return `404 application/json` with body
`{"status": 404, "message": "Version not found"}` when either `fromRef` or `toRef`
cannot be resolved (missing version slug, unknown history revision, or unknown
application slug). The same `404` shape SHALL be used for both "does not exist" and
"caller not permitted" cases to prevent existence enumeration.

The endpoint SHALL carry `#[NoAdminRequired]` and SHALL be registered in
`appinfo/routes.php`. It SHALL NOT carry `#[PublicPage]` — an authenticated session is
required to resolve the Application's `permissions` block.

#### Scenario: Diff two current ApplicationVersions by slug

- **GIVEN** an Application `hello-world` with ApplicationVersions `development` and
  `production`, both carrying distinct manifests
- **WHEN** an authenticated viewer GETs
  `/api/applications/hello-world/versions/diff?from=development&to=production`
- **THEN** the response is `200 application/json`
- **AND** `from.manifest` equals the current saved manifest of the `development` version
- **AND** `to.manifest` equals the current saved manifest of the `production` version
- **AND** `from.semver` and `to.semver` match each version's current `semver` field
- **AND** `from.savedAt` and `to.savedAt` are valid ISO-8601 timestamps

#### Scenario: Diff two OR object-history revisions of the same version

- **GIVEN** an ApplicationVersion `staging` of `hello-world` that has at least two
  OR object-history revisions (`r5` and `r9`) with different manifests
- **WHEN** an authenticated editor GETs
  `/api/applications/hello-world/versions/diff?from=history:staging:r5&to=history:staging:r9`
- **THEN** the response is `200 application/json`
- **AND** `from.manifest` is the manifest captured at OR object-history revision `r5`
- **AND** `to.manifest` is the manifest captured at OR object-history revision `r9`
- **AND** `from.savedAt` and `to.savedAt` match the timestamps recorded by OR's history
  engine on those revisions

#### Scenario: Diff current state against a historical revision

- **GIVEN** an ApplicationVersion `staging` of `hello-world` with history revision `r3`
- **WHEN** an authenticated editor GETs
  `/api/applications/hello-world/versions/diff?from=staging&to=history:staging:r3`
- **THEN** the response is `200 application/json`
- **AND** `from.manifest` is the current saved manifest of `staging`
- **AND** `to.manifest` is the manifest captured at revision `r3`

### Requirement: REQ-OBV-502 Missing or unknown reference returns 404 without data leak

The diff endpoint SHALL return `404 application/json` with body
`{"status": 404, "message": "Version not found"}` when:

- The application slug `{slug}` does not match any `Application` record.
- Either `fromRef` or `toRef` names a version slug that does not exist under the
  resolved Application.
- Either ref uses the `history:` form and the named revision ID does not exist.
- The caller is a non-member of the Application (not in `permissions.owners`,
  `permissions.editors`, or `permissions.viewers`).
- The caller is a Nextcloud admin who is not explicitly listed in the Application's
  `permissions` block.

In all cases the response body SHALL be identical — no partial data is returned, and the
response does not distinguish between "does not exist" and "not permitted".

#### Scenario: Unknown version slug returns 404

- **GIVEN** an Application `hello-world` with no ApplicationVersion whose slug is
  `nonexistent`
- **WHEN** an authenticated viewer GETs
  `/api/applications/hello-world/versions/diff?from=nonexistent&to=production`
- **THEN** the response is `404 application/json`
- **AND** the body is `{"status": 404, "message": "Version not found"}`
- **AND** no partial data from the `production` version is included in the response

#### Scenario: Non-member caller receives 404

- **GIVEN** an Application `hello-world` whose `permissions` block does not include
  user `janwillem`
- **WHEN** `janwillem` GETs the diff endpoint for any two valid version refs
- **THEN** the response is `404 application/json`
- **AND** the response body is identical to the "missing version" 404 body

#### Scenario: Unknown OR history revision returns 404

- **GIVEN** an ApplicationVersion `staging` of `hello-world`
- **AND** no OR object-history revision with ID `rXXXX` exists for that version
- **WHEN** an authenticated editor GETs
  `/api/applications/hello-world/versions/diff?from=history:staging:rXXXX&to=production`
- **THEN** the response is `404 application/json`
- **AND** the body is `{"status": 404, "message": "Version not found"}`

### Requirement: REQ-OBV-503 Viewers may diff; non-members may not

The diff endpoint SHALL enforce the parent Application's `permissions` RBAC block:

- Callers in `permissions.owners` → allowed.
- Callers in `permissions.editors` → allowed.
- Callers in `permissions.viewers` → allowed (diff is a read-only operation).
- Callers not in any `permissions` key (non-members) → `404`.
- Nextcloud admins not in any `permissions` key → `404` (admin power does NOT grant
  access per ADR-002 and the gate pattern established in spec E).

The RBAC check SHALL be performed inside `DiffResolverService` before any manifest data
is returned, so neither the `from` nor the `to` payload is leaked on failure.

#### Scenario: Viewer may diff two versions

- **GIVEN** an Application `hello-world` with user `noor` listed in
  `permissions.viewers`
- **WHEN** `noor` GETs
  `/api/applications/hello-world/versions/diff?from=development&to=production`
- **THEN** the response is `200 application/json`
- **AND** both manifests are present in the response

#### Scenario: Nextcloud admin not in permissions receives 404

- **GIVEN** an Application `hello-world` whose `permissions` block does not include
  the Nextcloud administrator account
- **WHEN** the Nextcloud admin GETs the diff endpoint with two valid version refs
- **THEN** the response is `404 application/json`

---

## REMOVED Requirements

### Requirement: REQ-OBV-001 ApplicationVersion schema declared in OpenRegister

**Reason**: The legacy `ApplicationVersion` schema (snapshot-row shape with
`applicationUuid` string, `publishedAt`, `publishedBy`) is superseded by the
versioned-model `ApplicationVersion` schema declared in
`openbuilt-versioning-model` → `application-versions` capability (long-lived row with
`register`, `semver`, `promotesTo`, real `application` OR relation, per-version
`status` lifecycle).

**Migration**: The legacy snapshot schema does not exist under the new model — there is
no row-shape conversion to perform. The new schema is declared in
`lib/Settings/openbuilt_register.json` by `openbuilt-versioning-model`.

### Requirement: REQ-OBV-002 Snapshot is created on draft-to-published transition

**Reason**: Retired by ADR-002. The versioned model treats every `ApplicationVersion`
row as a long-lived first-class object, not a snapshot spawned on publish.
`ApplicationVersionSnapshotListener` is deleted by `openbuilt-versioning-model`. No
`create_relation(ApplicationVersion)` action exists on `Application.x-openregister-lifecycle`.
OR's object-history captures the change trail on the `ApplicationVersion` row itself.

**Migration**: Callers that previously expected a new `ApplicationVersion` row to appear
after each publish MUST switch to querying OR's object-history API on the single
`ApplicationVersion` row for the relevant deployment stage (e.g. `staging`).

### Requirement: REQ-OBV-003 Rollback restores a previous snapshot as the draft manifest

**Reason**: Retired by ADR-002. Rollback is now OR object time-travel on the
`ApplicationVersion` row via OR's restore API. No sibling snapshot row is copied back;
no `+rollback` version suffix is appended. The `ApplicationVersion` row's manifest
reverts to its prior OR-history state, which triggers the manifest-hash semver patch-bump
defined in `openbuilt-versioning-model` (REQ-OBV-103) if the restored manifest differs
from the current state.

**Migration**: Callers that previously drove rollback by copying from a sibling
`ApplicationVersion` row MUST switch to calling OR's object time-travel (restore) API
on the relevant `ApplicationVersion` row.

### Requirement: REQ-OBV-004 Version history is retained without retention cap

**Reason**: Retired by ADR-002. Snapshot rows no longer exist — version history is OR
object-history on the `ApplicationVersion` row itself. Retention is inherited from OR's
object-history retention settings (no cap by default). If a future spec introduces a
per-ApplicationVersion retention policy, it will be declared via OR's declarative
retention vocabulary on the schema, not as an openbuilt-level requirement.

**Migration**: No application-level retention logic to remove. Callers that previously
enumerated `ApplicationVersion` rows for history MUST switch to querying OR's
object-history API on the single relevant `ApplicationVersion` row.

### Requirement: REQ-OBV-006 Current version reference is maintained on the Application

**Reason**: Retired by ADR-002. `Application.currentVersion` (UUID string, denormalised
cache) is removed. "Which version is live?" is now answered by
`Application.productionVersion` (a first-class OR relation pointer to an
`ApplicationVersion`), set explicitly by the admin and maintained by no listener.
`ApplicationVersionSnapshotListener` (the class that wrote `currentVersion`) is deleted
by `openbuilt-versioning-model`.

**Migration**: Callers that previously read `Application.currentVersion` MUST switch to
reading `Application.productionVersion` (an OR relation) and dereferencing it to obtain
the live version's manifest and semver. The field name, type (string UUID → OR relation),
and update mechanism all differ.
