# Spec: builder-debug-log

## Purpose

A maker sees why a page in the live preview shows nothing or the wrong thing. The
page designer keeps a log of the preview's requests, errors and binding problems,
per page, in the maker's own browser.

## ADDED Requirements

### Requirement: The designer logs the preview's requests and errors (REQ-BQDM-001)

The page designer SHALL show a "Debug" panel under the live preview. It SHALL
list each request the preview makes, with method, path, status, duration and the
number of rows returned, and each error the preview raises, each with the time
and the page it came from. The panel SHALL offer an "Errors" filter and a "Clear"
button, and SHALL keep the last 200 entries.

#### Scenario: A maker sees a failed list request

- **GIVEN** a maker in the page designer of `vergunningen`, previewing the index page `permits`
- **WHEN** the preview's list request for `permits` answers 403
- **THEN** the Debug panel shows an entry for page `permits` with the request path, status 403 and its duration, and the "Errors" filter keeps it

### Requirement: The log names binding problems (REQ-BQDM-002)

The Debug panel SHALL list, as binding entries, a column or field that points at a
property the schema does not have, and a register or schema the preview cannot
find, together with the problems the designer's own validation marks for the
previewed page. Clicking an entry SHALL select its page in the designer.

#### Scenario: A column points at a property that is gone

- **GIVEN** the index page `permits` with a column `kvkNumber`, and a schema `permit` without that property
- **WHEN** the maker opens the preview
- **THEN** the Debug panel shows a binding entry that the column `kvkNumber` has no matching property on `permit`, and clicking it selects the page `permits`

### Requirement: The log stays in the maker's browser (REQ-BQDM-003)

The debug log SHALL NOT be sent to or stored on the server, SHALL NOT record
request or response bodies, and SHALL be empty when the designer is opened again.

#### Scenario: A reload starts a clean log

- **GIVEN** a Debug panel with twelve entries
- **WHEN** the maker reloads the page designer
- **THEN** the panel is empty and no request carrying log entries was made
