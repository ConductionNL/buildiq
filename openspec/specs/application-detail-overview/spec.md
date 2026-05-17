# application-detail-overview Specification

## Purpose
TBD - created by archiving change openbuilt-app-detail-overview. Update Purpose after archive.
## Requirements
### Requirement: REQ-OBADO-001 Application detail main area renders six stacked rows

The system SHALL replace the generic `CnDetailPage` main area on
`/applications/:objectId` with `ApplicationDetailHeader.vue`, registered as the
`headerComponent` on the `VirtualAppDetail` page entry in `src/manifest.json`. The
component SHALL render six rows, top to bottom:

1. Hero strip — app icon (from the Application record per ADR-001), name,
   description, status badge, role badge (caller's role on the Application), and
   the production version's semver string.
2. Version pill tabs (REQ-OBADO-002).
3. Window toggle — three buttons (`7d`, `30d`, `90d`) right-aligned on the same row
   as the pill tabs. Default selection is `7d`.
4. KPI grid — four cards (REQ-OBADO-004).
5. Activity-graph card (REQ-OBADO-005).
6. Structural-widget grid — five cards (REQ-OBADO-006 through REQ-OBADO-010).

The sidebar (Manifest / Version history / Diff / Audit tabs) is unchanged by this
spec. The redundant Overview sidebar tab entry SHALL be removed from
`sidebarTabs` on the `VirtualAppDetail` page entry in `src/manifest.json`.

#### Scenario: Page renders six rows in order

- **GIVEN** an Application `hello-world` with at least one ApplicationVersion
- **WHEN** an authenticated viewer-or-better navigates to `/applications/<nil>`
- **THEN** the main area renders, in DOM order: hero strip, pill tab strip + window
  toggle row, KPI grid, activity-graph card, structural-widget grid
- **AND** the Manifest / Version history / Diff / Audit sidebar tabs are present
- **AND** no Overview sidebar tab is present

#### Scenario: Hero icon comes from the Application record

- **GIVEN** an Application with an icon file attached via OR's file relation
- **WHEN** the page renders
- **THEN** the hero strip displays that icon (not a per-version icon)
  _(per ADR-001 — assets live on the Application, not the ApplicationVersion)_

### Requirement: REQ-OBADO-002 Version pill tabs render chain order, production starred, non-authorised hidden

The pill strip SHALL render one pill per `ApplicationVersion` in the
Application's `versions` relation, ordered by the `promotesTo` chain (most-upstream
first; most-downstream last). The pill whose UUID matches
`Application.productionVersion.uuid` SHALL display a leading asterisk in its label
(e.g. `* production`).

Pills whose version the caller is NOT authorised to access SHALL be HIDDEN from the
strip. The visibility rule SHALL match the backend RBAC gate:

- The production version SHALL be visible to any authenticated caller (the
  production-is-public policy from `openbuilt-version-routing` REQ-OBVR-001).
- Non-production versions SHALL be visible only to callers listed in
  `Application.permissions.editors` ∪ `Application.permissions.owners`.

Clicking a pill SHALL update the URL's `?_version=<versionSlug>` query parameter via
the `buildVersionedRoute` helper from `openbuilt-version-routing`. The hero strip,
KPI grid, activity-graph card, and structural-widget grid SHALL re-scope to the
newly-selected version on the same render cycle.

#### Scenario: Pill strip renders chain order

- **GIVEN** an Application `hello-world` with three ApplicationVersion records whose
  `promotesTo` chain is `development → staging → production`
- **AND** `production` is the production version
- **WHEN** an owner of the Application loads the page
- **THEN** the pill strip renders pills in the order: `development`, `staging`,
  `* production`

#### Scenario: Non-authorised version is hidden from the strip

- **GIVEN** an Application `hello-world` with three ApplicationVersions:
  `development`, `staging`, `production`
- **AND** the caller is in `permissions.viewers` but not in `permissions.editors`
- **WHEN** the page loads
- **THEN** only the `* production` pill renders
- **AND** the `development` and `staging` pills are NOT in the DOM
  _(the caller has no way to discover they exist)_

#### Scenario: Pill click updates `?_version=` and reloads dependent rows

- **GIVEN** the page is loaded with `?_version=production`
- **WHEN** an editor clicks the `staging` pill
- **THEN** the URL becomes `?_version=staging` (via `buildVersionedRoute`)
- **AND** the hero strip's version semver, the KPI grid values, the activity-graph
  data, and the structural-widget contents all re-fetch and re-render for the
  `staging` version

### Requirement: REQ-OBADO-003 Window toggle scopes time-windowed KPIs and activity graph

The window toggle SHALL offer three values: `7d`, `30d`, `90d`, with `7d` as the
default. The selected window SHALL be passed to the insights endpoint as
`?window=7d|30d|90d` (REQ-OBAI-001). The Active-users KPI, the Audit-events KPI, and
the activity-graph card SHALL scope to the selected window. The Object-count KPI
and Files-count KPI SHALL NOT scope to the window — they are point-in-time totals.

#### Scenario: Default window is 7d

- **WHEN** the page first loads
- **THEN** the window toggle has `7d` selected
- **AND** the insights request carries `?window=7d`

#### Scenario: Changing the window reloads windowed values only

- **GIVEN** the window toggle is on `7d`
- **WHEN** the user clicks `30d`
- **THEN** the insights request fires with `?window=30d`
- **AND** the Active-users KPI, Audit-events KPI, and activity graph re-render
- **AND** the Object-count KPI and Files-count KPI values do not change
  _(they are point-in-time totals not affected by the window)_

### Requirement: REQ-OBADO-004 KPI grid renders four cards

The KPI grid SHALL render four cards in a responsive grid (desktop: 4 columns;
tablet: 2 columns; mobile: 1 column). Each card SHALL be presentational only
(receives its value as a prop; performs no aggregation client-side):

| Card           | Value source from the insights response |
| -------------- | --------------------------------------- |
| Active users   | `kpis.activeUsers`                      |
| Object count   | `kpis.objectCount`                      |
| Files count    | `kpis.filesCount`                       |
| Audit events   | `kpis.auditEventCount`                  |

The Files-count card SHALL be labelled "Files" (not "Storage") and SHALL carry a
tooltip explaining it is a count of OR-attached files across all objects in the
selected version's register.

#### Scenario: KPI grid renders four cards with values from the insights response

- **GIVEN** the insights endpoint returns
  `{"kpis":{"activeUsers":12,"objectCount":487,"filesCount":89,"auditEventCount":1043}}`
- **WHEN** the page renders
- **THEN** the grid shows four cards labelled "Active users" (12), "Object count"
  (487), "Files" (89), and "Audit events" (1043)

#### Scenario: Files card carries the "not storage" tooltip

- **WHEN** the user hovers the Files card
- **THEN** a tooltip appears explaining "count of OR-attached files across all
  objects in this version's register; storage-bytes aggregation deferred"

### Requirement: REQ-OBADO-005 Activity-graph card renders the timeline from the insights response

The activity-graph card SHALL render an event timeline using the
`activity[]` array from the insights response (REQ-OBAI-001). Each array entry has
`{ "timestamp": "<iso8601>", "eventCount": <int> }`. The card SHALL render the
selected window's range on the X axis and event counts on the Y axis. Empty arrays
SHALL render an empty-state message ("No activity in the selected window") rather
than an empty chart frame.

#### Scenario: Activity graph renders the timeline

- **GIVEN** the insights endpoint returns
  `"activity":[{"timestamp":"2026-05-08T00:00:00Z","eventCount":142},
  {"timestamp":"2026-05-09T00:00:00Z","eventCount":198}]`
- **WHEN** the page renders
- **THEN** the activity-graph card renders a chart with two data points

#### Scenario: Empty activity renders an empty-state message

- **GIVEN** the insights endpoint returns `"activity":[]`
- **WHEN** the page renders
- **THEN** the activity-graph card displays "No activity in the selected window"
- **AND** no empty chart frame is shown

### Requirement: REQ-OBADO-006 Register widget renders read-only with an "Open in OpenRegister" deep-link

The `RegisterWidget.vue` component SHALL render a card with:

- The register name
- The register slug `openbuilt-{appSlug}-{versionSlug}`
- The schema count
- The object count
- The files count
- A primary action button "Open in OpenRegister" that navigates to
  `/apps/openregister/registers/{registerSlug}` (top-level Nextcloud URL, not a
  router-internal route)

No inline create. No row click action.

#### Scenario: Register widget deep-links to OpenRegister

- **GIVEN** an Application `hello-world` with the `production` version selected
- **WHEN** the user clicks "Open in OpenRegister" on the Register card
- **THEN** the browser navigates to
  `/apps/openregister/registers/openbuilt-hello-world-production`

### Requirement: REQ-OBADO-007 Schemas widget renders rows with deep-link and inline "+ Add schema"

The `SchemasWidget.vue` component SHALL render a card listing the schemas in the
selected version's register. Each row SHALL display the schema name, its object
count, and its status. Row click SHALL navigate to
`/builder/{slug}/schemas/{schemaId}?_version={versionSlug}` via the
`buildVersionedRoute` helper.

The card header SHALL include an inline "+ Add schema" button. Clicking SHALL open
the existing create-schema dialog if a global registration exists; otherwise it
SHALL log a deferred notice (the dialog itself is owned by a future schema-designer
spec) and take no action.

#### Scenario: Row click deep-links to the schema designer with the active version

- **GIVEN** the user is viewing `hello-world` with `?_version=staging`
- **AND** the Schemas card lists a schema with UUID `<nil>` named "Customer"
- **WHEN** the user clicks the "Customer" row
- **THEN** the router navigates to
  `/builder/hello-world/schemas/<nil>?_version=staging`

#### Scenario: "+ Add schema" opens the existing dialog when present

- **GIVEN** a global create-schema dialog component is registered
- **WHEN** the user clicks "+ Add schema"
- **THEN** the dialog opens

#### Scenario: "+ Add schema" no-ops with a logged notice when no dialog is registered

- **GIVEN** no create-schema dialog is registered
- **WHEN** the user clicks "+ Add schema"
- **THEN** a `debug` log entry is emitted ("schema-create dialog not yet
  registered — deferred to schema-designer spec")
- **AND** no UI change occurs

### Requirement: REQ-OBADO-008 Groups widget renders permissions entries with role badges

The `GroupsWidget.vue` component SHALL render a card listing the entries in the
Application's `permissions.{owners,editors,viewers}` arrays. Each row SHALL display
the entry name (group name or user UID), a role badge (`owner` / `editor` /
`viewer`), and a member count (for groups; "1" for users). Row click SHALL open
the existing permissions editor for the Application; the exact path is verified at
apply time and recorded in the apply-time task notes.

#### Scenario: Groups card lists permissions entries with role badges

- **GIVEN** an Application with `permissions.owners=["g:admins"]`,
  `permissions.editors=["u:alice", "g:devs"]`, `permissions.viewers=["g:everyone"]`
- **WHEN** the page renders
- **THEN** the Groups card lists four rows: `g:admins` (owner badge),
  `u:alice` (editor badge), `g:devs` (editor badge), `g:everyone` (viewer badge)

### Requirement: REQ-OBADO-009 Pages widget renders manifest pages with deep-link

The `PagesWidget.vue` component SHALL render a card listing entries from the
selected version's `manifest.pages[]`. Each row SHALL display the page id, route,
type, and title. Row click SHALL navigate to
`/builder/{slug}/pages?_version={versionSlug}&pageId={id}` via the
`buildVersionedRoute` helper.

#### Scenario: Row click deep-links to the page designer focused on the page

- **GIVEN** the user is viewing `hello-world` with `?_version=development`
- **AND** the Pages card lists a page with `id: "customers-list"` and
  `route: "/customers"`
- **WHEN** the user clicks the row
- **THEN** the router navigates to
  `/builder/hello-world/pages?_version=development&pageId=customers-list`

### Requirement: REQ-OBADO-010 Menu widget renders manifest menu entries with deep-link

The `MenuWidget.vue` component SHALL render a card listing entries from the
selected version's `manifest.menu[]`. Each row SHALL display the label, route,
order, and section. Row click SHALL navigate to
`/builder/{slug}/pages?_version={versionSlug}&focus=menu` via the
`buildVersionedRoute` helper.

#### Scenario: Row click deep-links to the page designer with menu focus

- **GIVEN** the user is viewing `hello-world` with `?_version=production`
- **AND** the Menu card lists an entry with `label: "Home"`, `route: "/"`
- **WHEN** the user clicks the row
- **THEN** the router navigates to
  `/builder/hello-world/pages?_version=production&focus=menu`

### Requirement: REQ-OBADO-011 Manifest config: add headerComponent, drop Overview sidebar tab

The system SHALL update `src/manifest.json`'s `VirtualAppDetail` page entry to:

1. Add `"headerComponent": "ApplicationDetailHeader"` (registering the new component
   as the main area).
2. Remove any `sidebarTabs` entry whose id is `overview` (the old generic data +
   metadata widgets, now redundant).

All other `sidebarTabs` entries (`manifest`, `versions`, `diff`, `audit`, or
whichever IDs the manifest carries at apply time) SHALL be preserved unchanged.

The manifest update SHALL validate against the canonical manifest schema at
`@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` after the edit.

#### Scenario: Manifest carries the headerComponent and no Overview sidebar tab

- **WHEN** the change is applied
- **THEN** `src/manifest.json`'s `VirtualAppDetail` entry contains
  `"headerComponent": "ApplicationDetailHeader"`
- **AND** no `sidebarTabs` entry with id `overview` is present
- **AND** the remaining `sidebarTabs` entries are unchanged in count, id, and order

### Requirement: REQ-OBADO-012 Pill strip renders a Promote button on each non-terminal pill

Each pill whose corresponding ApplicationVersion has a `promotesTo` target SHALL
render a small "Promote" affordance (icon button or trailing chevron) on the pill.
Clicking SHALL invoke the promotion dialog registered by
`openbuilt-version-promotion`. This spec does NOT define the dialog itself — it
defines only the trigger surface.

If no promotion dialog is registered (e.g. `openbuilt-version-promotion` not yet
applied), the button SHALL render but click SHALL log a deferred notice and no-op.

#### Scenario: Promote button renders on non-terminal pills

- **GIVEN** an Application with chain `development → staging → production`
- **AND** the user is in `permissions.editors`
- **WHEN** the page renders
- **THEN** the `development` and `staging` pills have a Promote affordance
- **AND** the `* production` pill does NOT have a Promote affordance
  _(production is the terminal node — no `promotesTo` target)_

#### Scenario: Promote click invokes the registered dialog

- **GIVEN** the promotion dialog from `openbuilt-version-promotion` is registered
- **WHEN** the user clicks Promote on the `staging` pill
- **THEN** the dialog opens, pre-targeted at the `staging` version

