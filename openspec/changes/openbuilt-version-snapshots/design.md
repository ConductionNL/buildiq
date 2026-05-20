## Context

The original `openbuilt-version-snapshots` spec (REQ-OBV-001 – REQ-OBV-006) defined an
append-only snapshot model: every `draft → published` transition on an `Application`
spawned a sibling `ApplicationVersion` row carrying a deep copy of the manifest blob.
`Application.currentVersion` (a denormalised UUID pointer) was maintained by a PHP
listener (`ApplicationVersionSnapshotListener`) subscribed to OR's
`ObjectLifecycleTransitionedEvent`.

ADR-002 retired this model:

- **Application split**: `Application` loses `manifest`, `version`, `status`,
  `currentVersion`. A new `ApplicationVersion` object (one long-lived row per
  admin-defined deployment stage) owns `manifest`, `semver`, `status`, `register`,
  `application` (relation), and `promotesTo` (relation). No snapshot rows are created
  on publish.
- **History via OR time-travel**: every save on an `ApplicationVersion` row is captured
  by OR's immutable object-history. Rollback = restore a prior revision via OR's
  time-travel API. No sibling rows.
- **`productionVersion` replaces `currentVersion`**: `Application.productionVersion` is
  an explicit relation pointer set by the admin, not a cache maintained by a listener.
- **Listener deleted**: `ApplicationVersionSnapshotListener` is removed by
  `openbuilt-versioning-model`.

What **survives** from the original spec is REQ-OBV-005 — the diff endpoint. Its URL
parameters are updated (version slugs + OR history refs instead of snapshot UUIDs), but
the capability — two manifest blobs returned in one call, driving a client-side diff view
— is unchanged in intent. This spec delivers that endpoint in its updated form.

## Goals / Non-Goals

**Goals:**

- Ship `GET /api/applications/{slug}/versions/diff?from={fromRef}&to={toRef}` backed by
  `ApplicationVersionsController::diffVersions()`.
- Support three reference forms for `from` / `to`:
  - bare version slug (`staging`, `production`) — current saved state of that version;
  - `current:<versionSlug>` — alias for the bare-slug form (forward compatibility);
  - `history:<versionSlug>:<revisionId>` — a named OR object-history revision.
- Return `{ from: { manifest, semver, savedAt }, to: { manifest, semver, savedAt } }`
  on `200`, and `404` for any unresolvable reference.
- Enforce the parent Application's `permissions` RBAC block: viewers **may** diff
  (read-only operation); non-members receive `404` (no existence leak).
- Introduce `DiffResolverService` to keep the controller thin and the OR delegation
  testable in isolation.
- Register the route in `appinfo/routes.php` with `#[NoAdminRequired]`.
- Document the retirement of REQ-OBV-001, REQ-OBV-002, REQ-OBV-003, REQ-OBV-004,
  and REQ-OBV-006 under ADR-002.

**Non-Goals:**

- The diff-viewer Vue component — `openbuilt-app-detail-overview` (spec B) owns the UI.
- Version routing (`?_version=`) — `openbuilt-version-routing` (spec E).
- Promotion — `openbuilt-version-promotion` (spec D).
- The `ApplicationVersion` schema and its lifecycle — `openbuilt-versioning-model`.
- OR's object-history API implementation — OR-side; this spec is a consumer.
- Re-introducing `ApplicationVersionSnapshotListener` or any sibling-row snapshot logic.
- A "three-way diff" (from + base + to) — not in scope for v1.

## Decisions

### Decision 1 — Reference format: slug and `history:<slug>:<revisionId>` (no UUID diffs)

The original REQ-OBV-005 accepted `from` / `to` as `ApplicationVersion` UUIDs or the
literal string `draft` (for the current draft manifest). Under ADR-002 neither concept
maps cleanly:

- Snapshot-row UUIDs do not exist (rows are not spawned on publish).
- `draft` is no longer a meaningful reference — `ApplicationVersion` rows have their own
  `status` lifecycle, and a "draft manifest" is just the current saved state of the
  development version.

The replacement reference forms are:

| Form | Resolves to |
|---|---|
| `<versionSlug>` | Current saved state of that ApplicationVersion's manifest |
| `current:<versionSlug>` | Same as above; reserved for forward compatibility |
| `history:<versionSlug>:<revisionId>` | A specific OR object-history revision of that version |

**Why version slug, not version UUID:** the slug is stable, human-readable, and what OR
URLs already use. Admins and builders write `from=development&to=production`, not raw
UUIDs. UUIDs are still acceptable inputs if an integrator prefers them — `DiffResolverService`
accepts either form and resolves them via OR's `ObjectService` (slug lookup or direct
UUID lookup).

**Alternatives considered:** Accept only UUIDs (rejected — poor DX; admins would have
to look up UUIDs). Accept the literal `draft` (rejected — ambiguous under the new model
where multiple versions may be in `draft` status; the slug is unambiguous). Accept only
current-state slugs, no history refs (rejected — the within-version history diff is the
second documented use case in the archived versioning-model spec and covers a real need:
comparing a staging version before and after a schema change).

### Decision 2 — `DiffResolverService` owns all reference → payload mapping

`ApplicationVersionsController::diffVersions()` receives the two raw `from`/`to` strings
and delegates immediately to `DiffResolverService::resolve(string $appSlug, string $ref):
array`. The service:

1. Parses the ref string to detect the form (bare slug / `current:` / `history:`).
2. For slug-form: calls OR's `ObjectService::searchObjects` on the `openbuilt` register,
   filtering by schema `applicationVersion` and `application.slug = $appSlug` and
   `slug = $versionSlug`. Returns null if not found.
3. For history-form: calls OR's object-history API on the resolved ApplicationVersion
   row to fetch the named revision. Returns null if the revision does not exist.
4. Returns `['manifest' => ..., 'semver' => ..., 'savedAt' => ...]` or null on miss.

The controller maps: both non-null → `200 {from, to}`; either null → `404` (no existence
leak — same response for "version doesn't exist" and "history revision doesn't exist").

**Why imperative (ADR-031 §Exceptions — cross-object lookup with access-control
branching):** the resolution involves a two-step lookup (Application by slug →
ApplicationVersion by application+slug) plus the RBAC gate, and optionally a third call
to OR's object-history API. OR's `x-openregister-calculation` vocabulary covers
single-row derived fields, not multi-step cross-object lookups with auth branching.

### Decision 3 — 404 for non-members (no 403 to prevent enumeration)

The diff endpoint returns `404` for callers who are not in the Application's
`permissions` block (owners, editors, viewers) AND for calls that reference missing
versions. Returning `403` would confirm that the Application slug exists; `404` is
consistent with the pattern used by `ManifestResolverService` (spec E, Decision 8) and
GitHub-style existence-concealment.

**Who may diff:**

| Caller | Result |
|---|---|
| Owner / Editor | `200` (current or history refs) |
| Viewer | `200` (current or history refs — read-only, no write risk) |
| Non-member | `404` |
| NC Admin (not in permissions) | `404` |

Viewers are allowed to diff because the diff endpoint is read-only and viewing diffs
is a natural part of the QA / review workflow. The RBAC gate is identical to the
read-only manifest endpoint — the diff carries no more information than two manifests
the viewer could already reach individually.

**Alternatives considered:** Allow viewers only for current-state diffs, block history
diffs — rejected (history diffs are also read-only; adding asymmetry here adds
complexity without a security benefit). Block all non-editors — rejected (QA reviewers
need to compare versions; making them editors just to view a diff over-privileges them).

### Decision 4 — `savedAt` is OR's `modified` timestamp (not a separate field)

The response shape `{ manifest, semver, savedAt }` uses OR's built-in `modified`
timestamp as `savedAt`. For history revisions, `savedAt` is the timestamp recorded by
OR's object-history engine on that revision. No new `publishedAt` field is introduced
(the original REQ-OBV-005 response included `publishedAt`, which mapped to the snapshot
writeback timestamp — that concept does not exist under ADR-002).

**Why `savedAt` not `publishedAt`:** under the new model a version's manifest can be
saved multiple times between state transitions (while in `draft`). `savedAt` accurately
reflects when that state of the manifest was recorded; `publishedAt` would suggest a
lifecycle transition occurred, which may not be true for a `history:` reference.

### Decision 5 — Route registered in `appinfo/routes.php`; method on existing controller

The diff endpoint is a method on the existing `ApplicationVersionsController` (introduced
by `openbuilt-versioning-model`), not a new controller. This avoids creating a one-method
controller file for a closely related operation.

Route entry:
```php
['name' => 'ApplicationVersions#diffVersions',
 'url'  => '/api/applications/{slug}/versions/diff',
 'verb' => 'GET']
```

The `#[NoAdminRequired]` attribute is required per `hydra-gate-route-auth` and
`hydra-gate-semantic-auth`. The RBAC gate is enforced inside `DiffResolverService`, not
via `#[PublicPage]` (the endpoint requires an authenticated session to resolve
`permissions`).

**Alternatives considered:** A standalone `DiffController` — rejected (one method;
the operation is semantically a sub-action of ApplicationVersionsController). Reuse
`ManifestResolverService` from spec E — rejected (that service resolves Application
slug + version slug → manifest only; the diff endpoint needs two refs, including
history-form refs that ManifestResolverService does not handle).

## Seed Data Section

Per ADR-001 (org-wide), every register-shipping change documents its seed data. **This
spec ships no register changes and writes no seed data.** The diff endpoint operates
on `Application` + `ApplicationVersion` records provisioned by the creation wizard
(spec F). The `ApplicationVersion` schema is owned by `openbuilt-versioning-model`.

- No `lib/Repair/*` files are added.
- No entries are added to `lib/Settings/openbuilt_register.json`.
- No seed objects are written at install time.

This is explicit and intentional: the diff endpoint is a verb over existing data, not a
data fixture.

## Declarative-vs-Imperative Decision Section

Per ADR-031, every business-logic site is classified.

| Concern | Declarative attempt | Final decision | Rationale |
|---|---|---|---|
| Resolve `{fromRef}` → `{ manifest, semver, savedAt }` | `x-openregister-calculation` on Application or ApplicationVersion | **Imperative** (`DiffResolverService`) | The resolution involves parsing three ref-form variants, a 2-step cross-object lookup (Application by slug → ApplicationVersion by application+slug), an optional OR object-history API call, and an access-control branch. OR's calculation vocabulary covers single-row derived fields, not multi-step cross-object resolution with conditional external API calls. ADR-031 §Exceptions: cross-row traversal + external-API delegation. |
| RBAC gate (viewer/editor/owner allowed; non-member → 404) | Declarative `x-openregister-authorization` block on the endpoint | **Delegated to existing RBAC block** on Application (`permissions.{owners,editors,viewers}` defined by spec C / ADR-005) | The gate is a read of an existing declarative block. No new declarative or imperative auth code is introduced — `DiffResolverService` calls the same permissions-resolver helper that other specs already wired. |
| `savedAt` field derivation | `x-openregister-calculation` reading `@self.modified` | **Already provided by OR** for current-state rows; OR object-history timestamps for history refs | The `modified` timestamp is a free field on every OR object. No calc declaration needed. |
| Parse `history:<slug>:<revisionId>` ref string | Declarative schema validation | **Imperative** (string parsing in `DiffResolverService::parseRef()`) | Reference-form parsing is a string-manipulation step with three branches, not business logic that OR's schema engine expresses. The parsing is trivially unit-testable as a pure function. |

## Retired Requirements Summary

| Requirement | Original intent | Retirement reason |
|---|---|---|
| REQ-OBV-001 | `ApplicationVersion` schema (snapshot-row shape) in `lib/Settings/openbuilt_register.json` | Superseded by `openbuilt-versioning-model` → `application-versions` capability. The new schema has a completely different shape (`promotesTo`, `register`, `semver`, real `application` relation). |
| REQ-OBV-002 | Spawn a sibling `ApplicationVersion` row on `draft → published` | Retired by ADR-002. History is OR object time-travel on the single `ApplicationVersion` row. `ApplicationVersionSnapshotListener` is deleted. |
| REQ-OBV-003 | Rollback by copying a snapshot's manifest back onto the Application | Retired by ADR-002. Rollback is OR time-travel on the `ApplicationVersion` row via OR's restore API. |
| REQ-OBV-004 | Retain every snapshot row indefinitely | Retired by ADR-002. Snapshot rows do not exist; OR's built-in object-history provides retention (no cap). |
| REQ-OBV-006 | `Application.currentVersion` pointer maintained by writeback listener | Retired by ADR-002. Replaced by `Application.productionVersion` explicit relation. Listener deleted. |

## Risks / Trade-offs

- **Risk: OR's object-history API shape for `history:<slug>:<revisionId>` is not yet
  stable at spec time.** → Mitigation: `DiffResolverService` wraps the OR history call
  behind a single private method (`fetchHistoryRevision`) so the call site can be updated
  at apply time without touching the controller or the unit tests. The method is a clean
  seam.
- **Risk: Two separate OR calls (one per ref) add latency on slow OR deployments.**
  → Mitigation: both OR calls are independent; they can be issued concurrently
  (e.g. via async PHP or simple sequential calls — the expected latency per call is
  single-digit milliseconds on local OpenRegister). The client saves the second
  round-trip entirely, which is the original motivation for the endpoint.
- **Risk: History ref `revisionId` format may differ across OR versions.** →
  Mitigation: `DiffResolverService` validates that `revisionId` is a non-empty string
  and delegates the semantic validation to OR's API (which returns `404` on unknown
  revisions). No openbuilt-side revision-format parsing beyond splitting the
  `history:<slug>:<revisionId>` string.
- **Risk: A viewer can diff history revisions that predate their access grant, exposing
  manifests they were not authorised to see at that point in time.** → Mitigation: the
  RBAC gate is membership-based on the parent Application (not time-based). OR's
  object-history API inherits the same permission model. This is by design — if a user
  is a viewer today, they can see historical manifests; if they are a non-member, they
  cannot. Introducing time-scoped RBAC on history access is explicitly deferred.
- **Trade-off: `savedAt` uses OR's `modified` timestamp, not a `publishedAt` field.**
  Per Decision 4, this accurately reflects the snapshot's save time rather than implying
  a lifecycle transition. Callers who need to know the publish time should read the
  `ApplicationVersion.status` history on the OR object.

## Open Questions

None — the locked decisions above cover every architectural axis. The exact OR API call
shape for the object-history endpoint (`history:<slug>:<revisionId>`) will be confirmed
at apply time; `DiffResolverService::fetchHistoryRevision` is the clean seam. Genuine
ambiguities surfaced during apply are tracked in tasks.md.
