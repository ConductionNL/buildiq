## ADDED Requirements

### Requirement: Page widgets MUST be filterable by roles

A widget on a page MAY declare `roles`, a list of principals that may see it. `ManifestResolverService::filterManifestForCaller` MUST remove every page widget whose `roles` the caller does not match from the response payload, server-side, wherever the page declares widgets: `widgets[]`, `config.widgets[]`, `config.sidebarTabs[].widgets[]`, and the page shapes in `pageTemplates[]` and `pageInstances[]`. Matching MUST follow the Nextcloud dashboard's check order (`AppVisibilityResolver`): `group:*` matches every signed-in user, `user:<uid>` matches that user, `group:<gid>` or a bare group id matches its members, and a Nextcloud admin matches any roles. A widget without `roles` MUST stay visible to every reader of the page. An unauthenticated caller MUST receive no widget that declares `roles`. A `config.layout[]` row or a tabs widget's `content.tabs[]` entry that names a removed widget MUST be removed with it. Admins and callers holding an owner or editor role on the Application MUST receive the manifest unfiltered.

@e2e exclude server-side manifest filtering verified by ManifestWidgetRoleFilterTest and ManifestResolverServicePermissionFilterTest (testViewerOutsideWidgetRolesNeverReceivesTheWidget, testViewerInWidgetRolesReceivesTheWidget, testOwnerEditorAndAdminKeepEveryWidget); live E2E deferred, no deploy to the shared dev instance

#### Scenario: A finance widget does not reach a vet

- **GIVEN** a dashboard page with an open widget and a widget declaring `roles: ["finance"]`
- **WHEN** a viewer in `vets`, not an owner, editor or admin, requests the manifest
- **THEN** the finance widget is absent from the page in the response
- **AND** its `config.layout[]` row and any tab naming it are absent too
- **AND WHEN** a user in `finance` requests the manifest
- **THEN** both widgets are present

#### Scenario: Builders see every widget

- **GIVEN** the same page
- **WHEN** an owner, an editor or a Nextcloud admin requests the manifest
- **THEN** both widgets are present, whatever their groups
