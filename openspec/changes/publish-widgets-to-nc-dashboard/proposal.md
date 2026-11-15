---
kind: code
depends_on: [nc-dashboard-widget-flag, v2-widget-placement-editor]
---

# publish-widgets-to-nc-dashboard

## Why

A widget authored in Buildiq's page designer is only visible inside the virtual app's
own shell at `/apps/buildiq/builder/{slug}`. To see it, a user has to remember the app
exists, open it, and land on the right page. Everything else on that user's Nextcloud
morning already collects itself on one screen: the Nextcloud Dashboard. Buildiq's apps
are the one thing that does not show up there.

Buildiq ships **zero** `OCP\Dashboard\IWidget` classes today. The gap is not a missing
feature in the manifest, it is a missing bridge: the manifest already carries everything
a dashboard panel needs (a widget key, a data source, a roles array), and Nextcloud
already has a registration API that takes lazily-resolved service names. Nothing joins
the two.

`app-nav-entries` closed exactly this shape of gap one level up: it made a published
virtual app a first-class citizen of the Nextcloud top bar. This change does the same
for a single widget on the dashboard.

## What Changes

- **A widget can be promoted.** A `widgets[]` entry in a published app's production
  manifest may carry an optional `ncDashboard` object (`title`, `icon`, `order`, `link`).
  A promoted entry becomes a real Nextcloud Dashboard widget, selectable from the normal
  dashboard widget picker, rendered next to Files, Calendar and the rest.
- **One PHP widget class, many instances.** `lib/Dashboard/VirtualAppWidget.php` implements
  `IWidget`, `IIconWidget`, `IConditionalWidget` and `IAPIWidgetV2`. A new
  `lib/Service/DashboardWidgetRegistrar.php` registers one container service per promoted
  widget under a synthetic service name and hands that name to
  `IManager::lazyRegisterWidget()` from `Application::boot()`. No class is generated and
  no class is faked: there are N named instances of one ordinary class.
- **Widget ids are frozen to the Application UUID.** The id is
  `buildiq-{applicationUuid}-{widgetEntryId}`, never the slug. Nextcloud's Dashboard app
  stores each user's chosen widgets by id in its own appconfig namespace, which no
  migration of ours can reach, so a changed id silently drops the panel from every
  dashboard that had it. App slug renames are a live hazard in this fleet; the UUID is
  stable across them. This is a one-way door.
- **Permissions are reused, not reinvented.** `isEnabled()` runs the existing
  `PermissionResolver` against the Application's `permissions` block plus the widget
  entry's own `roles` array, per user, per request. `AppNavigationService` already
  implements this exact check order; both now share it.
- **One OpenRegister query per boot, not two.** A new `PublishedApplicationProvider`
  owns the per-request published-Application cache that `AppNavigationService` holds
  today, and serves both the nav entries and the widget registrar.
- **A new webpack entry renders the panel.** `src/ncDashboard.js` emits
  `buildiq-ncDashboard.js`, loops the descriptors passed as initial state, and calls
  `OCA.Dashboard.register(id, cb)` for each. Rendering goes through
  `@conduction/nextcloud-vue`'s `CnWidgetWrapper` with `chrome="nc-dashboard"`, the
  variant the library already ships to match the native panel's design tokens.
- **Mobile and desktop clients get real content.** `IAPIWidgetV2::getItemsV2()` is served
  by a new `lib/Service/Dashboard/WidgetItemProjector.php` that computes in PHP what the
  Vue widget computes in the browser. A widget shape it cannot project honestly (a raw
  `graphql` data source, a chart, a gauge) returns an empty `WidgetItems` whose message
  points at the app page. It never guesses a number.
- **The designer gets a per-widget toggle.** A row-level control in the page designer
  promotes a widget, reveals its title and icon fields, and fills in the entry `id` that
  the schema rule below now requires.
- **BREAKING for nobody.** Every part of this is additive. An app with no `ncDashboard`
  entry registers no widgets and behaves exactly as today.

### The dependency chain

The manifest schema this change reads is not owned by this repo. Verified rather than
assumed: `scripts/check-manifest.js` and `tests/composables/manifestRoundTrip.spec.js`
both read `node_modules/@conduction/nextcloud-vue/src/schemas/`, and the designer's
runtime validator `src/composables/useManifestValidator.js` calls `validateManifest`
imported from `@conduction/nextcloud-vue`, Ajv-compiled against the v2 schema with
`additionalProperties: false`. An `ncDashboard` key written today is rejected by the
validator before it reaches OpenRegister.

There is a second prerequisite, in this repo. The designer has a v1/v2 split that predates
this work: `WidgetBuilder.vue` authors the **v1** `widgetDef` shape through
`DashboardPageEditor.vue`, while the **v2** `widgets[]` array that `ncDashboard` attaches to
is only ever read — listed with checkboxes in `WidgetSelectionPanel.vue`, passed through by
`PageDesigner.vue`. v2 placements come from templates, saved blocks and the copilot, and
nothing hand-authors them. The promote toggle has no editor to live in.

So four changes land in order:

1. **`nc-dashboard-widget-flag`** (`kind: config`, repo `ConductionNL/nextcloud-vue`):
   add the `ncDashboard` object to `$defs/widgetEntry` in
   `src/schemas/app-manifest-v2.schema.json`, plus the conditional rule that presence of
   `ncDashboard` requires a non-empty `id` on the same entry. Released as a MINOR, since
   it is additive and non-breaking.
2. **The gate copy** (`kind: config`, repo `ConductionNL/.github`): add ONLY the
   `ncDashboard` property to the vendored gate schema at
   `hydra-gates/scripts/schemas/app-manifest-v2.schema.json`. That copy is already stale,
   it lacks `roles` and `visibleWhen` which the canonical schema has. A full resync is a
   separate change on purpose: gates resolve `@main` and go fleet-wide the minute they
   merge.
3. **`v2-widget-placement-editor`** (`kind: code`, repo `ConductionNL/buildiq`): give the
   page designer a real editor for v2 `widgets[]` placements, closing the v1/v2 split
   above. This change hangs its promote toggle off that editor.
4. **This change** (`kind: code`, repo `ConductionNL/buildiq`), including a task that
   moves `package-lock.json` onto the released library version. The declared range
   `^3.2.0` already permits it, but a range is permission and the lockfile is the
   decision.

## Capabilities

### New Capabilities

- `nc-dashboard-widgets`: promoting a virtual app's manifest widget to a native Nextcloud
  Dashboard widget. Covers the `ncDashboard` declaration, the synthetic-service
  registration, the frozen id scheme, per-user permission gating, the browser render path
  and the `IAPIWidgetV2` projection for clients that cannot run the Vue widget.

### Modified Capabilities

- `openbuild-page-designer`: the widget editor gains a promote control, the title and icon
  fields it reveals, and the rule that promoting a widget assigns it a stable `id`.

## Impact

**Code, this repo**

- New: `lib/Dashboard/VirtualAppWidget.php`, `lib/Service/DashboardWidgetRegistrar.php`,
  `lib/Service/Dashboard/WidgetItemProjector.php`,
  `lib/Service/PublishedApplicationProvider.php`, `src/ncDashboard.js`.
- Modified: `lib/AppInfo/Application.php` (`boot()` registers widgets beside nav entries),
  `lib/Service/AppNavigationService.php` (its per-request cache moves to the provider),
  `webpack.config.js` (a fourth entry), the page designer's widget row, `package-lock.json`.

**Dependencies**

- `@conduction/nextcloud-vue` must be on a release carrying `ncDashboard` in the canonical
  manifest schema. The lockfile currently resolves 3.4.0.
- No new composer dependency. `OCP\Dashboard\IManager`, `IWidget`, `IIconWidget`,
  `IConditionalWidget`, `IAPIWidgetV2` and the `WidgetItem` / `WidgetItems` models are all
  public OCP.

**Data**

- No OpenRegister register or schema changes. `ncDashboard` is a key inside the manifest
  JSON blob stored on an existing Application object.

**Other apps**

- None consume this. The vendored gate schema in `ConductionNL/.github` is touched by the
  separate `kind: config` link, not by this change.

**Irreversible**

- Widget ids. Once a user has added a panel, changing the id drops it from that user's
  dashboard with no error anywhere.
