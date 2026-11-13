# Tasks: built-app-connections-page

## 1. Backend
- [ ] 1.1 Add `ApplicationConnectionsService::list(string $appId)` that reads the app's manifest data-source bindings (integriq source ids) and the integriq `app_connection` rows with `app` equal to the app's slug, and returns one list with kind (source or integration), name, status, status message and checked-at.
- [ ] 1.2 Expose it on `GET /api/applications/{id}/connections`, guarded by the same edit-or-admin check as the application detail, before any read.
- [ ] 1.3 When integriq is not installed, return the manifest's sources with no status and a flag that health needs integriq.

## 2. Interface
- [ ] 2.1 Add a Connections tab to the application detail page (BqAppWerking) that renders the list with the `connectionStatus` formatter, all seven integriq statuses and "Not checked".
- [ ] 2.2 Each row links to the source or connection in integriq.

## 3. Tests
- [ ] 3.1 PHPUnit for 1.1 to 1.3: rows of another app are never returned; a user who may not edit the app gets 403; without integriq the sources are listed with no status.
- [ ] 3.2 Vitest for the tab's status labels in English and Dutch.

## 4. Capability row
- [ ] 4.1 When 1 to 3 are done, set `int-connection-registry` to built with this change's evidence and archive the change.
