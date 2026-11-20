# Tasks: built-app-connections-page

## 1. Backend
- [x] 1.1 Add `ApplicationConnectionsService::list(string $appId)` that reads the app's manifest data-source bindings (integriq source ids) and the integriq `app_connection` rows with `app` equal to the app's slug, and returns one list with kind (source or integration), name, status, status message and checked-at.
  - Done: `lib/Service/ApplicationConnectionsService.php` `list(appSlug, manifest)`: every `connector.endpointPath` in the manifest, resolved through integriq's `endpoint` (`targetType: api`) to its `source`, plus the `app_connection` rows whose `app` is the slug (filtered by integriq and again in PHP). A source's health is the check on one of this app's connection rows naming it; otherwise not checked. Names, statuses, messages and check times only, never a source's configuration.
- [x] 1.2 Expose it on `GET /api/applications/{id}/connections`, guarded by the same edit-or-admin check as the application detail, before any read.
  - Done: `ApplicationConnectionsController::index` on `GET /api/applications/{id}/connections` (`{id}` is the slug), owners or editors of the app or an admin, checked before the manifest or any connection is read; documented in `openapi.json`.
- [x] 1.3 When integriq is not installed, return the manifest's sources with no status and a flag that health needs integriq.
  - Done: without integriq the manifest's endpoint paths are listed with no status and `healthNeedsIntegriq: true`, and integriq is not read.

## 2. Interface
- [x] 2.1 Add a Connections tab to the application detail page (BqAppWerking) that renders the list with the `connectionStatus` formatter, all seven integriq statuses and "Not checked".
  - Done: `src/components/applicationDetail/ApplicationConnectionsSection.vue` in the detail dashboard (shown to owners, editors and admins): kind, name, status with message, last check. The library's `connectionStatus` formatter is not exported, so `src/services/appConnections.js` renders the same seven labels plus "Not checked" from buildiq's catalogue, and says that health needs integriq when it is absent.
- [x] 2.2 Each row links to the source or connection in integriq.
  - Done: a source links to `/apps/integriq/sources/{uuid}`, an integration to `/apps/integriq/connections?app={slug}`; a source without an integriq record has no link.

## 3. Tests
- [x] 3.1 PHPUnit for 1.1 to 1.3: rows of another app are never returned; a user who may not edit the app gets 403; without integriq the sources are listed with no status.
  - Done: `tests/Unit/Service/ApplicationConnectionsServiceTest.php` (4) and `tests/Unit/Controller/ApplicationConnectionsControllerTest.php` (4).
- [x] 3.2 Vitest for the tab's status labels in English and Dutch.
  - Done: `tests/components/applicationDetail/ApplicationConnectionsSection.spec.js` (4), the Dutch labels read from `l10n/nl.json`.

## 4. Capability row
- [ ] 4.1 When 1 to 3 are done, set `int-connection-registry` to built with this change's evidence and archive the change.
  - Left for after merge: flipping the capability and archiving belong to the merged change.
