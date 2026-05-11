## ADDED Requirements

### Requirement: REQ-OBR-006 Schema designer routes mounted under the builder host

The OpenBuilt frontend router SHALL register two new routes under the
existing `/builder/:slug/*` host (from `bootstrap-openbuilt`
REQ-OBR-002 / REQ-OBR-003):

- `/index.php/apps/openbuilt/builder/:slug/schemas` — schema list.
- `/index.php/apps/openbuilt/builder/:slug/schemas/:schemaId` —
  schema detail / designer.

Both routes SHALL be rendered by `src/views/SchemaDesigner.vue` and
SHALL be registered under the OpenBuilt **outer** router (not the
nested-CnAppRoot inner router). The Schemas surface is a meta-tool
that authors the data model OF a virtual app and SHALL stay scoped to
the OpenBuilt shell so the user can navigate between schema authoring
and the virtual app's runtime preview without re-mounting the nested
CnAppRoot. The existing `/builder/:slug/*` virtual-app preview route
from `bootstrap-openbuilt` SHALL continue to mount the nested
CnAppRoot for the runtime preview and SHALL be unaffected by this
addition.

#### Scenario: Schema list route renders the designer, not the virtual app

- **WHEN** an authenticated user navigates to
  `/index.php/apps/openbuilt/builder/hello-world/schemas`
- **THEN** the OpenBuilt outer shell renders `SchemaDesigner.vue`
- **AND** the nested `CnAppRoot` for `hello-world` is NOT mounted on
  this route

#### Scenario: Virtual-app preview route still mounts the nested CnAppRoot

- **WHEN** an authenticated user navigates to
  `/index.php/apps/openbuilt/builder/hello-world`
- **THEN** the nested `CnAppRoot` for `hello-world` mounts per
  REQ-OBR-002 (bootstrap-openbuilt)
- **AND** the Schemas menu entry is reachable from the outer shell's
  navigation

### Requirement: REQ-OBR-007 Schemas menu entry surfaced in the builder host

`src/views/BuilderHost.vue` SHALL surface a **Schemas** menu entry in
the OpenBuilt outer-shell secondary navigation while the user is in a
virtual app's builder context. Activating the entry SHALL route to
`/builder/{slug}/schemas`. The entry SHALL be visible to any user
authorised to read the virtual app's Application object; chain spec
`openbuilt-rbac` (#7) MAY narrow this visibility further. The menu
entry SHALL use a translation key (`openbuilt.builder.menu.schemas`)
in both `l10n/en.json` and `l10n/nl.json`.

#### Scenario: Schemas entry appears in the builder context

- **WHEN** an authenticated user opens
  `/index.php/apps/openbuilt/builder/hello-world`
- **THEN** the outer shell's secondary navigation includes a
  **Schemas** entry
- **AND** clicking the entry navigates to
  `/builder/hello-world/schemas`
