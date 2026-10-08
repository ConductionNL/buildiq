# Spec: page-chart-authoring

## Purpose

A maker adds a chart to a dashboard page in the page designer and binds it to records, without writing manifest JSON. Buildiq mounts the chart form the nextcloud-vue renderer already ships.

## ADDED Requirements

### Requirement: A chart widget is configured with the library's chart form (REQ-BQCW-001)

The widget list of the dashboard page editor SHALL show nextcloud-vue's chart form for every widget of type `chart`, offering the chart type, the breakdown, the register and schema, the date or category field, the interval and the metric.

#### Scenario: A maker configures a bar chart by category

- **GIVEN** a maker editing the `Overview` dashboard page of an app with a `requests` schema that has a `status` field
- **WHEN** they add a chart, pick chart type "Bar", breakdown "By category", schema `requests` and field `status`
- **THEN** the widget row shows those choices
- **AND** the page's preview shows one bar per request status

### Requirement: The chart is saved in the shape the manifest schema allows (REQ-BQCW-002)

The editor SHALL save a chart's type as `props.chartKind` and its data binding as the widget's `dataSource`, and SHALL NOT write a `content` key into a widget definition. Reopening the page SHALL pre-fill the chart form from those keys.

#### Scenario: The saved chart validates and draws after publishing

- **GIVEN** the chart from the previous scenario
- **WHEN** the maker saves and publishes the app
- **THEN** the saved widget validates against the manifest's `widgetDef`
- **AND** an app user opening `Overview` sees the bar chart of requests by status

#### Scenario: A saved chart opens with its choices filled in

- **GIVEN** a saved chart widget with `props.chartKind` `pie` and a `dataSource` grouped by `status`
- **WHEN** a maker opens the dashboard page editor again
- **THEN** the chart form shows "Pie", "By category" and `status`

### Requirement: A new chart is placed on the page (REQ-BQCW-003)

"Add chart" SHALL add a chart widget with a unique id and SHALL add a layout item for it when no layout item references that id.

#### Scenario: A new chart appears without a separate layout step

- **GIVEN** a dashboard page with two widgets placed in its layout
- **WHEN** a maker chooses "Add chart" and configures it
- **THEN** the layout holds a third item for the new chart
- **AND** the preview shows the chart below the other two widgets
