## Purpose

Lets a widget authored in a Buildiq virtual app appear on the user's own Nextcloud
Dashboard, next to Files and Calendar, instead of only inside the app's shell. Covers the
manifest declaration that promotes a widget, its frozen identity, per-user visibility, the
browser render path, and the item projection that mobile and desktop clients read.

## ADDED Requirements

### Requirement: Manifest declares which widgets reach the Nextcloud dashboard

A widget placement in a published app's production manifest SHALL be promoted to a
Nextcloud Dashboard widget when, and only when, it carries an `ncDashboard` object. The
object SHALL accept `title`, `icon`, `order` and `link`, and SHALL reject any other
property. A placement carrying `ncDashboard` SHALL also carry a non-empty `id`, because
position in the `widgets[]` array is not stable across edits and identity derived from
position would move a user's panel onto a different widget when the page is reordered.

Only VIRTUAL apps are in scope: apps published as Application records and rendered by
Buildiq. Exported apps are out of scope.

`ncDashboard` is validated by the canonical manifest schema
`@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json`, which this app
consumes as the published npm package.

#### Scenario: A widget with no ncDashboard object stays inside the app

- **WHEN** a published app's manifest declares widgets and none carries `ncDashboard`
- **THEN** the app registers no Nextcloud Dashboard widget
- **AND** the Nextcloud dashboard widget picker offers nothing from that app

#### Scenario: ncDashboard without an id is rejected

@e2e exclude schema validation contract, asserted by a vitest manifest-validation spec against the canonical schema

- **WHEN** a manifest is validated with a widget placement that carries `ncDashboard` and an absent or empty `id`
- **THEN** validation fails and names the offending placement
- **AND** the manifest is not saved

#### Scenario: An unknown key inside ncDashboard is rejected

@e2e exclude schema validation contract, asserted by a vitest manifest-validation spec against the canonical schema

- **WHEN** a manifest is validated with `ncDashboard` carrying a property other than `title`, `icon`, `order` or `link`
- **THEN** validation fails and names the unknown property

### Requirement: Widget identity is frozen to the Application UUID

The id of a promoted widget SHALL be `buildiq-{applicationUuid}-{widgetEntryId}`, derived
from the Application's immutable UUID and never from its slug or name. The id SHALL match
`^[a-z][a-z0-9\-_]*$`; a widget entry id that does not SHALL be normalised to that
alphabet before the widget is registered, and the normalisation SHALL be deterministic so
the same entry always produces the same id.

Nextcloud's Dashboard app stores each user's chosen widgets by id in its own appconfig
namespace, which this app cannot read or migrate. A changed id therefore removes the panel
from every dashboard that had it, with no error raised anywhere. Widget ids are a one-way
door.

#### Scenario: Renaming the app slug does not move the widget

@e2e exclude identity contract over stored records, asserted by a PHPUnit regression test that renames the slug and recomputes the id

- **WHEN** a published Application's slug changes and its manifest is otherwise unchanged
- **THEN** every promoted widget keeps the id it had before the rename
- **AND** a user who had added the panel still sees it

#### Scenario: A widget entry id with unsupported characters is normalised

@e2e exclude pure id-derivation logic, asserted by a PHPUnit unit test

- **WHEN** a widget entry id contains uppercase letters, spaces or other characters outside the supported alphabet
- **THEN** the registered widget id contains only characters Nextcloud accepts
- **AND** re-deriving the id from the same entry produces the same result

### Requirement: Promoted widgets are registered with the Nextcloud dashboard

For every published Application, the system SHALL register one Nextcloud Dashboard widget
per promoted widget placement during the app's boot, so the widget appears in the normal
dashboard widget picker. Registration SHALL be lazy: no widget is constructed unless the
dashboard actually asks for it.

Registration failure SHALL never break boot. When the published applications cannot be
read, the system SHALL log a warning and register no widgets.

#### Scenario: Every promoted widget becomes a selectable dashboard widget

@e2e nc-dashboard-widgets::every-promoted-widget-becomes-a-selectable-dashboard-widget

- **WHEN** two widgets across two published apps carry `ncDashboard` and the dashboard is opened
- **THEN** the widget picker lists both, each under the title from its `ncDashboard` object
- **AND** each is listed exactly once

#### Scenario: A broken data layer does not break the dashboard

@e2e exclude boot-time failure path, asserted by a PHPUnit test with a failing object service

- **WHEN** the published applications cannot be read during boot
- **THEN** boot completes, a warning is logged, and no widget is registered
- **AND** the Nextcloud dashboard renders its other widgets normally

### Requirement: A user only sees widgets they are allowed to see

A promoted widget SHALL be offered and rendered only to users who pass the Application's
own permission check combined with the widget placement's `roles` array. The check order
SHALL be the one the app already applies to its navigation entries: a `group:*` sentinel
makes the widget visible to every signed-in user, then a `user:<uid>` match, then a
`group:<gid>` or bare group id match against the user's memberships, then the Nextcloud
admin bypass.

The check SHALL be evaluated per user, per request, so a change to an Application's
permissions takes effect without any writeback.

The placement's `visibleWhen` expression SHALL NOT be evaluated for a promoted widget, and
a placement carrying one SHALL still be promotable. `visibleWhen` reads page context — the
loaded object, route params, the page's own filters — and none of that exists on the
Nextcloud dashboard. Evaluating it there would require inventing that context, and every
answer would reflect the invented values rather than the author's intent.

#### Scenario: A placement carrying visibleWhen is promoted and shown on roles alone

@e2e exclude unit-level branch, asserted by PHPUnit on the visibility resolver

- **WHEN** a promoted placement declares both a `visibleWhen` expression and a `roles` array
- **AND** a user matches the roles but the expression would evaluate false against an empty context
- **THEN** the widget is offered and rendered to that user
- **AND** the expression is never evaluated

#### Scenario: A user outside every listed role is not offered the widget

@e2e nc-dashboard-widgets::a-user-outside-every-listed-role-is-not-offered-the-widget

- **WHEN** a user who matches no entry in the Application's permissions or the placement's roles opens the dashboard
- **THEN** the widget picker does not list that widget
- **AND** the widget does not render even if its id is already stored on that user's dashboard

#### Scenario: The group wildcard makes a widget visible to everyone signed in

@e2e exclude permission-matrix branch, asserted by PHPUnit across the four check-order cases

- **WHEN** the placement's roles contain the `group:*` sentinel
- **THEN** every signed-in user is offered the widget

### Requirement: The widget renders in the Nextcloud panel chrome

A promoted widget SHALL render in the browser using the shared library's Nextcloud
dashboard panel variant, so it matches the native panels around it rather than carrying
the app's own card styling. The widget SHALL receive the descriptors visible to the
current user as server-provided initial state, so the first paint needs no extra request.

A widget key the runtime cannot resolve SHALL render the library's unknown-widget state
rather than an empty panel, so the failure is visible instead of silent.

#### Scenario: A promoted widget renders with the native panel chrome

@e2e nc-dashboard-widgets::a-promoted-widget-renders-with-the-native-panel-chrome

- **WHEN** a user adds a promoted widget to their dashboard
- **THEN** the panel renders with the widget's title and icon and the widget's own content
- **AND** its chrome matches the native Nextcloud dashboard panels beside it

#### Scenario: An unresolvable widget key is visible as unknown

@e2e exclude render-path branch, asserted by a vitest mount test with an unregistered widget key

- **WHEN** a descriptor names a widget key the runtime registry does not hold
- **THEN** the panel shows the unknown-widget state naming the key
- **AND** the panel is not blank

### Requirement: Clients that cannot run the widget receive projected items

The system SHALL answer the Nextcloud dashboard API with items computed on the server, so
the mobile and desktop clients show real content rather than an empty panel. For a
placement with a declarative data source, each returned row SHALL become one item carrying
the row's title and a link that deep-links into the page the widget lives on. For a
placement whose data source aggregates to a single number, the response SHALL be one item
whose title is that number.

#### Scenario: A declarative data source becomes one item per row

@e2e exclude server-side API projection with no browser surface, asserted by PHPUnit against a stubbed object service

- **WHEN** the dashboard API is asked for a widget backed by a declarative data source returning three rows
- **THEN** three items are returned
- **AND** each item's link opens the app page the widget is placed on

#### Scenario: An aggregating data source becomes a single number

@e2e exclude server-side API projection with no browser surface, asserted by PHPUnit against a stubbed object service

- **WHEN** the dashboard API is asked for a widget whose data source aggregates to a count
- **THEN** exactly one item is returned and its title is that count

### Requirement: An unprojectable widget returns an honest empty state

When a promoted widget's shape cannot be projected on the server, the system SHALL return
an empty item list with a message pointing the user at the app page. It SHALL NOT return a
guessed, zero or placeholder value. Shapes in this class include a raw GraphQL data source
and the chart and gauge widget types, whose value is a rendering rather than a list.

Returning zero is indistinguishable from a real zero, which is why it is forbidden here
rather than merely discouraged.

#### Scenario: A chart widget returns empty with a pointer to the app

@e2e exclude server-side API projection with no browser surface, asserted by PHPUnit per unprojectable shape

- **WHEN** the dashboard API is asked for a chart or gauge widget
- **THEN** no items are returned
- **AND** the empty message names the app page where the widget can be seen
- **AND** no numeric value appears in the response

#### Scenario: A raw GraphQL data source returns empty rather than zero

@e2e exclude server-side API projection with no browser surface, asserted by PHPUnit

- **WHEN** the dashboard API is asked for a widget whose data source is a raw GraphQL query
- **THEN** no items are returned and the empty message points at the app page
- **AND** the response does not contain a count

### Requirement: Each OpenRegister endpoint is queried in its own filter grammar

The item projection SHALL address OpenRegister's objects endpoint and its aggregations
endpoint using the filter grammar each one accepts: bare keys for the objects endpoint and
bracketed `filter[...]` keys for the aggregations endpoint. Using the other endpoint's
grammar SHALL be treated as a defect, because each endpoint answers the wrong shape with a
confident wrong number rather than an error: the objects endpoint reads a bracketed key as
the empty set, and the aggregations endpoint drops a bare key and returns the whole
register.

Each endpoint SHALL be covered by its own test asserting the grammar actually sent.

#### Scenario: A row-listing projection sends bare filter keys

@e2e exclude query-grammar contract with no browser surface, asserted by PHPUnit inspecting the outbound query

- **WHEN** the projection lists rows for a widget filtered on a property
- **THEN** the objects endpoint receives the filter as a bare key
- **AND** the returned row count reflects the filter rather than the whole register

#### Scenario: An aggregating projection sends bracketed filter keys

@e2e exclude query-grammar contract with no browser surface, asserted by PHPUnit inspecting the outbound query

- **WHEN** the projection aggregates for a widget filtered on a property
- **THEN** the aggregations endpoint receives the filter as a bracketed key
- **AND** the returned number reflects the filter rather than the whole register

### Requirement: Published applications are read once per request

Navigation entries and dashboard widgets SHALL be served from a single read of the
published Applications per request. Adding dashboard widgets SHALL NOT add a second query
to the request that already reads the same records for the app menu.

#### Scenario: Registering widgets adds no extra query

@e2e exclude per-request query accounting with no browser surface, asserted by PHPUnit counting object-service calls during boot

- **WHEN** boot registers both the navigation entries and the dashboard widgets
- **THEN** the published applications are read exactly once
