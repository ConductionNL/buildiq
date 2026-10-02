# Spec: connector-bound-page-runtime

## Purpose

A page a maker binds to an integriq endpoint shows that endpoint's records in
the published app, projected through the fields the maker mapped. Extends
`openconnector-api-sources` (REQ-OCAS-001 to REQ-OCAS-006), which specifies the
binding, the mapping and the fetch but no page that renders them.

## ADDED Requirements

### Requirement: A connector-bound index page renders its records (REQ-BQCB-001)

When a published app's manifest has an `index` page with
`config.dataSource.connector`, the runtime SHALL render that page as a list of
the endpoint's items, projected through `itemsPath` and `fields`. The rewrite
SHALL happen in memory at load; the stored manifest SHALL keep the page as the
maker authored it.

#### Scenario: An app user opens a page bound to an external API

- **GIVEN** a maker published an app whose `Suppliers` index page is bound to the integriq endpoint `suppliers` with fields `name` and `city`
- **WHEN** an app user opens `/apps/buildiq/builder/{slug}` and goes to `Suppliers`
- **THEN** the page shows a list with the columns `name` and `city` and one row per item the endpoint returned

#### Scenario: The page designer still shows what the maker authored

- **GIVEN** the same published app
- **WHEN** the maker opens the `Suppliers` page in the page designer
- **THEN** the page is still an index page with its data source set to OpenConnector and its field mapping intact

### Requirement: The list page shows every state of the fetch (REQ-BQCB-002)

The connector list page SHALL show a loading state, an error state with a Retry
action, a stale notice when it serves a cached response after a failed refresh,
and an empty state when the endpoint returns no items. It SHALL offer a search
box that filters the loaded rows on the mapped fields.

#### Scenario: The endpoint is down

- **GIVEN** an app user on a connector-bound page whose endpoint answers with an error
- **WHEN** the page loads
- **THEN** the page says the data could not be loaded and offers Retry, and no blank page or unknown widget message appears

### Requirement: A maker can place a connector widget on a dashboard (REQ-BQCB-003)

The dashboard widget picker SHALL offer the `connector-data` widget. Placing it
SHALL open the data source origin toggle, the source picker and the field
mapper for that widget, and the published dashboard SHALL render its rows.

#### Scenario: A maker adds an external list to a dashboard

- **GIVEN** a maker editing a dashboard page in the page designer
- **WHEN** they add the connector widget, pick the endpoint `open-requests`, map two fields and publish
- **THEN** the published dashboard shows that widget with the two mapped columns

### Requirement: Preview and published app render the same pages (REQ-BQCB-004)

The preview host and the published runtime SHALL apply the same connector page
normalisation and the same runtime registry, so a page that renders in the
preview renders in the published app.

#### Scenario: The preview does not promise more than the published app

- **GIVEN** a maker previews an app with a connector-bound index page in the builder
- **WHEN** they publish it and open the published app
- **THEN** the page renders the same rows in both, and a unit guard fails if either host skips the normaliser
