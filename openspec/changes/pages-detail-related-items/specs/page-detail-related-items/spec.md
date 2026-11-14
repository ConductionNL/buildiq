# Spec: page-detail-related-items

## Purpose

A maker shows a record's related records and files on a detail page from the page designer, without writing manifest JSON. Buildiq authors the keys the nextcloud-vue detail page already renders.

## ADDED Requirements

### Requirement: A detail page lists related records (REQ-BQRI-001)

The detail page editor SHALL offer a Related items section in which a maker adds related lists. Each list SHALL take a title, a register and schema, and the field on that schema that points at the open record, and MAY take columns and a row limit. The editor SHALL save each list as an entry of `config.relatedCollections` whose `filter` maps the chosen field to `@objectId`.

#### Scenario: A maker adds the requests of a client

- **GIVEN** a maker editing the `Client` detail page of an app whose `requests` schema has a `client` field
- **WHEN** they add a related list titled "Requests", pick schema `requests` and link field `client`, and save
- **THEN** the page's `relatedCollections` holds `{ title: "Requests", schema: "requests", filter: { client: "@objectId" } }` with the app's register

#### Scenario: An app user sees only this client's requests

- **GIVEN** the published app with that list and two clients who each have requests
- **WHEN** an app user opens the first client
- **THEN** the page shows a "Requests" section listing only that client's requests

### Requirement: A detail page offers link buttons (REQ-BQRI-002)

The Related items section SHALL let a maker add link buttons, saved as entries of `config.relationLinks` with a label, a register and schema, the field on the open record that stores the link, and optionally the field shown in the picker and whether a new record may be created.

#### Scenario: An app user links a contact to a client

- **GIVEN** the published `Client` page with a link button "Link contact" on schema `contacts` storing into the client's `primaryContact` field
- **WHEN** an app user chooses "Link contact" and picks "Ann de Vries"
- **THEN** the client's `primaryContact` holds Ann's id

### Requirement: A maker decides whether the files tab shows (REQ-BQRI-003)

The Sidebar section SHALL offer a Files choice, on by default. Switching it off SHALL add `files` to `config.sidebar.hiddenTabs`, converting a boolean sidebar to the object form, and switching it on SHALL remove it.

#### Scenario: Files are hidden on a detail page

- **GIVEN** a detail page whose sidebar is enabled in the boolean form
- **WHEN** the maker switches Files off and saves
- **THEN** the page's `sidebar` is `{ enabled: true, hiddenTabs: ["files"] }`
- **AND** an app user opening a record sees no files tab in the sidebar
