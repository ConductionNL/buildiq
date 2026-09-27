# Spec: page-codes

## Purpose

An app user scans a QR code or barcode with a phone to open or find a record, or
to fill a form field, and a detail page shows a code for its record. The camera
is allowed only on apps that scan.

## ADDED Requirements

### Requirement: Index pages can scan to find a record (REQ-BQQR-001)

The index page editor SHALL offer a "Scan" section with a switch, the schema
field that holds the code, and what a match does (`open` or `filter`). The page
SHALL open the one matching record, filter to several, or say "No record has
this code."

#### Scenario: A technician opens a device by its label

- **GIVEN** an index page `Apparaten` with scan on, field `serienummer`, and match action open
- **WHEN** a technician taps "Scan" on a phone and points it at the label `SN-40211`
- **THEN** the detail page of the device with `serienummer` `SN-40211` opens

#### Scenario: No match is said plainly

- **GIVEN** the same page
- **WHEN** the scanned code matches no record
- **THEN** the page says "No record has this code." and keeps the list as it was

### Requirement: Forms can fill a field from a scan (REQ-BQQR-002)

The form field builder SHALL offer a `scan` field type with the accepted formats,
filling a string field, and SHALL keep typing possible. Validation SHALL refuse a
`scan` field on a form in `public` mode.

#### Scenario: A clerk registers a parcel

- **GIVEN** a form `Pakket ontvangen` with a scan field `trackingcode`
- **WHEN** the clerk scans the parcel's barcode
- **THEN** `trackingcode` holds the scanned value and the form waits for Submit

### Requirement: Detail pages can show a code (REQ-BQQR-003)

The detail page editor SHALL offer a `code` widget with format QR, Code 128 or
EAN-13 and a source: a field of the record or the record's address in the app.
Validation SHALL refuse a Code 128 or EAN-13 widget whose source is the address,
and an EAN-13 source field that is not digits.

#### Scenario: A maker puts a QR code on the asset page

- **GIVEN** a maker editing the detail page of `apparaat`
- **WHEN** they add a code widget with format QR and source address, and save
- **THEN** the detail page shows a QR code that opens that device's page in the app, with "Download as SVG"

### Requirement: The camera is allowed only where scanning is used (REQ-BQQR-004)

Saving a manifest SHALL add `camera` to the version's device features when an
index page scans or a form has a scan field, and the built app pages SHALL allow
the camera for the page itself only in that case.

#### Scenario: An app without scanning keeps the camera closed

- **GIVEN** an app with a code widget and no scan action or scan field
- **WHEN** an app user opens it
- **THEN** the page policy still refuses the camera
