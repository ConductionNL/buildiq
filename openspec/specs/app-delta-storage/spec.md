# app-delta-storage Specification

## Purpose

A built app's version can extend a base (a template, another buildiq app, a fleet
app's bundled manifest, or one version) and store only its difference from that
base. The manifest endpoint merges the two server-side, so the app inherits later
changes to its base, while an app that stores a whole manifest is served as before.

**OpenSpec changes**: [app-delta-override](../../changes/archive/2026-11-25-app-delta-override/) _(archived 2026-11-25)_

## Requirements

### Requirement: Application version stores baseRef and manifestDelta

The manifest-carrying schema (`ApplicationVersion` per ADR-002) SHALL support
two optional, additive top-level properties: `baseRef`, a structured reference
naming what the design extends, and `manifestDelta`, the keyed structural delta
(the `diffManifest` output) layered over the resolved base. An
`ApplicationVersion` MAY carry neither, only `manifestDelta` with a `baseRef`, or
the legacy `manifest` blob with no `baseRef`.

#### Scenario: Delta-mode version persists baseRef and minimal delta

- **WHEN** a built app extends a base and a single page is edited
- **THEN** its `ApplicationVersion` SHALL store a `baseRef` naming the base
- **AND** a `manifestDelta` containing only the changed page keyed by its `page.id`
- **AND** SHALL NOT store a frozen whole-manifest blob for that version

#### Scenario: Both delta fields are optional

- **WHEN** an `ApplicationVersion` is created with neither `baseRef` nor `manifestDelta`
- **THEN** the schema SHALL accept it as valid
- **AND** the existing `manifest` blob property SHALL remain the resolution source

#### Scenario: The stored pair has the shape resolution reads

- **WHEN** a delta-mode `ApplicationVersion` stores a `baseRef` and a `manifestDelta`
- **THEN** the `baseRef` SHALL name an OpenBuilt template, another OpenBuilt app, or a fleet app's bundled manifest
- **AND** the `manifestDelta` SHALL be a keyed delta consumable by `mergeManifestDelta`: page entries keyed by `page.id`, widget entries keyed by `widget.id`, `{ "$op": "remove" }` deletions and an optional `__order`
- **AND** that shape SHALL be the one the `@conduction/nextcloud-vue` `manifest-delta-merge-and-flex-columns` contract defines

### Requirement: Manifest endpoint resolves base plus delta server-side

The manifest resolution path (`ManifestResolverService`) SHALL, when an
`ApplicationVersion` carries a non-null `baseRef`, resolve `baseRef` to its base
manifest, apply the `manifestDelta` over that base via a server-side keyed merge
equivalent to the canonical JS `mergeManifestDelta`, and return the fully merged
manifest to the caller. No consumer SHALL be required to perform a merge itself.

#### Scenario: Base plus delta resolves to a merged manifest

- **WHEN** an authenticated, authorised caller requests the manifest for a built app
  whose `ApplicationVersion` has a `baseRef` and a `manifestDelta`
- **THEN** the endpoint SHALL resolve the base manifest, apply the delta over it,
  and respond `200 application/json` with the merged manifest
- **AND** the response body SHALL NOT contain the raw `baseRef` or `manifestDelta`

#### Scenario: Server merge matches the JS contract

- **WHEN** the server resolves a `base` and `delta` pair
- **THEN** the resulting manifest SHALL equal the output of the canonical JS
  `mergeManifestDelta(base, delta)` for the same inputs (page/widget keyed merge,
  `$op:"remove"` deletion, `__order` reordering, plain-object recursion)
- **AND** the two outputs SHALL be bit-identical

#### Scenario: Every consumer receives a complete manifest

- **WHEN** the runtime `CnAppRoot`, the export pipeline, Newman or any other HTTP caller reads the manifest of a delta-mode app
- **THEN** it SHALL receive the complete, already-merged manifest and SHALL NOT perform a merge itself
- **AND** that manifest SHALL validate against `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`

### Requirement: Built apps inherit base-app upgrades

A built app with a `baseRef` SHALL reflect later changes to its base manifest,
because resolution re-reads the live base on each request (new pages, widgets, or
fixes added upstream) without requiring the built app to be re-saved, except where
the `baseRef` explicitly pins a base version. A `baseRef` MAY pin a specific base
version; when pinned, resolution SHALL read that pinned version rather than the
live base.

#### Scenario: Base adds a page after the built app was saved

- **WHEN** a base app gains a new page after a derived app saved its `baseRef` + delta
- **AND** the derived app's `baseRef` does not pin a base version
- **THEN** a subsequent manifest request for the derived app SHALL include the new
  base page in the merged manifest

#### Scenario: Pinned baseRef does not auto-upgrade

- **WHEN** a derived app's `baseRef` pins a specific base version
- **AND** the base later changes
- **THEN** the merged manifest SHALL reflect the pinned base version, not the live base

### Requirement: Legacy whole-manifest blob apps still resolve unchanged

An `ApplicationVersion` with a populated `manifest` blob and no `baseRef` SHALL be
treated as `baseRef = null` — the stored blob IS the manifest and SHALL be returned
unchanged. No data migration SHALL be required for existing apps; resolution SHALL
serve their blob byte-for-byte as it did before this change.

#### Scenario: Pre-existing blob app serves verbatim

- **WHEN** an authenticated, authorised caller requests the manifest for an app
  created before this change (blob present, no `baseRef`)
- **THEN** the endpoint SHALL return the stored `manifest` blob unchanged
- **AND** SHALL NOT attempt any base resolution or delta merge

### Requirement: Editor save persists a minimal delta

The Buildiq manifest editor SHALL, on save for a delta-mode app, compute the
minimal keyed delta between the resolved base and the edited manifest with the
canonical JS `diffManifest(base, edited)`, and SHALL persist `{ baseRef,
manifestDelta }` to the `ApplicationVersion` rather than a whole-manifest blob. The
editor SHALL preview the app with the canonical JS `mergeManifestDelta(base,
delta)`, so the preview equals what the server later resolves.

#### Scenario: Editing one field stores only the delta

- **WHEN** a developer changes one field of one page in a delta-mode app and saves
- **THEN** the persisted `manifestDelta` SHALL contain only the changed entry keyed
  by its id, not the whole `pages[]` array
- **AND** the editor preview SHALL render the merged base + delta result

#### Scenario: Preview equals served result

- **WHEN** the editor previews a delta-mode app via `mergeManifestDelta`
- **THEN** the previewed manifest SHALL equal the manifest the server resolves for
  the same `baseRef` and `manifestDelta`

### Requirement: Orphaned delta paths are non-fatal and surfaced

A `manifestDelta` patch whose key matches no base entry SHALL be skipped (base drift), resolution SHALL NOT fail, and a usable merged manifest SHALL still be served. The skipped (orphaned) paths SHALL be collected and made
observable to the editor and to an admin surface, parallel to the upstream
`orphanedDeltaPaths` contract. Orphaned-path diagnostics SHALL NOT be included in
the public manifest response — only the merged manifest is returned.

#### Scenario: Base deletes a page a delta patched

- **WHEN** a base removes a page that a derived app's `manifestDelta` patches
- **THEN** resolution SHALL skip the orphaned patch and still return a usable merged
  manifest
- **AND** the orphaned path SHALL be surfaced to the editor / admin surface

#### Scenario: Diagnostics omitted from public response

- **WHEN** the public manifest endpoint returns a merged manifest that had orphaned
  delta paths
- **THEN** the response body SHALL contain only the merged manifest
- **AND** SHALL NOT contain the orphaned-path diagnostics
