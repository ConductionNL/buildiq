## ADDED Requirements

### Requirement: Manifest-carrying schema declares optional baseRef and manifestDelta

The system SHALL declare two optional top-level properties on the manifest-carrying
schema (`ApplicationVersion` per ADR-002, where the `manifest` blob lives) in
`lib/Settings/openbuild_register.json`: `baseRef` and `manifestDelta`. `baseRef` SHALL
be a structured reference identifying what the design extends — an OpenBuilt template,
another OpenBuilt app, or a fleet app's bundled manifest — with an optional pin to a
specific base version. `manifestDelta` SHALL be an object carrying the keyed structural
delta (page entries keyed by `page.id`, widget entries keyed by `widget.id`, the
`{ "$op": "remove" }` deletion marker, and the optional `__order` reorder key) as
defined by the `@conduction/nextcloud-vue` `manifest-delta-merge-and-flex-columns`
contract. Both properties SHALL be optional and additive — an object that omits both is
valid, and the existing `manifest` blob property SHALL remain on the schema for legacy
and standalone apps.

#### Scenario: Object with baseRef and manifestDelta is accepted

- **WHEN** a client saves a manifest-carrying object with a `baseRef` and a
  `manifestDelta` (and no whole-manifest blob)
- **THEN** OR persists the object and returns 2xx with no validation error

#### Scenario: Object without delta fields is still accepted

- **WHEN** a client saves a manifest-carrying object that omits both `baseRef` and
  `manifestDelta` and carries only a `manifest` blob
- **THEN** OR persists the object and returns 2xx — the new fields are optional and the
  blob remains the resolution source

#### Scenario: Resolved manifest validates against the canonical schema

- **WHEN** a `baseRef` + `manifestDelta` object is resolved into a merged manifest
- **THEN** the merged manifest SHALL validate against the canonical app-manifest schema
  at `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`

### Requirement: Delta storage replaces the whole-manifest blob for delta-mode apps

For an app that extends a base, the manifest-carrying object SHALL store the
`baseRef` + `manifestDelta` pair instead of a frozen whole-manifest blob. The stored
`manifestDelta` SHALL be the minimal keyed diff between the resolved base and the edited
manifest (the `diffManifest` output), so the stored object does not duplicate base
content that is unchanged.

#### Scenario: Editing one page stores a minimal delta, not a full blob

- **WHEN** a delta-mode app changes one page and the change is persisted
- **THEN** the stored `manifestDelta` SHALL contain only the changed entry keyed by its
  id
- **AND** the object SHALL NOT store a whole-manifest blob duplicating the unchanged base
  pages
