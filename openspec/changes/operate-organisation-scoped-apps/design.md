# Design: operate-organisation-scoped-apps

Read at buildiq development `d21e42f` and openregister development `ae898b0`.

## Where it sits

- Application: the `Application` schema (slug `built-app`) in
  `lib/Settings/openbuild_register.json`, with `permissions` (owners, editors,
  viewers) and `allowUserOverrides` (line 189). OpenRegister stamps an
  `organisation` on each object it saves.
- Global reads: `_multitenancy: false` appears 127 times under `lib/`. The
  decision is written down in `ManifestResolverService::findApplicationBySlug()`
  (`lib/Service/ManifestResolverService.php:229-265`, comment at lines 231-239),
  in `ApplicationsController::provisionPerAppRegister()` (comment at
  `lib/Controller/ApplicationsController.php:2188-2196`) and in
  `RuleEngineService` (lines 275-279 and 443-448).
- Entry points that resolve or list apps: `ManifestResolverService::resolve()`
  (line 107, the manifest endpoint and the runtime), `AppNavigationService::registerNavEntries()`
  (`lib/Service/AppNavigationService.php:141`, reading `getPublishedApplications()`
  at line 360), the MCP handlers `ListAppsHandler::handle()`
  (`lib/Mcp/Handler/ListAppsHandler.php:44`, query at line 64) and
  `GetAppManifestHandler`, and the app list page (`VirtualApps` in
  `src/manifest.json`), which reads through OpenRegister's objects API.
- Creation paths: `ApplicationCreationService::createApplication()`
  (`lib/Service/ApplicationCreationService.php:147`) and
  `ApplicationsController::installFromTemplateArray()`
  (`lib/Controller/ApplicationsController.php:1670`), which the store, the
  GitHub shop and the import all reach.
- Settings: `src/modals/AppSettingsModal.vue`.
- OpenRegister: `MultiTenancyTrait::applyOrganisationFilter()`
  (`lib/Db/MultiTenancyTrait.php:380`), and `OrganisationService` for membership
  and the active organisation.

## D1. Scope is a property of the app, and "all" is the default for what exists

A new optional `organisationScope` on `Application` (`all` or `holder`), declared
in `lib/Settings/register.d/25-organisation-scope.json` with the register version
bumped. Absent means `all`, so every existing app behaves as today without a
migration. The holder is the organisation OpenRegister stamped on the
Application; buildiq does not keep a second copy.

## D2. One guard at every door

`lib/Service/AppOrganisationGuard.php::canSee(array $application, ?IUser $user)`
answers true for scope `all`, and for scope `holder` only when the user is a
member of the holder organisation (or of an organisation OpenRegister counts as
its child). It is called at each entry point listed above, before the role check,
and a `false` becomes the same "not found" an unknown slug gets, so the answer
never tells a stranger the app exists. The 127 global reads stay as they are;
the guard is applied to what they return. A test lists the entry points and
fails when a new one resolves an app without the guard.

## D3. The data follows the app

When a scoped app is created, its per-version registers and schemas are saved
while the creator's active organisation is the holder's, so OpenRegister holds
them there and stamps each record with it. Members of other organisations then
cannot read the records through OpenRegister, whatever buildiq does. For an `all`
app nothing changes.

## D4. Slugs stay unique on the instance

Making slugs unique per organisation would change the URL of every app
(`/apps/buildiq/builder/{slug}`) and the per-version register names, which is the
break `ApplicationsController.php:2188-2196` warns about. Slugs stay unique
instance-wide. A collision with a scoped app of another organisation is refused
with "This slug is taken. Choose another.", the same message as any collision,
without naming the other organisation.

## D5. Who sets the scope

The owner sets it in the app settings. Switching `holder` to `all` shows a
confirmation that every organisation will see the app (its records stay with the
holder). Switching `all` to `holder` hides the app from other organisations at
once. The setting is only offered when OpenRegister's multitenancy is on; with it
off, organisations do not exist for OpenRegister and the scope has no effect.

## Risks

- A missed entry point would show a scoped app to a stranger. D2's test is the
  control, and the guard fails closed: an app whose scope cannot be read is
  treated as `holder`.
- The list page reads OpenRegister directly (sibling check). Until that check is
  done, the page's behaviour for `all` apps held by another organisation is not
  known, and the change does not ship before it is.
- Rule sets and data registers stay instance-wide; an owner may assume otherwise.
  The settings text says what the scope covers.

## What it does not do

- It does not partition slugs, rule sets or data registers per organisation.
- It does not move apps or records between organisations.
