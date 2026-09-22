# Tasks: publish-widgets-to-nc-dashboard

Reference `specs/` for what must be true and `design.md` for how. Task 4.4 is the pin test
and the most important one in this change: the registration mechanism rests on behaviour
documented by implementation rather than by contract, and its failure mode is widgets that
quietly stop appearing.

## 1. Library dependency and manifest schema

- [ ] 1.1 Move `package-lock.json` onto the released `@conduction/nextcloud-vue` carrying `ncDashboard`, then verify `npm ls @conduction/nextcloud-vue` reports that version and `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json` contains `ncDashboard` under `$defs/widgetEntry`.

> Deferred: waits on a published `@conduction/nextcloud-vue` release carrying `ncDashboard` in `$defs/widgetEntry`. That schema change is in flight in `ConductionNL/nextcloud-vue` and does not exist in any release yet.

Acceptance criteria:
- The declared range `^3.2.0` is left alone. The lockfile is what changes.
- A stale lockfile fails task 1.2 rather than shipping a designer toggle that cannot save.

- [ ] 1.2 Add a vitest manifest-validation spec running the installed schema over three fixtures, and verify it passes for a valid `ncDashboard` and fails for the two invalid ones.

> Deferred: waits on a published `@conduction/nextcloud-vue` release carrying `ncDashboard` in `$defs/widgetEntry`. That schema change is in flight in `ConductionNL/nextcloud-vue` and does not exist in any release yet.

Acceptance criteria:
- A placement with `ncDashboard` and a non-empty `id` validates.
- A placement with `ncDashboard` and an absent or empty `id` fails, naming the placement.
- A placement whose `ncDashboard` carries a key outside `title`, `icon`, `order`, `link` fails, naming the key.

## 2. One query per request

- [x] 2.1 Extract `lib/Service/PublishedApplicationProvider.php`, move `AppNavigationService`'s per-request `$cachedApplications` into it, repoint both callers, and verify a PHPUnit test counting object-service calls across one boot reports exactly one read.

Acceptance criteria:
- `AppNavigationService` behaviour is unchanged; its existing tests still pass untouched.
- Registering dashboard widgets adds no second query.

## 3. Descriptor and identity

- [x] 3.1 Build a widget descriptor from a published Application's production manifest, collecting every `widgets[]` entry carrying `ncDashboard`, and verify a PHPUnit test over a fixture manifest returns one descriptor per promoted entry and none for unpromoted entries.

Acceptance criteria:
- Title, icon, order and link come from `ncDashboard`; widget key, data source and roles come from the placement.
- Only published Applications are walked. Draft and archived are skipped.

- [x] 3.2 Derive the widget id as `buildiq-{applicationUuid}-{widgetEntryId}`, normalised deterministically into `^[a-z][a-z0-9\-_]*$`, and verify a PHPUnit regression test that renames an Application's slug and asserts every id is byte-identical before and after.

Acceptance criteria:
- The slug and the app name appear nowhere in the derivation.
- Re-deriving from the same entry twice gives the same id.
- The test names, in its failure message, that a changed id drops the panel from every dashboard that had it with no error anywhere.

## 4. Registration

- [x] 4.1 Add `lib/Dashboard/VirtualAppWidget.php` implementing `IWidget` and `IIconWidget` from a descriptor, including `load()` providing this user's visible descriptors as initial state and calling `Util::addScript`, and verify a PHPUnit test asserts the id, title, icon url, order and link.

Acceptance criteria:
- The class docblock carries the ADR-031 §Exceptions reasoning, mirroring `AppNavigationService`'s, naming `IManager::lazyRegisterWidget()`.

- [x] 4.2 Add `lib/Service/DashboardWidgetRegistrar.php` registering one container service per descriptor under a synthetic name and calling `IManager::lazyRegisterWidget()` for each, and verify a PHPUnit test asserts one registration per promoted widget and none for unpromoted ones.

Acceptance criteria:
- No class is generated and none is faked. The synthetic name is a service name that is never autoloaded.

- [x] 4.3 Call the registrar from `Application::boot()` beside the existing `AppNavigationService` call inside a guard that never throws, and verify a PHPUnit test with a failing object service completes boot, logs a warning and registers nothing.

Acceptance criteria:
- An instance without OpenRegister still boots and renders its other dashboard widgets.

- [x] 4.4 Add the pin test: register a service under a synthetic name, `lazyRegisterWidget` it, and verify `IManager::getWidgets()` contains the derived id.

Acceptance criteria:
- The test runs against the live Nextcloud container, not a double.
- Its failure message states that the synthetic-service resolution path has changed, points at `design.md` R1, and names the fallback: one generated class per promoted widget.
- Two widgets from one class under two names both appear, each under its own id.

## 5. Permissions

- [x] 5.1 Implement `IConditionalWidget::isEnabled()` on `VirtualAppWidget` reusing `PermissionResolver` plus the placement's `roles`, in `AppNavigationService`'s existing check order, and verify a PHPUnit matrix covering all four branches.

Acceptance criteria:
- The `group:*` sentinel makes the widget visible to every signed-in user.
- `user:<uid>`, `group:<gid>` or bare gid, and the Nextcloud admin bypass each have a case.
- No second copy of the check order is written. Both call sites share one implementation.

## 6. Browser rendering

- [x] 6.1 Add the `ncDashboard` entry to `webpackConfig.entry` in `webpack.config.js` emitting `buildiq-ncDashboard.js`, write `src/ncDashboard.js` looping the initial-state descriptors into `OCA.Dashboard.register(id, cb)`, and verify a production build emits the file and the app's dashboard panel renders in a browser.

Acceptance criteria:
- `registerBuiltinDashboardWidgets()` is called. Without it the registry is empty and every widget renders "Widget not available".
- `gridstack/dist/gridstack.min.css` is imported. Without it grid items render 0px wide.
- `publicPath: 'auto'` is left as it is, globally set. A hardcoded `/apps/{appId}/js/` breaks async chunks under `custom_apps/`.
- `src/builder.js` and `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/procest/src/myTasksWidget.js` are the patterns followed.

- [x] 6.2 Render each widget through `@conduction/nextcloud-vue`'s `CnWidgetWrapper` with `chrome="nc-dashboard"`, and verify a vitest spec mounts a widget from a descriptor and a second one asserts an unresolvable widget key shows the unknown-widget state naming the key.

Acceptance criteria:
- No panel styling is written in this app. The library variant already matches the native tokens.
- An unresolvable key is visible as unknown, never as a blank panel.

## 7. Item projection for clients

- [x] 7.1 Add `lib/Service/Dashboard/WidgetItemProjector.php` with `getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems`, projecting a declarative `dataSource` to one `WidgetItem` per row, and verify a PHPUnit test asserting the outbound objects query sends BARE filter keys.

Acceptance criteria:
- Each item's title comes from the schema's title field and its link deep-links into the app page the widget sits on.
- The test asserts the query string actually sent, not only the returned count. A count-only assertion passes against the whole register.

- [x] 7.2 Project an aggregating data source to a single `WidgetItem` whose title is the number, and verify a PHPUnit test asserting the outbound aggregations query sends BRACKETED `filter[...]` keys.

Acceptance criteria:
- The two grammars are tested separately, because the objects endpoint reads `filter[x]` as the empty set and the aggregations endpoint drops a bare key and returns the whole register. Each answers the other's shape with a confident wrong number rather than an error.

- [x] 7.3 Return `WidgetItems([], emptyContentMessage: …)` pointing at the app page for a raw `graphql` data source and for the chart and gauge types, and verify a PHPUnit test asserting no numeric value appears in the response.

Acceptance criteria:
- Zero is never returned as a stand-in. A returned zero is indistinguishable from a real zero.
- The empty message names the app page where the widget can be seen.

## 8. Designer

- [ ] 8.1 Add the per-widget promote control to the v2 widget placement editor delivered by `v2-widget-placement-editor`, revealing panel title and panel icon when on, auto-filling `id` on promote and keeping it on demote, and verify vitest specs for promote, demote and the untouched-page no-op.

> Deferred: needs both the published `ncDashboard` schema and the v2 placement editor from `v2-widget-placement-editor` (PR #894). There is no v2 editor for the toggle to live in yet, and hanging it off `WidgetBuilder.vue` would attach `ncDashboard` to the v1 `widgetDef` shape it does not belong to.

Acceptance criteria:
- The control is off for every existing widget. Opening and saving a page promotes nothing.
- The control lands on the v2 placement editor, never on `WidgetBuilder.vue`, which authors the v1 `widgetDef` shape that `ncDashboard` does not attach to.
- The icon field accepts only names from the shared semantic icon set.
- Re-promoting a demoted widget produces the same dashboard widget id as before.

- [x] 8.2 Load the hydra `writing` skill (`.claude/skills/writing/`, `references/voice.md`) and run every new user-facing string and the app docs page for this feature through it, then verify no em-dash and no Title Case remains in the added copy.

Acceptance criteria:
- Section 8 bans em-dashes and Title Case outright.
- Gate 96 is a backstop that only sees manifests and only fires after the text is written. Passing it is not evidence the copy is good.
- The docs page states plainly that a widget id cannot be changed after a user has added the panel.

## 9. End to end

- [ ] 9.1 Add a Playwright spec that promotes a widget in the designer, finds it in the Nextcloud dashboard widget picker, adds it and asserts it renders, and verify the run is green against the live instance.

> Deferred: the spec starts by promoting a widget in the designer, which needs task 8.1, which needs the published `ncDashboard` schema and the v2 placement editor.

Acceptance criteria:
- The spec carries `@e2e` annotations for `nc-dashboard-widgets::every-promoted-widget-becomes-a-selectable-dashboard-widget`, `nc-dashboard-widgets::a-user-outside-every-listed-role-is-not-offered-the-widget`, `nc-dashboard-widgets::a-promoted-widget-renders-with-the-native-panel-chrome`, `openbuild-page-designer::promoting-a-widget-assigns-it-a-stable-id` and `openbuild-page-designer::demoting-a-widget-keeps-its-identity`, so hydra gate 19 passes on the changed scenarios.
- A second user outside every listed role is asserted not to be offered the widget.
- Fixture values are obviously fake placeholders only.
