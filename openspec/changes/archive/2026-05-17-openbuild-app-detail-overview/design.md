## Context

The Application detail page at `/applications/:objectId` is today rendered by a
generic `CnDetailPage` that just shows the raw record as a data widget. With the
versioning chain (`openbuilt-versioning-model`), the `?_version=` routing contract
(`openbuilt-version-routing`), and promotion (`openbuilt-version-promotion`) all in
place, the page becomes the natural maintainer cockpit — but only if the main area
renders that information. This spec swaps the generic main area for a purpose-built
dashboard composed of a hero strip, version pill tabs, a window toggle, a KPI grid,
an activity-graph card, and five structural-widget cards (Register / Schemas /
Groups / Pages / Menu) that deep-link into the existing builder views. The sidebar
is preserved unchanged except for the redundant Overview tab.

The KPI data and activity timeline are not derivable client-side without unbounded
fan-out queries to OpenRegister, so a thin server-side endpoint
`GET /api/applications/{appUuid}/versions/{versionUuid}/insights?window=…` fans out
to OR mappers (`AuditTrailMapper::getActionChartData`, the new
`AuditTrailMapper::getDistinctActorCount` from the OR-side change
`openregister-distinct-actor-aggregation`, and standard object/file counts) and
returns a single payload. This is per ADR-031's §Exceptions clause — cross-table
aggregations that fan across schemas are imperative work, not schema-declarative.

## Goals / Non-Goals

**Goals:**

- Replace the Application detail page main area with a maintainer dashboard: hero
  strip, version pill tabs, window toggle, KPI grid, activity-graph card, and the
  five structural-widget cards.
- Render the pill tabs in chain order, mark production with an asterisk, and HIDE
  versions the caller cannot access (production is always visible to viewer-or-better).
- Re-scope hero, KPIs, activity graph, and structural widgets to the currently
  selected version every time the pill tab changes — driven by `?_version=` in the URL.
- Ship a single backend endpoint
  `GET /api/applications/{appUuid}/versions/{versionUuid}/insights?window=7d|30d|90d`
  returning `{kpis, activity}` with the four KPIs and the chart payload. Auth gate
  mirrors `openbuilt-version-routing` (viewer-or-better for production, editor-or-better
  for non-production, 404 — not 403 — on failure). `Cache-Control: public, max-age=60`.
- Make each structural widget deep-link into the matching builder path with the
  active `?_version=` preserved.
- Preserve the existing sidebar (Manifest / Version history / Diff / Audit) unchanged;
  drop the redundant Overview sidebar tab from the manifest config.

**Non-Goals:**

- The version-switcher's writing to the URL — `openbuilt-version-routing` already
  owns `buildVersionedRoute` and `useApplicationVersion`; this spec consumes them.
- The promotion dialog itself — `openbuilt-version-promotion` owns it. This spec
  wires the Promote button on each non-terminal pill, but the click delegates to the
  dialog shipped by spec D.
- The `getDistinctActorCount` mapper aggregation — a separate OR-side change
  (`openregister-distinct-actor-aggregation`).
- Storage-bytes aggregation — deliberately deferred. "Files" is the v1 proxy (counts
  OR-attached files across all objects in the selected version's register).
- Layout persistence (admin reorders KPIs, layout saved per-user) — roadmap.
- The Overview sidebar tab's content — removed wholesale; the new main area replaces it.
- Schema creation dialog — the inline "+ Add schema" button on the Schemas widget
  opens the existing create-schema dialog if present, otherwise a thin stub the
  schema-designer spec will flesh out. Defer the dialog itself.

## Decisions

### Decision 1 — Page layout: six stacked rows in the main area

The main area renders, top to bottom: (a) hero strip, (b) version pill tabs, (c)
window toggle (right of the pill tabs, same row), (d) KPI grid (four cards), (e)
activity-graph card, (f) structural-widget grid (five cards).

**Why six rows over a denser layout:** the hero + version pill tabs + window toggle
form a "header" zone — the maintainer needs to know which app, which version, and
which time window before any number on the page means anything. The KPIs +
activity graph form a "signal" zone that lights up the same way every time. The
five structural widgets form an "edit-this-app" zone that maps 1:1 to the existing
builder views. Each zone has a different purpose and lives one beneath the other
without competing for attention.

**Alternatives considered:**

- Two-column layout with KPIs on the right rail: rejected — the existing right rail
  is the Manifest / Version history / Diff / Audit sidebar; doubling it up confuses
  the eye.
- KPIs + activity graph in a single combined card: rejected — the KPIs answer
  "how big / how busy" while the graph answers "when did the activity happen". They
  are not interchangeable surfaces and should not be visually fused.

### Decision 2 — Version pill tabs: chain order, production starred, non-authorised hidden

The pill strip renders one pill per `ApplicationVersion` in `Application.versions`,
ordered by the `promotesTo` chain (most-upstream first). The pill whose UUID matches
`Application.productionVersion.uuid` is marked with a leading asterisk in its label
(e.g. `* production`). Pills whose version the caller is NOT authorised to access
under `openbuilt-version-routing`'s RBAC gate are HIDDEN from the strip — they do
not render at all. Production is always visible to viewer-or-better callers.

**Why hide rather than disable:** a disabled pill leaks the existence of a version
the caller has no business knowing about (an `internal-staging` branch, say). Hiding
it matches the `openbuilt-version-routing` gate's "404 not 403" policy — the surface
behaves as if the version did not exist for non-authorised callers.

**Why mark with an asterisk rather than a coloured badge:** the asterisk is
text-only and accessible by default (screen readers announce "asterisk"). A coloured
badge requires an ARIA label and a colour-contrast check; an asterisk does not.
Visual design can later swap in a styled badge if needed without changing semantics.

**Why chain order rather than alphabetical or by created-date:** the chain captures
the maintainer's mental model — "what flows into what". `development` → `staging` →
`production` reads left-to-right exactly as the promotion path does in spec D.
Alphabetical would put `staging` between `development` and `production`, which
matches by coincidence but breaks for chains like `feature-branch` → `qa` → `production`.

### Decision 3 — KPI scope: strictly per-selected version

All four KPI cards plus the activity-graph card scope to the currently-selected
version's per-version register (`openbuilt-{appSlug}-{versionSlug}`, per
`openbuilt-versioning-model`). Switching versions via the pill strip reloads
everything. The page does NOT show cross-version aggregates ("total objects across
all versions") in v1.

**Why per-version not cross-version:** the maintainer's question on this page is
"what is the state of *this* deployment?". Cross-version totals answer a different
question (capacity planning) and would dominate the page if shown alongside
per-version numbers. They can be added as a follow-on if demand emerges.

### Decision 4 — KPI definitions and aggregation strategy

| Card             | Definition                                                                                                  | OR call                                            |
| ---------------- | ----------------------------------------------------------------------------------------------------------- | -------------------------------------------------- |
| Active users     | Distinct actor UIDs in audit-trail rows scoped to all schemas in the version's register, within the window  | `AuditTrailMapper::getDistinctActorCount`          |
| Object count     | Sum of `objectCount` across all schemas in the version's register                                           | `ObjectService::countObjects` per schema, summed   |
| Files count      | Count of OR-attached files across all objects in the version's register (proxy for "storage")               | `FileService::countAttachedFilesForRegister`       |
| Audit events     | Total audit-trail rows scoped to all schemas in the version's register, within the window                   | `AuditTrailMapper::countByRegisterAndWindow`       |

The schema-set is derived server-side by walking the version's
`manifest.pages[].config.{register,schema}` entries and unique-ing the resulting
schema IDs. The activity-graph payload comes from `AuditTrailMapper::getActionChartData`
called once per request with the same schema-set and window.

**Why a single endpoint over per-card endpoints:** five round-trips (four KPIs +
chart) would render staircase-style with awkward partial states; one endpoint returns
a coherent snapshot the frontend can render in one paint. The cache header
`Cache-Control: public, max-age=60` covers the cost of a heavier query.

**Why "Files" not "Storage":** the OR-side storage-bytes aggregation is unblocked
work (would require summing `file_size` across all attached files). The decision
to defer it is from the earlier conversation; "Files" is the v1 proxy and the label
reflects the count, not the bytes.

### Decision 5 — Window toggle: 7d / 30d / 90d

The window toggle sits right of the pill tabs and selects the time window for the
Active-users KPI, the Audit-events KPI, and the activity-graph card. The Object
count and Files count are point-in-time totals and do NOT scope to the window —
they always show "right now". The window value is passed to the backend as
`?window=7d|30d|90d`. Default is `7d`.

**Why 7/30/90 days:** these match the windows already used by OR's existing
`getActionChartData` callers across the fleet — the maintainer reading two
dashboards side by side sees consistent windowing semantics.

**Why a fixed three-bucket toggle and not a date picker:** the page is a
glance-and-act surface; a date picker would imply analytics-grade slicing the page
is not designed for. Three buckets cover the maintainer's actual question
("today / this month / this quarter").

### Decision 6 — Structural widgets: read with deep-links, "+ Add" inline only where cheap

Five small Vue components under `src/components/applicationDetail/widgets/`:

- **`RegisterWidget.vue`** — name, slug (`openbuilt-{appSlug}-{versionSlug}`),
  schema count, object count, files count. "Open in OpenRegister" button deep-links
  to `/apps/openregister/registers/{registerSlug}`. Info-only on this page; no
  inline create.
- **`SchemasWidget.vue`** — list of schemas in the version's register with name +
  object count + status. Row click navigates to
  `/builder/{slug}/schemas/{id}?_version={versionSlug}` via `buildVersionedRoute`.
  An inline "+ Add schema" button opens the existing create-schema dialog if
  present; otherwise it is a no-op stub (logged) deferring to the schema-designer
  spec.
- **`GroupsWidget.vue`** — flat list of entries from the Application's
  `permissions.{owners,editors,viewers}` arrays with role badge + member count.
  Row click opens the existing permissions editor (path verified at apply time).
  No inline create — group management is a Nextcloud-system concern.
- **`PagesWidget.vue`** — list of `manifest.pages[]` entries (id, route, type, title).
  Row click navigates to
  `/builder/{slug}/pages?_version={versionSlug}&pageId={id}`.
- **`MenuWidget.vue`** — list of `manifest.menu[]` entries (label, route, order,
  section). Row click navigates to
  `/builder/{slug}/pages?_version={versionSlug}&focus=menu`.

**Why one component per widget and not a generic config-driven grid:** the five
widgets have different row shapes (the Groups row has a role badge; the Pages row
has a type column; the Menu row has order/section) and different click targets.
A generic component would have to render all five row shapes via slots, which is
the same amount of code split across more layers. Five small files with single
responsibilities is the simpler shape.

**Why "+ Add" only on the Schemas widget:** schemas are the only structural concept
where the create surface is cheap (single name + register pick) and where the
maintainer expects to add from any context. Pages and Menu entries are added inside
the page designer where their config form lives; the widget is a deep-link, not
a duplicate creator. Groups inherit from Nextcloud's identity system and are
managed there.

### Decision 7 — Backend endpoint shape, auth, and caching

```
GET /api/applications/{appUuid}/versions/{versionUuid}/insights?window=7d|30d|90d
```

**Path params:** `appUuid` (Application UUID), `versionUuid` (ApplicationVersion UUID).
**Query param:** `window` — required; one of `7d`, `30d`, `90d`. Invalid values → `400`.

**Response (200):**

```json
{
  "kpis": {
    "activeUsers": 12,
    "objectCount": 487,
    "filesCount": 89,
    "auditEventCount": 1043
  },
  "activity": [
    { "timestamp": "2026-05-08T00:00:00Z", "eventCount": 142 },
    { "timestamp": "2026-05-09T00:00:00Z", "eventCount": 198 }
  ]
}
```

**Auth gate (mirrors `openbuilt-version-routing` REQ-OBVR-003):**

- If the resolved version's UUID matches `Application.productionVersion.uuid`:
  viewer-or-better on the Application is required (`permissions.viewers` ∪
  `permissions.editors` ∪ `permissions.owners`). Failure → `404`.
- Otherwise (non-production): editor-or-better is required
  (`permissions.editors` ∪ `permissions.owners`). Failure → `404`.
- Unknown `appUuid` or `versionUuid`, or a version whose `application` relation
  does not match the path's `appUuid` → `404`.

**Cache:** `Cache-Control: public, max-age=60`. The 60-second window is short enough
that maintainers reloading the page see fresh enough numbers and long enough that
the heavy fan-out query is not re-run on every paint.

**Implementation:** a thin `ApplicationInsightsController` reads the path + query
params, applies the auth gate via the same RBAC helper as `ManifestResolverService`,
and delegates to `ApplicationInsightsService::computeInsights($appUuid, $versionUuid, $window)`.
The service walks the version's `manifest.pages[].config.{register,schema}` to derive
the schema-set, then fans out the five OR calls (`getDistinctActorCount`,
`countObjects` × N, `countAttachedFilesForRegister`, `countByRegisterAndWindow`,
`getActionChartData`) and assembles the response.

**Why UUIDs in the path and not slugs:** the path is API-internal — the frontend
already has both UUIDs from the Application record and the resolved
ApplicationVersion. Slugs would require the resolver service walk on every call
and would conflict if two apps had the same version slug (the slug is only unique
within an Application, not globally).

### Decision 8 — Frontend: `ApplicationDetailHeader.vue` + `useApplicationInsights` composable

The single Vue component `ApplicationDetailHeader.vue` (under
`src/components/applicationDetail/`) renders all six rows of Decision 1. It is
registered as `headerComponent` on the `VirtualAppDetail` page entry in
`src/manifest.json`, replacing the default generic main area. It reads `?_version=`
via Vue Router (`$route.query._version`) and the new
`useApplicationInsights(appUuid, versionUuid, window)` composable (under
`src/composables/`) — a thin wrapper around the insights endpoint that handles
loading / error states and revalidates on `(versionUuid, window)` change.

**Why one big header component and not five composed components for the rows:** the
six rows share state (`selectedVersionUuid`, `selectedWindow`, the
`useApplicationInsights` result). Decomposing into row components would require
prop drilling or a local Pinia store for what is effectively page-local state. The
five structural-widget cards ARE decomposed (each takes its own data slice as a
prop) because they DO have independent lifecycles.

**Why a composable and not a Pinia store:** the insights state is page-local — it
does not need to survive route changes and is not shared with other views. A
composable matches the lifecycle. If a second surface ever needs the same data, the
composable can be promoted to a store at that point.

### Decision 9 — Pill-tab visibility uses the same RBAC rule as the backend

The frontend filters the pill strip using the same `permissions.{viewers,editors,owners}`
arrays the backend uses. Specifically:

- Production version: visible to any caller who is in `viewers ∪ editors ∪ owners`,
  OR (per `openbuilt-version-routing`'s production-is-public policy) to any
  authenticated caller. Defaulting to the latter avoids a permission lookup for the
  common case.
- Non-production version: visible only to callers in `editors ∪ owners`.

The Application record carries the `permissions` field, so the frontend has the
data without a second round-trip. If the backend later disagrees (record stale,
permission revoked mid-session) the insights endpoint returns 404 and the page
shows a "version no longer accessible" banner without crashing.

**Why client-side filtering rather than a versions-list endpoint:** the Application
record already includes the versions array (via OR relation resolution); a second
endpoint would be redundant. The backend remains the source of truth — client-side
filtering is a UX optimisation, not the security boundary.

### Decision 10 — Sidebar: drop Overview, keep the rest

In `src/manifest.json`'s `VirtualAppDetail` page entry, the `sidebarTabs.overview`
entry is removed; the remaining `manifest`, `versions`, `diff`, `audit` tabs (or
whichever IDs they carry in the manifest as found at apply time) stay unchanged.
This spec touches no sidebar-tab implementation files.

**Why drop Overview entirely rather than refactor it:** the Overview tab's content
(generic data + metadata widgets) becomes redundant once the main area renders the
hero + KPIs. Keeping it would duplicate information and confuse the eye. Refactoring
it into something else is out of scope — this spec ships a removal, not a replacement.

### Decision 11 — Declarative vs imperative classification (per ADR-031)

| Surface                                         | Classification         | Rationale                                                                                                                                                                                       |
| ----------------------------------------------- | ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Insights aggregation (4 KPIs + activity chart)  | **Imperative**         | Cross-table SQL fans over the audit_trail + objects + files tables, across N schemas. Outside OR's schema vocabulary. Falls under ADR-031 §Exceptions "cross-system aggregations".              |
| Pill-tab rendering + version selection          | **Declarative in spirit, imperative in implementation** | The pill strip is derived from `Application.versions` (a manifest-shaped relation). The rendering is Vue (only practical option). No business logic — pure projection. |
| KPI rendering                                   | **Declarative**        | KPI values are passed to four presentational `CnCard` instances (no `CnKpiGrid` dependency — locked decision to avoid coupling this spec to a specific nc-vue component). Each card renders; it does not compute. No imperative branching inside the card. |
| Structural widget rendering                     | **Declarative**        | Each widget receives its data slice as a prop and renders a list. Click handlers emit nav events; the router consumes them. No business logic.                                                  |
| Auth gate on the insights endpoint              | **Imperative**         | RBAC enforcement is a cross-cutting concern owned by a service, not a schema rule. Same shape as the `openbuilt-version-routing` RBAC gate (mirrored, not duplicated logic).                    |

### Decision 12 — Seed data

No new seed data. The page reads existing Application + ApplicationVersion records,
which are created by the wizard (`openbuilt-app-creation-wizard`, spec F) or
existing fixtures. No seed-data file is added to `lib/Settings/`.

## Risks / Trade-offs

- **Risk:** the insights endpoint fans out N+3 queries on every uncached call
  (N = schema count in the version's register). On apps with many schemas this can
  be slow. → **Mitigation:** `Cache-Control: public, max-age=60` absorbs repeat
  loads; the schema-set walk is bounded by manifest size (typically < 10 schemas);
  we can add a server-side memoise on `(appUuid, versionUuid, window)` if profiling
  shows a hot path.
- **Risk:** the "Files" KPI is a count, not a byte total, and the label may mislead
  maintainers into thinking it represents storage. → **Mitigation:** label is
  explicitly "Files" not "Storage"; tooltip on hover ("count of OR-attached files
  across all objects in this version's register; storage-bytes aggregation deferred").
  When the OR-side storage aggregation lands, this card can be upgraded in place.
- **Risk:** client-side pill-tab filtering disagrees with backend RBAC (stale
  permissions, revoked mid-session). → **Mitigation:** backend remains the source
  of truth; if the insights endpoint returns 404 the page shows a "version no longer
  accessible" banner and falls back to production. No client-side filter is treated
  as a security boundary.
- **Risk:** the `useApplicationInsights` composable re-fetches on every `window`
  change, including unintentional ones (e.g. a toggle bounce). → **Mitigation:**
  debounce the watcher in the composable (200ms); the 60-second HTTP cache absorbs
  the rest.
- **Risk:** the manifest config touch (`headerComponent` on `VirtualAppDetail` +
  drop Overview from `sidebarTabs`) collides with other in-flight specs that touch
  the same entry. → **Mitigation:** the touch is additive on `headerComponent` and a
  pure deletion on the Overview entry — both are mergeable. The apply-time task
  explicitly re-reads `src/manifest.json` before editing.

## Migration Plan

No data migration. No schema changes. No new register. Deploy is a code-only push:

1. Land the OR-side `openregister-distinct-actor-aggregation` change first
   (`AuditTrailMapper::getDistinctActorCount`). Without it, the Active-users KPI
   cannot be computed and the insights endpoint returns a 500.
2. Land this change. The endpoint and the new header component go in together; the
   manifest config touch flips the page over to the new shape on the first reload.

Rollback: revert this change. The manifest config touch falls back to the default
generic main area + the Overview sidebar tab. No data loss; no orphaned records.

## DEFERRED_QUESTIONS

- **Exact endpoint contract for the inline "+ Add schema" dialog:** the widget calls
  the existing create-schema dialog if present, else a no-op stub. Confirm at
  apply time which path is wired and whether the dialog accepts a pre-filled
  register slug.
- **Permissions editor path:** Groups widget rows click into "the existing
  permissions editor". The exact route (`/builder/{slug}/permissions`?
  `/applications/{uuid}/permissions`?) is verified at apply time against the
  installed manifest.
- **KPI rendering uses `CnCard`, not `CnKpiGrid` (locked).** The KPI grid renders
  four `CnCard` instances in a responsive grid (desktop 4 / tablet 2 / mobile 1).
  We deliberately do not depend on `CnKpiGrid` from `@conduction/nextcloud-vue` —
  the spec contract is presentational ("four cards with a label + value") and stays
  decoupled from the specific nc-vue layout component, so a future nc-vue rename or
  prop change cannot break this page. Rendering remains declarative per ADR-031.
- **Activity-graph component choice:** the chart renderer is presentational. If
  `CnActivityChart` (or similar) is available in `@conduction/nextcloud-vue`, use
  it; otherwise wrap the existing `@nextcloud/vue` chart primitive. Either way the
  endpoint contract is unchanged.
- **`window` enum extension:** if a future spec wants `24h` or `1y`, the endpoint
  validation accepts a fixed set today (`7d|30d|90d`). Extending is a one-line
  change but out of scope here.
