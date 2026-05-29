## MODIFIED Requirements

### Requirement: REQ-OBR-005 Textarea manifest editor saves to the Application object

The OpenBuilt shell SHALL render a **tabbed Application editor** for
the `manifest` field of an `Application` object, composed of two
sibling tabs sharing one in-flight manifest state:

1. **"Design"** (default tab) — mounts the visual `PageDesigner.vue`
   shipped by the `openbuilt-page-designer` capability. The designer
   authors the manifest through structured per-page-type sub-editors
   and a menu-tree editor; see the `openbuilt-page-designer`
   capability spec for its full requirements.
2. **"Raw JSON"** — the original JSON `<textarea>`-based editor (the
   integrator-only fallback path).

Both tabs SHALL:
(a) load the current `manifest` blob from OR via the standard OR REST
API at view mount; (b) validate the edited blob client-side using
`validateManifest` from `@conduction/nextcloud-vue` and refuse to save
when validation fails, surfacing the failing JSON path in the
shared error surface; (c) on successful validation, PUT the updated
Application back to OR via the same REST endpoint used by spec #1.
The shared in-flight manifest state SHALL persist across tab switches
without saving, so edits made in one tab are visible in the other on
tab change.

#### Scenario: Invalid edit is blocked before save

- **WHEN** an integrator pastes a manifest blob missing the required
  `pages` array into the Raw JSON tab and clicks Save
- **THEN** the shared error surface cites the missing `pages` field
- **AND** no PUT request is sent to OR
- **AND** the Design tab disables its inputs and surfaces the parse
  / validation error in its side-panel error list

#### Scenario: Valid edit persists and reloads

- **WHEN** an integrator pastes a valid manifest blob in the Raw JSON
  tab and clicks Save
- **THEN** the editor sends a PUT to OR's Application endpoint
- **AND** reloading the editor surfaces the new manifest in both tabs

#### Scenario: Default tab is Design

- **WHEN** the user opens the Application editor view for an existing
  Application
- **THEN** the Design tab is selected by default
- **AND** the Raw JSON tab is accessible as a sibling tab

#### Scenario: Unsaved edits survive a tab switch

- **WHEN** the user edits a page title in the Design tab and switches
  to the Raw JSON tab without saving
- **THEN** the textarea's JSON content reflects the unsaved page title
- **AND** the dirty indicator persists across the tab switch
