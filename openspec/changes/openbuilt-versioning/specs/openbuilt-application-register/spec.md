## ADDED Requirements

### Requirement: REQ-OBA-006 Application schema carries a currentVersion reference

The `Application` schema declared in `lib/Settings/openbuilt_register.json` (REQ-OBA-001) SHALL be extended with a `currentVersion` property of type string with UUID-format. The property SHALL be optional (an Application that has never been published has no `currentVersion`). When populated, it SHALL hold the `uuid` of the most recent `ApplicationVersion` row for this Application (see capability `openbuilt-version-snapshots`, REQ-OBV-006). The schema change SHALL remain backward-compatible: existing Applications imported from spec #1 carry no `currentVersion` and SHALL continue to load, list, and edit without error.

#### Scenario: Existing Applications remain valid without currentVersion

- **WHEN** the OpenBuilt repair step runs an upgrade on an install
  that already has seeded Applications from spec #1
- **THEN** those Applications continue to load via OR REST
- **AND** their `currentVersion` field is absent or `null`
- **AND** the textarea editor renders them without validation
  errors

#### Scenario: currentVersion is updated atomically with the snapshot

- **WHEN** an Application transitions from `draft` to `published`
- **THEN** the same lifecycle action that creates the
  `ApplicationVersion` row also writes the new row's `uuid` into
  the Application's `currentVersion`
- **AND** both writes are observed by a subsequent OR REST GET of
  the Application

### Requirement: REQ-OBA-007 Draft-to-published transition declares a snapshot action

The `x-openregister-lifecycle` block on the `Application` schema (REQ-OBA-003) SHALL declare an `on_transition` action on the `draft → published` edge that creates a new `ApplicationVersion` row populated from the Application's current `manifest`, `version`, the actor's NC user id, and the transition timestamp; updates the Application's `currentVersion` to the new row's `uuid`; and sets the Application's `status` back to `draft` so that the next edit session continues from a draft state, while the just-created `ApplicationVersion` serves as the "published" record (see design.md Decision 3 for rationale).

If OR's lifecycle engine cannot yet express a sibling-object create action in `on_transition`, the action MAY be implemented as a single PHP listener subscribed to `ObjectLifecycleTransitionedEvent` per ADR-031 §Exceptions(1) — mirroring the OQ-1 escape hatch bootstrap-openbuilt established. The observed behaviour SHALL be identical in either case. The implementer SHALL NOT introduce a generic `VersioningService` / `SnapshotService` class.

#### Scenario: Declarative path emits the snapshot

- **WHEN** OR's engine supports `on_transition.create_relation` (or
  equivalent)
- **AND** an Application transitions from `draft` to `published`
- **THEN** a snapshot is created without any custom PHP listener
  being invoked
- **AND** the OR audit trail records both the transition and the
  snapshot create

#### Scenario: Listener fallback produces the same outcome

- **WHEN** OR's engine does not yet expose the sibling-create action
  and the fallback `ApplicationVersionSnapshotListener` is registered
- **AND** an Application transitions from `draft` to `published`
- **THEN** the listener creates the `ApplicationVersion` row,
  updates `currentVersion`, and resets the Application's `status`
  to `draft`
- **AND** the resulting Application + ApplicationVersion records
  are byte-equal (modulo `uuid` and timestamps) to the declarative
  path
