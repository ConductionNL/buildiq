## ADDED Requirements

### Requirement: Manifest widget shows the delta layers and per-layer version state

The system SHALL replace the structural Schemas / Pages / Menu widgets on the
app-detail dashboard (`src/components/applicationDetail/ApplicationDetailDashboard.vue`
+ `widgets/`) with a **Manifest widget** that shows the manifest customization
LAYERS and their version state, NOT raw manifest JSON. The widget SHALL render:

- **Base** — labelled read-only (the installed app's / virtual app's base
  manifest).
- **Admin delta** — the shared delta: its current version and version count.
- **Your delta** — the caller's user delta: its current version and version count
  when one exists, OR a "create override" affordance when
  `Application.allowUserOverrides` is `true` and the caller has no user delta. When
  `allowUserOverrides` is `false`, the "Your delta" row SHALL be hidden or shown
  as unavailable.

Clicking the widget (or a layer row) SHALL open the Manifest detail page
(REQ-ADLU-003) for the selected layer. User-facing strings SHALL be provided in
Dutch and English via `t()` (ADR-007).

@e2e exclude component-contract — the layer rows, counts, and create-override affordance are Vitest component contracts; end-to-end navigation to the detail page is covered by the dashboard Playwright validation task

**ID:** REQ-ADLU-001

#### Scenario: Widget shows admin and user layers

- **WHEN** a caller opens the app detail of an app with `allowUserOverrides: true`,
  an admin delta, and their own user delta
- **THEN** the Manifest widget shows Base (read-only), the admin delta's current
  version + count, and the caller's user delta's current version + count

#### Scenario: Create-override affordance when none exists

- **WHEN** `allowUserOverrides` is `true` and the caller has no user delta
- **THEN** the "Your delta" row shows a "create override" affordance instead of a
  version state

#### Scenario: User layer hidden when overrides disabled

- **WHEN** `allowUserOverrides` is `false`
- **THEN** the Manifest widget does not offer a create-override affordance

### Requirement: Register widget deep-links to OpenRegister with current counts

The system SHALL render a **Register widget** on the app-detail dashboard showing
the app's OpenRegister register(s) and current object counts, with an "Open in
OpenRegister" deep-link into the OpenRegister app for any register-data version /
rollback / time-travel. The widget SHALL NOT model a register-delta and SHALL NOT
reimplement register versioning in OpenBuild — it is a read + deep-link surface
only, consistent with the existing `RegisterWidget.vue` pattern.

@e2e exclude deep-link contract — the deep-link target and count formatting are Vitest component contracts; OpenRegister-side version/rollback is OR's own verified surface

**ID:** REQ-ADLU-002

#### Scenario: Register widget shows counts and a deep-link

- **WHEN** a caller opens the app detail of an app with a register
- **THEN** the Register widget shows the register name and current object count
- **AND** an "Open in OpenRegister" deep-link navigates to that register in
  OpenRegister

### Requirement: Manifest detail page shows per-layer version history with rollback

The system SHALL add a new routed **Manifest detail page** that shows ALL VERSIONS
of a selected delta (the admin delta, or the caller's own user delta) using
OpenRegister's version history, and supports rollback / time-travel. The page
SHALL reuse the existing `src/views/VersionHistory.vue`,
`src/components/tabs/ApplicationVersionsTab.vue`, and
`src/modals/RollbackConfirmModal.vue` surfaces, pointed at the selected delta
row's uuid. The route SHALL be registered with a manifest page-registry entry and
SHALL NOT register an admin-only Vue component in the in-app vue-router
(ADR-004). A caller SHALL only be able to select the admin delta or a user delta
they own; selecting another user's delta SHALL be impossible from the UI and
denied server-side.

@e2e exclude reuse-of-existing-surfaces — version-history + rollback are existing verified Vue surfaces re-pointed at a delta uuid; the no-foreign-delta rule is enforced and tested in the layered-app-deltas backend spec

**ID:** REQ-ADLU-003

#### Scenario: Open version history for the admin delta

- **WHEN** a caller opens the Manifest detail page for the admin delta
- **THEN** the page lists the admin delta's OR versions and offers rollback

#### Scenario: Open version history for the caller's user delta

- **WHEN** a caller who owns a user delta opens the Manifest detail page for it
- **THEN** the page lists that user delta's OR versions and offers rollback

#### Scenario: Rolling back from the detail page

- **WHEN** the caller confirms a rollback in `RollbackConfirmModal`
- **THEN** the selected delta reverts to the chosen OR version via OR's rollback
  path

### Requirement: Create / edit / rollback modals for a delta

The system SHALL provide modal flows for creating, editing, and rolling back a
delta, each in its own file under `src/modals/` (NcModal-based) or `src/dialogs/`
(NcDialog-based) per ADR-004 gate-13 modal isolation — no inline modal markup in
the dashboard or detail page. The create flow SHALL write a new
`scope: user` `ApplicationVersion` (`owner: <caller-uid>`, `manifestDelta: {}`,
`baseRef` → the admin delta version) when the caller has no user delta and
`allowUserOverrides` is `true`. NcSelect controls SHALL carry an `inputLabel`
(ADR-004). User-facing strings SHALL be Dutch + English via `t()`.

@e2e exclude modal-contract — modal open/close, create-payload shape, and label props are Vitest component contracts; the persisted user-delta row is verified by the layered-app-deltas PHPUnit suite

**ID:** REQ-ADLU-004

#### Scenario: Create override modal writes an empty user delta

- **WHEN** the caller confirms "create override" with `allowUserOverrides: true`
  and no existing user delta
- **THEN** a `scope: user` `ApplicationVersion` is created with the caller as
  `owner`, an empty `manifestDelta`, and `baseRef` pointing at the admin delta

#### Scenario: Modals are isolated files

- **WHEN** the dashboard or detail page renders its create/edit/rollback affordances
- **THEN** each modal/dialog lives in its own `src/modals/` or `src/dialogs/` file
  and is imported by the parent (no inline `<NcModal>`/`<NcDialog>` markup)
