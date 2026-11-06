# buildiq-walkthrough-editor Specification

**Status:** proposed
**Scope:** buildiq
**Tier:** V1
**Depends on:** `cn-walkthrough-engine` (manifest `walkthrough` schema + `data-walkthrough-id`); ADR-041 (universal in-app editing); `layered-versioned-app-deltas`; ADR-043.

## Purpose

Let an Buildiq app owner author and edit a virtual app's product walkthrough
visually — by pointing at real UI — and persist it into the app's versioned
manifest delta, with no code or hand-written JSON.

## ADDED Requirements

### Requirement: REQ-WALK-OB-001 — Visual Tour Recorder In The Edit Shell

Buildiq SHALL add an **Edit walkthrough** mode to its in-app edit shell. In that
mode the owner SHALL be able to click any element in the running app to create a
walkthrough step, and Buildiq SHALL resolve the clicked element to the most stable
`target` available — preferring a manifest identity (`nav-item` route, `widget`
widgetKey, `action` id, `page` route), then an injected `data-walkthrough-id`
(`element`), and only as a last resort a CSS `selector` (flagged as brittle).

#### Scenario: Clicking a menu item records a stable nav-item target

- **GIVEN** the owner is in Edit walkthrough mode
- **WHEN** they click the Products menu item
- **THEN** a step SHALL be created with `target: { kind: "nav-item", ref: "products-index" }`, not a CSS selector

### Requirement: REQ-WALK-OB-002 — Advance Conditions Are Recorded From Real Actions

While recording a step, Buildiq SHALL observe vue-router and the OpenRegister
object store and suggest the step's `advanceOn` from the owner's real action: a
navigation SHALL suggest `route-match` with an `:id` `capture` when the route has an
id param; creating an object SHALL suggest `object-created` with that
register/schema. The owner SHALL be able to confirm or edit the suggestion.

#### Scenario: Recording a create-product step suggests object-created

- **GIVEN** the owner is recording a step and creates a product
- **WHEN** the new product is saved
- **THEN** Buildiq SHALL suggest `advanceOn: { type: "object-created", register: ..., schema: "product" }` with an id `capture`

### Requirement: REQ-WALK-OB-003 — Steps Are Editable, Orderable, And Versioned

The editor SHALL let the owner set each step's title, body, optional task,
`placement`, `advanceOn`, `optional`/`allowManualNext`, and `sinceVersion`
(defaulting to the app's current `ApplicationVersion`); add/rename/delete tours; and
drag-reorder steps.

#### Scenario: A step defaults its sinceVersion to the current app version

- **GIVEN** the app's current version is `1.3.0`
- **WHEN** the owner records a new step
- **THEN** its `sinceVersion` SHALL default to `1.3.0`

### Requirement: REQ-WALK-OB-004 — Authored Tours Persist Into The Versioned Manifest Delta

On save, the editor SHALL merge the authored `walkthrough` block into the app's
manifest delta via the existing Buildiq persistence path, tagged to the chosen
`ApplicationVersion`, and SHALL validate the block against the canonical v2 schema
before persisting — an invalid block SHALL NOT be saved.

#### Scenario: Invalid tour is blocked

- **GIVEN** an authored step with an unknown `advanceOn.type`
- **WHEN** the owner saves
- **THEN** the save SHALL be rejected with a validation error and nothing persisted

#### Scenario: Saved tour is assignable to a version

- **GIVEN** a valid tour authored against version `1.3.0`
- **WHEN** the owner saves
- **THEN** the `walkthrough` block SHALL be written to the manifest delta tagged to `1.3.0`, so an upgrade surfaces only its steps

### Requirement: REQ-WALK-OB-005 — The Editor Also Edits The Setup Block

The same editor panel SHALL edit a virtual app's `manifest.setup` steps
(`info` | `choice` | `config-fields` | `run-action` | `summary`), writing to the
`setup` block of the same manifest delta — closing the gap that setup is
manifest-configurable but had no visual editor.

#### Scenario: Editing a setup choice step

- **GIVEN** a virtual app with a `setup` block
- **WHEN** the owner edits a `choice` step's options in the panel and saves
- **THEN** the updated `setup` block SHALL persist into the app's manifest delta
