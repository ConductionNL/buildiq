## MODIFIED Requirements

### Requirement: Live-preview pane mounts a sandboxed CnAppRoot when available

The Page Designer SHALL provide an optional right-hand pane that mounts a
**sandboxed** `CnAppRoot` instance configured from the in-flight (unsaved)
manifest, so the user sees their edits render live without saving. The
pane SHALL be considered available **only when** the in-memory
`useAppManifest(appId, manifestObject)` overload from chain spec #2
(`nextcloud-vue-in-memory-manifest`) is detected at runtime — this
detection is already implemented by `useLivePreview()`. When available,
the Page Designer SHALL actually render the sandboxed `CnAppRoot` (not
merely detect availability and leave the pane empty). When the overload
is absent, the pane SHALL collapse to a "save & reload" affordance that
opens `/builder/:slug` in a new browser tab against the last saved
manifest, with an inline i18n note explaining the limitation.

@e2e exclude mixed spec — sandboxed `CnAppRoot` re-mount on in-flight manifest change (in-memory `useAppManifest` overload from chain spec #2, now shipped) is verified by Vitest component tests mocking `useLivePreview`; the fallback "Save & open preview" button remains covered by the openbuild-page-designer Playwright tests

**ID:** REQ-OBPD-008

The sandboxed `CnAppRoot` SHALL:

- Use a unique `appId` of `openbuild-preview-{slug}` so its state
  does not collide with the production-mounted virtual app.
- Receive the manifest as an in-memory object (no fetch).
- Re-mount via a `:key` bound to the manifest's content hash, so any
  manifest edit re-renders the preview cleanly.
- Receive the same `registry`/`pageTypes`/`custom-components` props the
  production `CnAppRoot` mount (`App.vue`) uses, so custom-page
  components resolve identically in the preview as they do in the built
  app.
- Never issue a write (PUT/POST) call against OpenRegister or the
  manifest-save endpoint.

#### Scenario: Preview pane renders the in-flight manifest

- **WHEN** chain spec #2's in-memory manifest loader is available
- **AND** the user edits a page's `title` field
- **THEN** the right-hand pane re-renders the preview with the new
  title visible
- **AND** no PUT request is sent to OR

#### Scenario: Fallback when in-memory loader is unavailable

- **WHEN** chain spec #2's in-memory manifest loader is NOT detected
- **THEN** the right-hand pane displays a "Save & open preview" button
  and no sandboxed `CnAppRoot` is mounted

#### Scenario: Preview pane is not left blank when the loader IS detected

- **WHEN** chain spec #2's in-memory manifest loader IS detected
  (`previewAvailable` is `true`)
- **THEN** the right-hand pane renders the sandboxed `CnAppRoot`
- **AND** the pane is never left empty (neither the fallback message nor
  a blank space)

### Requirement: Custom-page sub-editor reads the customComponents registry

`CustomPageEditor.vue` SHALL surface a **component-name picker**
populated from the consuming app's `customComponents` registry —
specifically, the keys of the `customComponents` prop passed to the
sandboxed `CnAppRoot` mounted by the live-preview pane (REQ-OBPD-008).
Now that the live-preview pane is implemented and mounts `CnAppRoot`
with a live `custom-components` prop, the picker SHALL read those keys
from the actually-mounted preview instance rather than only supporting
the free-text fallback. When the live-preview pane is unavailable (chain
spec #2 not detected), the picker SHALL accept a free-text string and
surface a warning that the value cannot be validated until preview is
enabled. The sub-editor SHALL also expose a free-form JSON editor for the
`config` sub-shape, because the canonical schema explicitly allows
`type: custom` configs to be "any shape the custom component
expects".

@e2e exclude visual-editor component spec — registry-backed picker from live-preview `customComponents` prop keys, free-text fallback warning when in-memory loader is absent, and free-form JSON config editor are CustomPageEditor.vue component contracts verified by Vitest unit tests; no independent Playwright-testable URL surface

**ID:** REQ-OBPD-007

#### Scenario: Registry-backed picker lists known components

- **WHEN** the live-preview pane is active and the registry exposes
  three custom-component keys
- **THEN** the picker dropdown lists exactly those three keys
- **AND** selecting one writes the chosen key to `pages[].component`
- **AND** the free-form JSON editor opens with an empty `config: {}`

#### Scenario: Free-text fallback when preview is unavailable

- **WHEN** the live-preview pane is unavailable (in-memory manifest
  loader from chain spec #2 not detected)
- **THEN** the picker renders as a free-text input
- **AND** an i18n warning explains the validation-deferral
- **AND** the value writes through to `pages[].component` unchanged
