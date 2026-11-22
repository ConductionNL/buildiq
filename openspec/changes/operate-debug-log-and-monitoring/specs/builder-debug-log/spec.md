# Spec: builder-debug-log

## Purpose

A maker sees why a page in the live preview shows nothing or the wrong thing. The
page designer keeps a log of the preview's requests, errors and binding problems,
per page, in the maker's own browser.

## ADDED Requirements

### Requirement: The designer logs the preview's requests and errors (REQ-BQDM-001)

The page designer SHALL show an "Error log" under the live preview (board
BqPaginaOntwerperFouten). It SHALL list each request of the preview that failed
(a 4xx or 5xx status, or no answer) and each render error and unknown
component, each with its page, the time it last happened and how often; the
same problem SHALL be one entry with a count. The log SHALL keep the last 200
entries and leave out the designer's own requests to buildiq's API.

#### Scenario: A maker sees a failed list request

- **GIVEN** a maker in the page designer of `vergunningen`, previewing the index page `permits`
- **WHEN** the preview's list request for `permits` answers 403
- **THEN** the Debug panel shows an entry for page `permits` with the request method and path, status 403 and its duration, and the "Errors" filter keeps it

#### Scenario: The log can be filtered, reloaded and cleared

- **GIVEN** a maker in the page designer whose Error log holds a failed request, a broken binding and a render error
- **WHEN** they open the log's filter
- **THEN** it offers everything, failed requests, broken bindings and render errors, each keeping only its own entries
- **AND** a "Reload preview" button reloads the preview and a "Clear" button empties the log

#### Scenario: A repeated problem is one entry

- **GIVEN** a preview whose list request for `permits` fails three times
- **WHEN** the maker opens the Error log
- **THEN** it shows one entry for that request with a count of 3 and the time of the last failure

#### Scenario: The designer's own requests are left out

- **GIVEN** a maker in the page designer whose save to buildiq's own API fails
- **WHEN** they open the Error log
- **THEN** the log has no entry for that request

### Requirement: The log names binding problems (REQ-BQDM-002)

The error log SHALL list, as binding entries, a column or field that points at a
property the schema does not have, and a register or schema the preview cannot
find. An entry SHALL offer to open its page in the designer.

#### Scenario: A column points at a property that is gone

- **GIVEN** the index page `permits` with a column `kvkNumber`, and a schema `permit` without that property
- **WHEN** the maker opens the preview
- **THEN** the error log shows a binding entry that `kvkNumber` is not a field of schema `permit`, and its "Go to the page" selects the page `permits`

### Requirement: The log stays in the maker's browser (REQ-BQDM-003)

The debug log SHALL NOT be sent to or stored on the server, SHALL NOT record
request or response bodies, and SHALL be empty when the designer is opened again.

#### Scenario: A reload starts a clean log

- **GIVEN** an error log with twelve entries
- **WHEN** the maker reloads the page designer
- **THEN** the log is empty and no request carrying log entries was made
