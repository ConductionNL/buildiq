# Design: publish-widgets-to-nc-dashboard

## Context

See `proposal.md` for motivation. What matters here is a distinction that will confuse
every future reader of this code, so it is stated first.

### Two unrelated things are both called a widget

1. **A manifest widget** is what Buildiq builds today. It is an entry in a page's
   `widgets[]` array: `widgetKey`, `slot`, `gridX/gridY/gridWidth/gridHeight`, `props`,
   `dataSource`, `roles`, `visibleWhen`. It is authored in
   `src/components/page-editor/DashboardPageEditor.vue` and the widget row editors beside
   it, resolved through `@conduction/nextcloud-vue`'s `dashboardWidgetRegistry` (types
   `stat`, `object-table`, `audit-trail`, `object-geo`, `data`), and rendered by
   `CnWidgetGrid` inside `CnAppRoot`. It exists only inside an app shell at
   `/apps/buildiq/builder/{slug}`.

2. **A Nextcloud Dashboard widget** is a PHP class implementing `OCP\Dashboard\IWidget`,
   registered at bootstrap, whose `load()` adds a script that calls
   `OCA.Dashboard.register(id, cb)`. Buildiq ships zero of these today. This change adds
   them.

The library already ships a bridge in the **opposite** direction: `nc-widget` is a
manifest placement type, authored with `CnNcDashboardWidgetForm`, which proxies a native
Nextcloud widget INTO an app dashboard. That is not this. This change takes an app widget
OUT to the Nextcloud dashboard. The two can coexist on the same page and mean opposite
things.

### The manifest schema is not ours

`ncDashboard` has to exist in `@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json`
before anything here can save. Verified, not assumed: `scripts/check-manifest.js` and
`tests/composables/manifestRoundTrip.spec.js` both read
`node_modules/@conduction/nextcloud-vue/src/schemas/`, and
`src/composables/useManifestValidator.js` calls `validateManifest` imported from the
package, Ajv-compiled against the v2 schema with `additionalProperties: false`. The
`$defs/widgetEntry` definition already carries `id`, `roles`, `visibleWhen` and a set of
`allOf` conditional rules, so both the new property and the new conditional rule fit the
shape that is already there.

## Goals / Non-Goals

**Goals**

- One widget placement in a published virtual app's manifest becomes one selectable panel
  on the Nextcloud dashboard, gated by the same permissions the app already enforces.
- No second OpenRegister query per request.
- Mobile and desktop clients get real content, or an honest empty state, never a guess.

**Non-Goals**

- Exported apps. Scope is virtual apps only.
- Per-user configuration of a panel beyond adding and removing it. Nextcloud's own widget
  options API is out of scope.
- A full resync of the stale vendored gate schema. That is a separate change on purpose.
- Replacing or deprecating `nc-widget`, the opposite-direction proxy.

## Decisions

### D1. One real class, N synthetic service names

**Decision.** One class `lib/Dashboard/VirtualAppWidget.php` implementing `IWidget`,
`IIconWidget`, `IConditionalWidget` and `IAPIWidgetV2`. A new
`lib/Service/DashboardWidgetRegistrar.php` walks each published Application's production
manifest, collects every `widgets[]` entry carrying `ncDashboard`, and per entry does:

```php
$container->registerService('OCA\Buildiq\Dashboard\Virtual\<key>', fn () => new VirtualAppWidget($descriptor, ...));
$dashboardManager->lazyRegisterWidget('OCA\Buildiq\Dashboard\Virtual\<key>', 'buildiq');
```

Called from `Application::boot()` beside the existing `AppNavigationService` call, which
already has both the app container and the published-app query in hand.

There are no generated classes and no fake classes. `OCA\Buildiq\Dashboard\Virtual\<key>`
is a *service name*, not a class name. Nothing ever autoloads it.

**Why it works.** Verified by reading Nextcloud 33 in this workspace at
`/home/rubenlinde/nextcloud-docker-dev/workspace/server`:

- `lib/private/Dashboard/Manager.php`: `lazyRegisterWidget()` (line 47) only appends
  `['class' => ..., 'appId' => ...]` to a list. `loadLazyPanels()` (line 52) resolves each
  entry through the **server** container, `$this->serverContainer->get($service['class'])`
  (line 64), and `registerWidget()` (line 36) keys the widget map by whatever
  `$widget->getId()` returns (line 44). So N instances of one class become N distinct
  widgets.
- `lib/private/ServerContainer.php`: `query()` (line 121) routes any name starting with
  `OCA\` to that app's `DIContainer::queryNoFallback()` (lines 124 to 135).
- `lib/private/AppFramework/DependencyInjection/DIContainer.php`: `queryNoFallback()`
  (line 342) checks `offsetExists($name)` **first** (line 345) and returns the registered
  service before it ever tries to instantiate a class. So the name need not be a real
  class.
- `lib/private/AppFramework/Bootstrap/RegistrationContext.php`: `registerDashboardPanel()`
  (line 466) stores a `ServiceRegistration` and never validates that the class exists.

Also noted: `Manager::registerWidget()` (line 41) only writes a **debug** log when an id
fails `^[a-z][a-z0-9\-_]*$`. It does not reject. Our ids match it anyway, and the spec
requires normalising an author-supplied entry id into that alphabet, because a silent
debug line is not a signal anyone will see.

**Alternative considered: generate one PHP class per promoted widget.** Rejected as the
default. It needs a writable generated-code directory, a cache-invalidation story on every
manifest save, and an autoloader that knows about it. It is, however, the documented
fallback (see R1 below).

**Alternative considered: one static class per app with a variant id passed in.**
Nextcloud resolves a widget class once and keys by `getId()`, so a single instance cannot
present several ids. Rejected because it does not work.

### D2. The widget id is `buildiq-{applicationUuid}-{widgetEntryId}`, and it is a one-way door

**Decision.** Key the id on the Application's immutable UUID, never on its slug or name.

**Why, plainly.** Nextcloud's Dashboard app stores each user's chosen widgets by id in its
**own** appconfig namespace. No migration this app ships can reach it. A changed id
therefore drops the panel from every dashboard that had it, with no error, no log line and
no 404. The user sees a dashboard that looks like one where they simply never added the
widget.

This fleet has already paid for this lesson. See the frozen-id comment in
`/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/procest/lib/Dashboard/MyTasksWidget.php`
around line 62, which is why procest's widget ids stayed on the pre-rename `procest_`
prefix through the app rename. App slug renames are a live hazard here; UUIDs are stable
across them.

Consequence to accept up front: once this ships, the id derivation cannot be changed.
Getting it right is cheaper than any recovery, because there is no recovery.

### D3. Permissions reuse `PermissionResolver`, through `isEnabled()`

`IConditionalWidget::isEnabled()` runs the existing `PermissionResolver` against the
Application's `permissions` block plus the widget entry's own `roles` array, per user, per
request. `Manager::loadLazyPanels()` calls `isEnabled()` before registering, so a widget a
user may not see never enters the map at all, which covers both the picker and a stored id
from an earlier grant.

`AppNavigationService::isVisibleForCurrentUser()` already implements exactly the required
order (`group:*` sentinel, then `user:<uid>`, then `group:<gid>` or bare gid, then the
Nextcloud admin bypass). Reuse it. Do not write a second copy: two copies of an
authorization check drift, and the drift is invisible until someone sees something they
should not.

### D4. One published-application query per request

`AppNavigationService` currently holds a per-request `$cachedApplications` field. Extract
`lib/Service/PublishedApplicationProvider.php`, move that cache into it, and have both
`AppNavigationService` and `DashboardWidgetRegistrar` read from it. `Application::boot()`
then makes one OpenRegister query serving both, not two.

### D5. Rendering: a new webpack entry, the library's own panel chrome

New entry `src/ncDashboard.js` emitting `buildiq-ncDashboard.js`, added to the
`webpackConfig.entry` map in `webpack.config.js` beside `main`, `adminSettings` and
`builder`. `VirtualAppWidget::load()` provides the descriptors visible to this user as
initial state and calls `Util::addScript`; the entry loops them, calling
`OCA.Dashboard.register(id, cb)` per descriptor. The pattern to copy is
`/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/procest/src/myTasksWidget.js`
and its sibling `*Widget.js` files.

Render through `@conduction/nextcloud-vue`'s `CnWidgetWrapper` with `chrome="nc-dashboard"`.
The library already ships that variant, built to match the native panel's design tokens
exactly: translucent blurred background, `--border-radius-container-large`, a 16px header,
a 20px/700 title, a 32px leading icon. Do not restyle it. A hand-rolled panel that is
nearly right is worse than one that is exactly right, because nobody will notice the drift
until a Nextcloud theme update moves the tokens.

**The bootstrap carries three lessons `src/builder.js` already learned.** All three fail
silently, which is why they are written down rather than left to the reader:

1. Call `registerBuiltinDashboardWidgets()`. The library self-registers its widget types
   through bare side-effect imports in its barrel, which webpack is free to drop and does.
   Without the explicit call the registry is empty and every widget renders "Widget not
   available".
2. Import `gridstack/dist/gridstack.min.css`. It is a peerDependency since nc-vue#557.
   Without it, grid items render 0px wide.
3. `publicPath: 'auto'` is already set globally in `webpack.config.js`. Leave it. A
   hardcoded `/apps/{appId}/js/` breaks async chunks under `custom_apps/`.

### D6. `IAPIWidgetV2` projects items in PHP, or returns an honest empty state

New `lib/Service/Dashboard/WidgetItemProjector.php` computes on the server what the Vue
widget computes in the browser. Model shapes verified in
`/home/rubenlinde/nextcloud-docker-dev/workspace/server/lib/public/Dashboard/Model/`:
`WidgetItem(string $title, string $subtitle, string $link, string $iconUrl, string $sinceId, string $overlayIconUrl)`
and `WidgetItems(array $items, string $emptyContentMessage, string $halfEmptyContentMessage)`.
Signature: `getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems`.

| Placement shape | Projection |
|---|---|
| declarative `dataSource` (`register`, `schema`, `filter`, `order`, `limit`) | `ObjectServiceInterface` query, one `WidgetItem` per row, title from the schema's title field, `link` deep-linking into the app page |
| `aggregate` or stat-shaped | a single `WidgetItem` whose title is the number |
| raw `graphql` dataSource, chart and gauge types | `WidgetItems([], emptyContentMessage: <points at the app page>)` |

The third row is the load-bearing one. Silently returning `0` is the tempting wrong answer
and is forbidden by the spec, because a returned zero is indistinguishable from a real
zero: the user reads a number that was never computed.

### D7. The two OpenRegister filter grammars, each with its own test

OpenRegister's objects endpoint and its aggregations endpoint spell filters **oppositely**,
and each answers the other's shape with a confident wrong number rather than an error:

- The **objects** endpoint wants **bare** keys. It reads `filter[x]` as the empty set.
- The **aggregations** endpoint wants **`filter[x]`**. It **drops** a bare key and returns
  the whole register.

So the row-listing path and the aggregating path of `WidgetItemProjector` speak different
dialects at two adjacent call sites in one class. That is exactly the kind of near-identical
code someone later "tidies up" into a shared helper. Each endpoint gets its own test
asserting the grammar actually sent, so that tidy-up fails loudly.

### D8. Designer UI, and the v2 editor this change waits for

A per-row promote toggle in the v2 widget placement editor, revealing the panel title
and panel icon fields when on, and auto-filling `id` on promote because the schema rule
requires it. Demoting removes `ncDashboard` and keeps the `id`, so a re-promote restores
the same dashboard identity.

**That editor does not exist yet, and this change does not build it.** The designer today
has a v1/v2 split that predates this work: `src/components/page-editor/fields/WidgetBuilder.vue`
authors the **v1** `widgetDef` shape (`id` / `title` / `type`) through
`DashboardPageEditor.vue`'s `config.widgets`, while the **v2** `widgets[]` array that
`ncDashboard` attaches to is only ever *read* in the designer —
`WidgetSelectionPanel.vue` lists it with checkboxes for "save as block", and
`PageDesigner.vue` passes it to `BlockLibraryPanel` as `targetWidgets`. v2 placements are
produced by templates, saved blocks and the copilot, never hand-authored.

Hanging a promote toggle off the v1 editor would attach `ncDashboard` to the wrong `$def`
and would promote widgets that the v2 runtime does not render. So the v2 placement editor
is a prerequisite change (`v2-widget-placement-editor`), named in `depends_on`, and task
8.1 lands the toggle inside it once it exists.

All copy goes through the hydra `writing` skill (`.claude/skills/writing/`,
`references/voice.md`). Section 8 bans em-dashes and Title Case outright. This is a task in
`tasks.md`, not a footnote: the rule was already written and already correct when 126
violations shipped across 12 apps, because a rule only applies when someone loads it. Gate
96 (`manifest-copy-style`) is a backstop with two limits: it only sees manifests, and it
only fires after the text is written.

## Declarative-vs-imperative decision (ADR-031)

ADR-031 names "dashboard widgets" as one of its trigger behaviours, so this section is
mandatory. The honest answer has two halves.

**The declaration is already declarative.** Which widgets reach the Nextcloud dashboard,
what they are called, what icon they carry, where they link and who may see them is stated
in the app manifest, which is the app's declaration surface exactly as ADR-024 intends. No
PHP decides what gets promoted. An author promotes a widget by editing a manifest, and the
change takes effect on the next request with no writeback anywhere.

**The registration plumbing is necessarily imperative,** and falls under ADR-031
§Exceptions(1): OpenRegister's extension vocabulary cannot express it. It requires a
closure factory evaluated per request, `IManager::lazyRegisterWidget()`, container service
registration under a name computed at runtime, and per-request `IGroupManager` calls inside
`isEnabled()`. None of these are OpenRegister calculation vocabulary; none of them can be
declared as schema metadata; and OpenRegister has no Nextcloud-dashboard extension to
declare against.

This is the **same** carve-out that `lib/Service/AppNavigationService.php` already
documents in its class docblock for navigation entries, one level up: "Per ADR-031
§Exceptions this is imperative because nav-entry registration requires a closure factory
evaluated per request, `IGroupManager` per-request calls, and `INavigationManager::add()`,
none of which are OR calculation vocabulary." `VirtualAppWidget` and
`DashboardWidgetRegistrar` carry the same reasoning in their own docblocks, naming
`IManager::lazyRegisterWidget()` in place of `INavigationManager::add()`.

No issue is opened against `openregister` for this: the missing vocabulary is Nextcloud's
dashboard registration API, which is not a data-layer concern and does not belong in
OpenRegister.

## Seed Data (ADR-001)

No OpenRegister schema is introduced or modified by this change. `ncDashboard` is a key
inside the app manifest, a JSON blob stored on an existing Application object, not a
property on an OpenRegister schema in a register. No seed objects are required and no seed
task appears in `tasks.md`.

## Risks / Trade-offs

**R1. The registration mechanism rests on behaviour documented by implementation, not by
contract.** `queryNoFallback()` returning a registered service before attempting to
instantiate the name is true of Nextcloud 33 and is not promised by any interface. If an
upgrade changes it, the failure mode is widgets that quietly stop appearing, which looks
exactly like a working system where no user has configured any widgets.

→ Mitigation, and it is the single most important test in this change: **the pin test.**
Register a service under a synthetic name, `lazyRegisterWidget` it, assert
`IManager::getWidgets()` contains the id. It fails loudly on the Nextcloud version that
breaks the assumption, at the moment that version is first run, instead of at a user's
report months later.

→ Fallback if the pin ever breaks: generate one real PHP class per promoted widget into a
writable app directory and register those. More moving parts, a cache-invalidation story
on every manifest save, and an autoloader entry, which is exactly why it is the fallback
and not the design.

**R2. Widget ids cannot be changed after release.** See D2. → Mitigation: a regression test
that renames an app's slug and asserts every widget id is unchanged. The test exists to
fail when someone later decides the slug reads better in the id.

**R3. `getItemsV2()` can silently disagree with what the browser renders.** Two
implementations of one calculation, one in PHP and one in Vue, drift. → Mitigation: the
projector returns an empty state rather than a number for every shape it cannot compute
from the declarative data source, so drift shows up as "open the app" and never as a wrong
number. Only genuinely declarative shapes get projected at all.

**R4. The two filter grammars.** See D7. Each endpoint answers the wrong shape with a
confident wrong number. → Mitigation: one test per endpoint, asserting the outbound query
string, not just the returned count. A test that only checks the count passes against the
whole register.

**R5. A slow manifest walk runs on every request boot.** Every published Application's
manifest is walked on every request. → Mitigation: `PublishedApplicationProvider` makes it
one query, and the walk itself is over already-loaded JSON. `lazyRegisterWidget` means no
widget object is constructed unless the dashboard actually loads.

**R6. A stale lockfile makes the whole feature silently inert.** The declared range
`^3.2.0` permits the new library release, but the lockfile decides which code is installed.
A branch that forgets the lockfile bump gets a validator that rejects `ncDashboard` and a
designer toggle that cannot save. → Mitigation: the lockfile bump is its own task, and the
manifest-validation test asserts `ncDashboard` validates against the installed schema, so a
stale lockfile fails a test rather than shipping a dead toggle.

## Migration Plan

1. `nc-dashboard-widget-flag` lands in `ConductionNL/nextcloud-vue` and is released as a
   minor.
2. The `ncDashboard` property is added to the vendored gate copy in `ConductionNL/.github`.
   Only that property: the copy is already stale (it lacks `roles` and `visibleWhen`), and
   gates resolve `@main` and go fleet-wide the minute they merge, so a full resync is a
   separate change with its own blast radius.
3. This change lands, including the `package-lock.json` bump.

No data migration. Nothing existing is promoted by default, so an instance that upgrades
and promotes nothing behaves exactly as before.

**Rollback.** Revert this change. Registration stops, the panels disappear from every
dashboard, and the stored per-user widget ids in the Dashboard app's namespace become
inert rows that come back if the change is re-applied with the same id derivation. Nothing
in the manifest needs unwinding: an `ncDashboard` object on a placement is simply not read.

### D9. `roles` gates a promoted widget. `visibleWhen` does not.

A promoted widget's visibility is decided by its `roles` array alone. The placement's
`visibleWhen` expression is ignored on the Nextcloud dashboard, and a widget is not made
unpromotable for carrying one.

`visibleWhen` evaluates against page context — the loaded object, route params, the page's
own filters. On the Nextcloud dashboard none of that exists. Honouring the expression would
mean inventing values for a context that is genuinely absent, and every answer it produced
would be an artefact of the values we invented rather than anything the author meant. An
expression that cannot be evaluated is not evaluated.

The panel is not a substitute for the page: a widget whose meaning depends on page context
still renders on the dashboard, and its `link` takes the reader to the page where that
context is real.

### D10. `ncDashboard.order` is an initial hint, and the user's own arrangement wins

`order` is passed through as `IWidget::getOrder()`, which decides where the panel first
appears. Once a user rearranges their dashboard, their arrangement wins and the declared
order stops mattering. This is how every other app's widget order already behaves in
Nextcloud, so the field promises exactly what the platform delivers and nothing more.

## Open Questions

None outstanding. D9 and D10 record the two that were open when this design was drafted.
