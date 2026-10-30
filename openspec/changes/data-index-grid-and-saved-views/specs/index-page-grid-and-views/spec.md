# Spec: index-page-grid-and-views

## Purpose

An app user edits many records in a grid without opening each one, and finds
the records they need through views: their own, or ones the maker set up for
everyone. Buildiq authors the grid page and the views; nextcloud-vue renders the
grid cells and the views control.

## ADDED Requirements

### Requirement: A maker can build a grid page (REQ-BQIX-001)

The page designer SHALL offer a "Grid" page for a register and schema, stored as
a `custom` page with component `record-grid`, where the maker picks the columns
and marks which ones can be edited. The published app SHALL render it as a grid
of the schema's records.

#### Scenario: A maker adds a grid of stock items

- **GIVEN** a maker editing an app with a `StockItem` schema
- **WHEN** they add a "Grid" page for `StockItem`, pick `name`, `quantity` and `location`, mark `quantity` editable and publish
- **THEN** the published app shows a page with one row per stock item and the `quantity` cells can be edited in place

### Requirement: A cell edit is saved to its record, or refused visibly (REQ-BQIX-002)

When an app user commits a cell, the grid SHALL save that one property on that
record. A refused save SHALL restore the old value and show the reason on the
cell. A row locked by a colleague SHALL be read-only and name the colleague.

#### Scenario: An app user corrects a quantity

- **GIVEN** an app user on the stock grid
- **WHEN** they change `quantity` of "Printer paper" from 12 to 10 and press Enter
- **THEN** the record is saved with quantity 10 and a reload of the page shows 10

#### Scenario: A colleague is editing the same record

- **GIVEN** a colleague holds the lock on "Printer paper"
- **WHEN** an app user tries to edit that row
- **THEN** the row cannot be edited and the grid says the colleague is editing it

### Requirement: A maker switches on saved views and defines views for everyone (REQ-BQIX-003)

The index page editor SHALL offer a switch that lets app users save their own
views, and a list where the maker defines named views (filters and sort) that
every app user of that page sees.

#### Scenario: Every app user gets the view "Open requests"

- **GIVEN** a maker editing the `Requests` index page
- **WHEN** they switch on saved views, add a view "Open requests" filtered on status open and sorted by date, and publish
- **THEN** an app user opening `Requests` finds "Open requests" in the views list and applying it shows only open requests, newest first
