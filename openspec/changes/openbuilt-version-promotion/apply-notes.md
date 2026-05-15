# Apply notes — openbuilt-version-promotion

Branch: `feature/openbuilt-version-promotion` (off `feature/openbuilt-nextcloud-nav`).

## OR locking API surface (Decision 5 / REQ-OBVP-006)

OpenRegister already ships the lock primitive used by this spec; no
floor change is required.

- **Acquire**: `OCA\OpenRegister\Service\ObjectService::lockObject(string $identifier, ?string $process = null, ?int $duration = null): array`
  — surfaces `OCA\OpenRegister\Exception\LockedException` (HTTP 423,
  RFC 4918) on contention. The exception itself does NOT carry the
  `lockedBy` / `expiresAt` metadata — see below.
- **Release**: `OCA\OpenRegister\Service\ObjectService::unlockObject(string|int $identifier): bool`.
- **Lock info**: `OCA\OpenRegister\Service\Object\LockHandler::getLockInfo(string $identifier): ?array`
  — returns `{ locked_at, locked_by, process, expires_at }`. NOT
  surfaced on `ObjectService` directly in the current release; the
  service treats it as defensively-callable (`method_exists` guarded)
  so the code keeps compiling against older floors that lack it. The
  unit-test stub at `tests/stubs/openregister-stubs.php` mirrors this
  by adding `getLockInfo()` returning `null` by default.

The promotion service translates **any** failure to acquire the lock
into `VersionLockedException` carrying `lockedBy` / `expiresAt` populated
from `getLockInfo()` when available. The controller forwards both fields
into the 409 body alongside `code: "version_locked"`.

## Schema-import / register-merge surface (Decision 4 / REQ-OBVP-005)

OR's `ImportHandler::importRegister()` is a heavyweight install-time
path that takes JSON payloads; it is **not** suitable for a runtime
"apply this register's schema set to that register" call.

For now, the promotion service forwards the source's schema set to the
target by **reading the source `Register`'s `getSchemas()` array (schema
ids) and writing them onto the target `Register` via `setSchemas()` +
`RegisterMapper::update()`**. OR's persistence path runs whatever
column-level migration / consistency check it has for register edits.

This delivers the spec's contract ("forward source's schema set to target;
OR's breaking-change handling drives the outcome") with the OR API that
exists today. If a future OR release exposes a dedicated
schema-import-into-register surface (e.g. a controller endpoint with a
proper diff / dry-run option), the wiring here can be swapped to that
surface without changing the spec or the controller contract.

The integration-test (Newman) gap noted below is the right place to
prove this empirically against a live OR floor; the unit tests assert
that `RegisterMapper::update()` is invoked for the schema-set forwarding
step in each strategy branch.

## `_self.promotionFailedAt` mechanism (Decision 8 / REQ-OBVP-009)

Spec C's `orphan-grace` deletion strategy uses an `orphanedAt` flag in
the `Register` entity's `metadata` array (written via
`Register::setMetadata` + `RegisterMapper::update`).

The promotion failure flow needs the same kind of stamp on the
**ApplicationVersion** row, not on the Register row. We follow the
spec's literal wording (`_self.promotionFailedAt`) by writing
`{_self: {promotionFailedAt: "<ISO-8601>"}}` directly into the
ApplicationVersion's serialised JSON payload and re-saving via
`ObjectService::saveObject()`. OR persists arbitrary payload keys on
the row's JSON column, so the flag survives the round-trip.

If OR later formalises a `_self` metadata-namespaced API (e.g. an
explicit `ObjectService::setSelfMetadata($uuid, $key, $value)` helper)
we should migrate this site to it. For now the literal payload-key
approach matches spec C's apply-side decision for `orphanedAt`.

## Newman / Postman integration test (task 8)

**Deferred to the dev container.** The repository does not currently
ship a Newman harness for openbuilt; spec C's apply also deferred its
integration suite. Tasks 8.1–8.8 will land alongside the next
docker-side test pass (the existing `tests/integration/` directory
does not yet exist). The unit suite covers every contract listed in
the spec; the Newman collection is the third layer (above unit + the
in-container PHPUnit functional path) and can be added as a follow-up
issue without changing the controller contract.

## Component-side unit test (task 4.9)

**Deferred** — the JS test harness for openbuilt's `src/dialogs/` is
not part of this wave. The pure-function default-strategy rule lives
in `src/dialogs/promoteVersionDefaults.js` and is small enough to be
covered by a future Jest / Vitest pass; the PHP twin is already unit-
tested. The Playwright destructive-confirmation gate test (task 5.1)
is similarly deferred to the journeydoc capture spec that the parent
chain (spec B `openbuilt-app-detail-overview`) will wire up.

## Route registration (task 3)

The promotion route uses UUID path parameters per spec REQ-OBVP-001
(`/api/applications/{appUuid}/versions/{versionUuid}/promote`).
This is distinct from the slug-based CRUD routes shipped by spec C
(`/api/applications/{slug}/versions/{versionSlug}`); the two coexist
peacefully because the `/promote` suffix + UUID-shape requirements
prevent collision with the `{versionSlug}` routes (which require
kebab-case slugs).

## Iteration count

1 (this pass).
