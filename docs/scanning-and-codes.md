# Scanning and showing codes

An app you build can read QR codes and barcodes with the device camera, and
show a record's code so it can be printed or scanned. Scanning finds a record
or fills a form field; a code widget shows a value as a code on a record's
page.

## Find a record by scanning its code

1. Open the app in the page designer and open an index page.
2. In the **Scan** section, tick **Find a record by scanning its code**.
3. Under **Field that holds the code**, pick the field whose value is printed
   on the label.
4. Under **When a code is scanned**, choose **Open the matching record** or
   **Show the matching records**.
5. Publish the app.

The section is stored as `config.scan` on the index page, with `field` and
`onMatch` (`open` or `filter`).

## Fill a form field by scanning

1. In the form builder, add a field and set its type to **Scanned code**
   (`scan`).
2. Tick the code formats the field accepts: QR code, Code 128 and EAN-13. A
   new scan field accepts all three.

The field is stored with `type: "scan"` and `formats`, the list of formats it
accepts.

## Show a record's code

On a detail page, add a **code** widget. Pick the format (QR code, Code 128
or EAN-13) and what the code holds: a field of the record (`source: "field"`
with `field`), or the address of the record's page (`source: "address"`),
which only a QR code can hold. The page shows it as a code.

## The camera is allowed only where it is used

When an app version is saved, Buildiq records the device features its pages
use on the version, as `deviceFeatures`. `camera` is recorded when an index
page scans or a form has a scan field. The pages of the built app then allow
the camera for the app's own page, and for nothing else. An app that only
shows codes uses no camera, so the browser keeps the camera closed.

## What is checked

The manifest check refuses, at the setting it belongs to:

- a scan section without a field to match, with a field the schema does not
  have, or with another choice than open or show;
- a scan field on a public form, and a scan field without a code format or
  with an unknown one;
- a code widget with an unknown format or source, the record's address as a
  Code 128 or EAN-13 code, a field source without a field or with a field the
  schema does not have, and an EAN-13 code from a field that does not hold
  digits.
