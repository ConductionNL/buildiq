## ADDED Requirements

### Requirement: REQ-OBVP-001 Promotion endpoint accepts a strategy and targets `sourceVersion.promotesTo`

The system SHALL expose `POST /index.php/apps/openbuilt/api/applications/{appUuid}/versions/{versionUuid}/promote`
mounted on `VersionPromotionController::promote(string $appUuid, string $versionUuid)`,
where `{versionUuid}` identifies the **source** ApplicationVersion. The endpoint SHALL
accept a JSON request body `{"strategy": "start-with-source-data" | "migrate-existing-data"
| "empty-start"}`. The endpoint SHALL resolve the target as exactly
`sourceVersion.promotesTo`. If `promotesTo` is null, the endpoint SHALL return
`422 Unprocessable Entity` with body `{"code": "no_promote_target"}` and SHALL NOT
modify any data. The endpoint SHALL be registered in `appinfo/routes.php` and SHALL
carry `#[NoAdminRequired]`.

#### Scenario: Successful promotion returns 200 with the updated target

- **GIVEN** an Application `app-1` with two ApplicationVersions: source `00000000-0000-0000-0000-000000000000`
  with `promotesTo` pointing at target `00000000-0000-0000-0000-000000000001`
- **WHEN** an authorised owner POSTs `{"strategy": "start-with-source-data"}` to
  `/api/applications/<appUuid>/versions/00000000-0000-0000-0000-000000000000/promote`
- **THEN** the response is `200 application/json`
- **AND** the response body is the updated target ApplicationVersion (the row whose
  uuid is `00000000-0000-0000-0000-000000000001`) with the source's manifest and
  semver applied

#### Scenario: Source with no promotesTo is rejected

- **GIVEN** an ApplicationVersion with `promotesTo: null`
- **WHEN** a client POSTs `{"strategy": "start-with-source-data"}` to the source's
  promote endpoint
- **THEN** the response is `422` with body containing `"code": "no_promote_target"`
- **AND** no ApplicationVersion row is modified

#### Scenario: Missing or unknown strategy is rejected

- **WHEN** a client POSTs a body with no `strategy` field, or with `strategy:
  "unknown-mode"`, to a valid source's promote endpoint
- **THEN** the response is `400 Bad Request` with body containing
  `"code": "invalid_strategy"`
- **AND** no ApplicationVersion row is modified

### Requirement: REQ-OBVP-002 `start-with-source-data` replaces target rows + imports source schema set

The system SHALL, when invoked with `strategy: "start-with-source-data"`:

1. Acquire OR object lock on the target ApplicationVersion row (REQ-OBVP-006).
2. Invoke OR's schema-import / register-merge API on the target's `register` with the
   source's schema set (REQ-OBVP-005).
3. Delete every row in the target's register.
4. Copy every row from the source's register into the target's register, preserving
   schema-named row identities (`schema → ...` mapping) but assigning new OR-assigned
   uuids on the target side.
5. Write the source's `manifest` and `semver` onto the target ApplicationVersion row.
6. Save the target row.
7. Release the OR object lock.

On success the endpoint SHALL return `200 application/json` with the updated target
ApplicationVersion.

#### Scenario: start-with-source-data wipes target rows and copies from source

- **GIVEN** a source register with 5 rows across 2 schemas and a target register with
  3 unrelated rows
- **WHEN** an owner promotes with `strategy: "start-with-source-data"`
- **THEN** the target register's 3 pre-existing rows are gone
- **AND** the target register holds 5 rows that mirror the source's rows by schema +
  field content
- **AND** the target ApplicationVersion's `manifest` equals the source's `manifest`
- **AND** the target ApplicationVersion's `semver` equals the source's `semver`

### Requirement: REQ-OBVP-003 `migrate-existing-data` keeps target rows + imports source schema set

The system SHALL, when invoked with `strategy: "migrate-existing-data"`:

1. Acquire OR object lock on the target ApplicationVersion row (REQ-OBVP-006).
2. Invoke OR's schema-import / register-merge API on the target's `register` with the
   source's schema set (REQ-OBVP-005). OR's own breaking-change handling drives any
   column-level data migration; openbuilt does not pre-flight diffs.
3. Leave the target register's existing rows in place (no delete, no copy).
4. Write the source's `manifest` and `semver` onto the target ApplicationVersion row.
5. Save the target row.
6. Release the OR object lock.

On success the endpoint SHALL return `200 application/json` with the updated target
ApplicationVersion.

#### Scenario: migrate-existing-data preserves target rows and applies source schemas

- **GIVEN** a target register with 10 existing rows
- **WHEN** an owner promotes with `strategy: "migrate-existing-data"`
- **THEN** the target register still contains the 10 rows (modulo OR's schema-migration
  column-level changes — row identities preserved)
- **AND** the target ApplicationVersion's `manifest` equals the source's `manifest`
- **AND** the target ApplicationVersion's `semver` equals the source's `semver`

### Requirement: REQ-OBVP-004 `empty-start` drops target rows + imports source schema set

The system SHALL, when invoked with `strategy: "empty-start"`:

1. Acquire OR object lock on the target ApplicationVersion row (REQ-OBVP-006).
2. Delete every row in the target's register.
3. Invoke OR's schema-import / register-merge API on the target's `register` with the
   source's schema set (REQ-OBVP-005).
4. Write the source's `manifest` and `semver` onto the target ApplicationVersion row.
5. Save the target row.
6. Release the OR object lock.

The endpoint SHALL NOT enforce the dialog's destructive-confirmation gate
(typed-slug match) — that gate is a UI-side guard (REQ-OBVP-010). The backend trusts
that the client has obtained admin intent. On success the endpoint SHALL return
`200 application/json` with the updated target ApplicationVersion.

#### Scenario: empty-start wipes target rows and leaves the register schema-only

- **GIVEN** a target register with 7 existing rows
- **WHEN** an owner promotes with `strategy: "empty-start"`
- **THEN** the target register has zero rows
- **AND** the target register's schema set matches the source's schema set
- **AND** the target ApplicationVersion's `manifest` equals the source's `manifest`
- **AND** the target ApplicationVersion's `semver` equals the source's `semver`

### Requirement: REQ-OBVP-005 Schema diff handling deferred to OR

The promotion endpoint SHALL invoke OR's schema-import / register-merge API for the
target register with the source's schema set; OR's own breaking-change handling
drives the outcome. The endpoint SHALL NOT implement an openbuilt-side schema-diff,
dry-run, or breaking-change preflight. If OR's API returns a failure response, the
endpoint SHALL treat that as a promotion failure (REQ-OBVP-009) and the on-failure
status flip applies.

#### Scenario: OR's schema-import success continues the strategy step

- **GIVEN** OR's schema-import API returns success for the target register
- **WHEN** the promotion endpoint forwards the source's schema set
- **THEN** the strategy step continues (delete / copy / no-op per strategy)
- **AND** the manifest + semver writes proceed

#### Scenario: OR's schema-import failure triggers on-failure flow

- **GIVEN** OR's schema-import API returns a failure response (e.g. `400` with an
  OR-side error payload)
- **WHEN** the promotion endpoint forwards the source's schema set
- **THEN** the promotion is treated as failed
- **AND** the target's `status` flips to `archived` per REQ-OBVP-009
- **AND** the endpoint returns `500 Internal Server Error` with OR's error payload
  preserved in `message`

### Requirement: REQ-OBVP-006 OR object lock acquisition on target + 409 on contention

The promotion endpoint SHALL acquire OR's object lock on the **target**
ApplicationVersion row before performing any schema-import, data-copy, or manifest
write. The lock SHALL be released in a `finally` block — both on success and on
failure. If the lock is already held by another caller, the endpoint SHALL return
`409 Conflict` with body `{"code": "version_locked", "lockedBy": "<uid>",
"expiresAt": "<ISO-8601 timestamp>"}` where `lockedBy` and `expiresAt` come from OR's
lock metadata. The endpoint SHALL NOT modify any data on contention.

#### Scenario: 409 returned on lock contention

- **GIVEN** the target ApplicationVersion row is locked by user `<uid>` with the lock
  expiring at `<ISO-8601>`
- **WHEN** a different authorised owner POSTs a valid promote request
- **THEN** the response is `409 Conflict`
- **AND** the response body matches `{"code": "version_locked", "lockedBy": "<uid>",
  "expiresAt": "<ISO-8601>"}`
- **AND** the target ApplicationVersion row is unmodified

#### Scenario: Lock is released after successful promotion

- **WHEN** a promotion completes successfully
- **THEN** the OR object lock on the target ApplicationVersion is no longer held

#### Scenario: Lock is released after failed promotion

- **WHEN** a promotion fails mid-strategy (e.g. OR schema-import returns failure)
- **THEN** the OR object lock on the target ApplicationVersion is no longer held
- **AND** the target's `status` is `archived` per REQ-OBVP-009

### Requirement: REQ-OBVP-007 Permission: editor or owner on parent Application required

The promotion endpoint SHALL resolve the caller's role against the parent
Application's `permissions.{owners, editors}` blocks. If the caller is neither an
owner nor an editor, the endpoint SHALL return `403 Forbidden` with body
`{"code": "insufficient_permission"}` and SHALL NOT modify any data. Nextcloud admins
SHALL NOT be auto-granted promotion permission — this is a deliberate constraint;
an admin who is not in `permissions.owners` or `permissions.editors` on the specific
Application SHALL be rejected with `403`.

#### Scenario: Viewer is rejected

- **GIVEN** an authenticated user listed in `permissions.viewers` but not in
  `permissions.owners` or `permissions.editors`
- **WHEN** the user POSTs a valid promote request
- **THEN** the response is `403 Forbidden` with body containing
  `"code": "insufficient_permission"`

#### Scenario: Non-member is rejected

- **GIVEN** an authenticated user not listed in any of the Application's permission
  blocks
- **WHEN** the user POSTs a valid promote request
- **THEN** the response is `403 Forbidden`

#### Scenario: Nextcloud admin without per-app role is rejected

- **GIVEN** a Nextcloud admin user who is NOT listed in the Application's
  `permissions.owners` or `permissions.editors`
- **WHEN** the admin POSTs a valid promote request
- **THEN** the response is `403 Forbidden` (admin power does NOT auto-grant promotion)

#### Scenario: Editor succeeds

- **GIVEN** an authenticated user listed in `permissions.editors`
- **WHEN** the user POSTs a valid promote request (with no lock contention)
- **THEN** the response is `200 application/json` with the updated target

### Requirement: REQ-OBVP-008 Semver: target inherits source's value uniformly

The promotion endpoint SHALL set `targetVersion.semver` to `sourceVersion.semver` at
the moment of promotion, regardless of whether the target is the production version
or a mid-chain version. The endpoint SHALL NOT introduce any additional semver bump
on the target. The next manifest edit on the upstream source SHALL fire the existing
spec-C semver auto-bump (REQ-OBV-103); this spec adds no new bump rule.

#### Scenario: Target inherits source semver on promotion to production

- **GIVEN** a source ApplicationVersion with `semver: 1.5.0` and a target that is the
  Application's `productionVersion`
- **WHEN** an owner promotes with any strategy
- **THEN** the target's `semver` is `1.5.0` after the promotion

#### Scenario: Target inherits source semver on mid-chain promotion

- **GIVEN** a source with `semver: 0.3.4` and a target that is NOT the Application's
  `productionVersion`
- **WHEN** an owner promotes with any strategy
- **THEN** the target's `semver` is `0.3.4` after the promotion

### Requirement: REQ-OBVP-009 On-failure target flips to archived and endpoint returns 500

The system SHALL handle any failure inside `VersionPromotionService::promote()` (e.g.
OR schema-import failure, register-row copy failure, manifest save failure) by
performing the following steps in order before returning a `500` response:

1. The service SHALL set `targetVersion.status` to `archived`.
2. The service SHALL write `_self.promotionFailedAt` to the current ISO-8601
   timestamp on the target ApplicationVersion row.
3. The service SHALL save the target row.
4. The service SHALL release the OR object lock (in the `finally` block).
5. The endpoint SHALL return `500 Internal Server Error` with body
   `{"code": "promotion_failed", "strategy": "<chosen strategy>", "message":
   "<captured error message>"}`.

The source register SHALL NOT be modified by failure handling — it is read-only
throughout the promotion flow. Re-promotion after the underlying issue is resolved
is the prescribed recovery path; alternative recovery is deletion of the archived
target via the spec-C deletion endpoint with `?strategy=delete-now`.

#### Scenario: Failure during data copy archives the target

- **GIVEN** an OR-side error occurs while copying rows from source to target during
  a `start-with-source-data` promotion
- **WHEN** the error is thrown
- **THEN** the target ApplicationVersion's `status` is `archived`
- **AND** the target ApplicationVersion carries `_self.promotionFailedAt` with an
  ISO-8601 timestamp
- **AND** the OR object lock on the target is released
- **AND** the response is `500` with body containing `"code": "promotion_failed"` and
  `"strategy": "start-with-source-data"`
- **AND** the source register is unmodified

#### Scenario: Re-promotion after failure is idempotent at the user-visible level

- **GIVEN** a target ApplicationVersion left in `archived` after a prior failed
  promotion
- **WHEN** an owner re-promotes with the same strategy (the underlying issue having
  been resolved)
- **THEN** the promotion succeeds
- **AND** the target's `status` is no longer `archived` (the manifest save during
  promotion overwrites status alongside manifest + semver)
- **AND** the target's state reflects the source's current state

### Requirement: REQ-OBVP-010 Dialog `PromoteVersionDialog.vue` ships with destructive-confirmation gate

The system SHALL ship a Vue component at `src/dialogs/PromoteVersionDialog.vue`
implemented as a standalone `.vue` file using `<NcDialog>` (per ADR-004
modal-isolation rule — no inline modal markup in parent components). The dialog
SHALL:

- Accept props `sourceVersion: ApplicationVersion` and
  `targetVersion: ApplicationVersion`. If `targetVersion` is null the dialog SHALL
  not mount (or SHALL display a no-target message and a Cancel-only footer).
- Render a summary `Promote <sourceVersion.name> → <targetVersion.name>` with both
  versions' `register` names visible.
- Render a three-option radio group with values
  `start-with-source-data | migrate-existing-data | empty-start`, each accompanied by
  a `<NcSelect inputLabel="…">` or properly-labeled input per ADR-004 input-label rule
  and a one-paragraph inline description explaining the strategy's effect.
- Default the radio per the chain-position rule (REQ-OBVP-011) on mount.
- For `empty-start` ONLY: render a "Type the application slug to confirm" text input
  and disable the Confirm button until the typed value matches the parent
  Application's `slug` exactly (case-sensitive byte-equal match). For other
  strategies, the Confirm button SHALL be enabled.
- Emit `confirm` with payload `{strategy: 'start-with-source-data' |
  'migrate-existing-data' | 'empty-start'}` when the admin clicks Confirm.
- Emit `cancel` (no payload) when the admin clicks Cancel or closes the dialog.

The dialog SHALL NOT call the backend endpoint itself — the parent surface (delivered
by spec B) is responsible for the network call.

#### Scenario: Dialog mounts with chain-position default

- **GIVEN** a source ApplicationVersion whose `promotesTo` points at the Application's
  `productionVersion`
- **WHEN** the dialog is mounted with `sourceVersion` and that target
- **THEN** the radio group's default selection is `migrate-existing-data`

#### Scenario: Mid-chain target defaults to start-with-source-data

- **GIVEN** a source whose `promotesTo` target is NOT the Application's
  `productionVersion`
- **WHEN** the dialog is mounted
- **THEN** the radio group's default selection is `start-with-source-data`

#### Scenario: empty-start is never the mounted default

- **WHEN** the dialog is mounted with any source/target pairing
- **THEN** the radio group's default selection is NOT `empty-start`

#### Scenario: Destructive-confirmation gate blocks Confirm until slug matches

- **GIVEN** the admin selects `empty-start` in a mounted dialog for Application
  `hello-world`
- **WHEN** the destructive-confirmation input is empty or contains a string other
  than `hello-world`
- **THEN** the Confirm button is disabled

#### Scenario: Destructive-confirmation gate enables Confirm on slug match

- **GIVEN** the admin selects `empty-start` for Application `hello-world`
- **WHEN** the admin types `hello-world` into the destructive-confirmation input
- **THEN** the Confirm button is enabled
- **AND** clicking Confirm emits `confirm` with payload `{strategy: "empty-start"}`

#### Scenario: Non-destructive strategy has Confirm enabled by default

- **GIVEN** the dialog is mounted with the default strategy selected
- **WHEN** the strategy is `start-with-source-data` or `migrate-existing-data`
- **THEN** the Confirm button is enabled (no destructive-confirmation gate applies)

#### Scenario: Cancel emits cancel and closes the dialog

- **WHEN** the admin clicks Cancel
- **THEN** the dialog emits `cancel` with no payload
- **AND** the dialog closes

### Requirement: REQ-OBVP-011 Default-strategy rule is a pure function of chain position

The system SHALL implement a pure function
`defaultStrategyFor(application: Application, target: ApplicationVersion):
'start-with-source-data' | 'migrate-existing-data'` returning:

- `"migrate-existing-data"` if `target.uuid === application.productionVersion.uuid`
- `"start-with-source-data"` otherwise

The function SHALL never return `"empty-start"`. The function SHALL be implemented in
both PHP (`VersionPromotionService::defaultStrategyFor()`) and JS (inside
`PromoteVersionDialog.vue` or a sibling helper imported by it). Both implementations
SHALL be unit-tested.

#### Scenario: Production target returns migrate-existing-data

- **GIVEN** an Application X with `productionVersion.uuid = u-prod` and a target
  ApplicationVersion with `uuid = u-prod`
- **WHEN** `defaultStrategyFor(X, target)` is called
- **THEN** the return value is `"migrate-existing-data"`

#### Scenario: Mid-chain target returns start-with-source-data

- **GIVEN** an Application Y with `productionVersion.uuid = u-prod` and a target
  ApplicationVersion with `uuid = u-mid-chain` (not equal to `u-prod`)
- **WHEN** `defaultStrategyFor(Y, target)` is called
- **THEN** the return value is `"start-with-source-data"`

#### Scenario: empty-start is never returned

- **WHEN** `defaultStrategyFor` is called with any valid `(Application, target)` pair
- **THEN** the return value is never `"empty-start"`
