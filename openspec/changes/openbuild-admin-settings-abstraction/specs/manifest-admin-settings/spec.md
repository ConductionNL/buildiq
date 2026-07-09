## ADDED Requirements

### Requirement: Manifest declares admin settings sections

The `app-manifest-v2` schema (`@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json`) SHALL support a top-level `adminSettings` array, sibling to `pages` and `menu`, in which an app declares admin-only settings sections. Each entry SHALL be an object with a required unique `id` (string) and a required `label` (string, an English-source i18n key), an optional integer `order`, an optional `permission` string reusing the existing per-item permission grammar, and exactly one of `type` (a built-in section) or `component` (a custom section resolved from the renderer registry). The entry object SHALL set `additionalProperties: false`. The `type` field SHALL be a closed enum whose only member introduced by this capability is `organisation-credentials`. Existing manifests that omit `adminSettings` SHALL continue to validate unchanged.

#### Scenario: Manifest with a built-in admin section validates

- **WHEN** a manifest declares `adminSettings: [{ "id": "org-credentials", "type": "organisation-credentials", "label": "myapp.admin.orgCredentials", "order": 10 }]`
- **THEN** the manifest SHALL validate against `app-manifest-v2`
- **AND** the entry SHALL be exposed to the renderer as an admin-settings section

#### Scenario: Manifest with a custom admin section validates

- **WHEN** a manifest declares `adminSettings: [{ "id": "billing", "component": "AdminBillingSection", "label": "myapp.admin.billing" }]`
- **THEN** the manifest SHALL validate
- **AND** the `component` SHALL be resolved against the renderer's custom-components registry at render time

#### Scenario: An entry with neither type nor component is rejected

- **WHEN** an `adminSettings` entry declares neither `type` nor `component`
- **THEN** manifest validation SHALL fail

#### Scenario: An entry with both type and component is rejected

- **WHEN** an `adminSettings` entry declares both a `type` and a `component`
- **THEN** manifest validation SHALL fail

#### Scenario: An unknown built-in type is rejected

- **WHEN** an `adminSettings` entry declares `type` with a value outside the closed enum (e.g. `type: "made-up"`)
- **THEN** manifest validation SHALL fail

#### Scenario: Existing manifests without adminSettings still validate

- **WHEN** a current manifest that has no `adminSettings` key is validated
- **THEN** it SHALL validate unchanged and SHALL produce no admin surface

### Requirement: Admin settings render in a generic admin dialog

`CnAppRoot` SHALL render a generic admin `NcAppSettingsDialog`, distinct from the personal user-settings dialog, populated from `manifest.adminSettings[]` sorted by `order`. Each entry SHALL render as one `NcAppSettingsSection` keyed by the entry `id`. A `type: "organisation-credentials"` entry SHALL render `CnCredentials` with `scope="organisation"`. A `component` entry SHALL render the registered component, forwarding the entry's optional `props`. The admin dialog SHALL be openable via a dedicated inject (parallel to the personal-settings inject) and SHALL NOT alter the personal user-settings dialog, its `action: "user-settings"` wiring, or its content.

#### Scenario: Organisation-credentials section renders the broker pane

- **WHEN** the admin dialog opens for a manifest whose `adminSettings` contains an `organisation-credentials` entry
- **THEN** the dialog SHALL contain an `NcAppSettingsSection` rendering `CnCredentials scope="organisation"`

#### Scenario: Custom section renders its registered component

- **WHEN** the admin dialog opens for a manifest whose `adminSettings` contains a `component` entry whose key is registered
- **THEN** the dialog SHALL render that component with the entry's `props`

#### Scenario: Sections are ordered by order then array position

- **WHEN** multiple `adminSettings` entries declare `order` values
- **THEN** the sections SHALL appear in ascending `order`, falling back to array position when `order` is absent

#### Scenario: Personal user-settings surface is unaffected

- **WHEN** the admin dialog capability is present
- **THEN** the personal user-settings dialog, its `action: "user-settings"` trigger, `CnNotificationPreferences`, and the personal Credentials pane SHALL behave exactly as before

### Requirement: No admin surface without adminSettings

The renderer SHALL treat an absent `adminSettings` key and an empty `adminSettings` array identically: it SHALL render no admin nav entry and SHALL NOT mount the admin dialog. The built-in `organisation-credentials` pane SHALL only appear when explicitly declared as an `adminSettings` entry; it SHALL NOT be hardcoded into the shell.

#### Scenario: Absent adminSettings yields no admin surface

- **WHEN** a manifest has no `adminSettings`
- **THEN** no admin nav entry SHALL be auto-included and no admin dialog SHALL mount

#### Scenario: Empty adminSettings yields no admin surface

- **WHEN** a manifest declares `adminSettings: []`
- **THEN** the renderer SHALL behave identically to an absent `adminSettings`
