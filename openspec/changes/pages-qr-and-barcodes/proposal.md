---
kind: code
depends_on: [forms-signature-audio-and-location]
---

# Proposal: pages-qr-and-barcodes

## Why

**buildiq matrix, row `pg-scan-code`**, "Scan a QR code or barcode with a phone
to open or act on a record.", rated `no`, `built.state` `none`. Five competitors
rate it `yes`:

- NocoBase: "packages/plugins/@nocobase/plugin-block-workbench/src/client-v2/models/actions/ActionPanelScanActionModel.tsx:135
  "Scan QR code" action in the action panel block" (source read at v2.2.18).
- Budibase: "packages/client/manifest.json:5072 'codescanner' Barcode/QR Scanner
  form field (scan button, manual entry, auto confirm) with an onChange action at
  :5193 that can fetch or navigate to the matching row" (source read at v3.46.0).
- Appsmith: "app/client/src/widgets/index.ts:361 CODE_SCANNER_WIDGET reads QR
  codes and barcodes with the device camera, ... contentConfig.ts:141
  onCodeDetected runs an action such as navigating to or querying the record"
  (source read at v2.4.2).
- Mendix: "the platform-supported Barcode Scanner widget scans barcodes and QR
  codes with the device camera"
  (https://docs.mendix.com/appstore/widgets/barcode-scanner/).
- Microsoft Power Apps: "the barcode scanner control scans barcodes and QR codes
  with the device camera"
  (https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/controls/control-barcodescanner).

Today, from `built.evidence`: "Searched: grep -iE 'qr|barcode|camera' over src/
and nextcloud-vue v2.55.1 page components: no scan action; the hits in src/ are
the word scan in comments".

**buildiq matrix, row `pg-generate-code`**, "Generate a QR code or barcode for a
record on its page.", rated `no`, `built.state` `none`. Two competitors rate it
`yes`:

- Budibase: "packages/client/manifest.json:5247 'codegenerator' Barcode/QR
  Generator component" (source read at v3.46.0).
- Mendix: "the platform-supported Barcode Generator widget generates a barcode
  or QR code from a string value and shows it on the page"
  (https://docs.mendix.com/appstore/widgets/barcode-generator/).

Today, from `built.evidence`: "Searched: grep -iE 'qr|barcode' over src/ and the
nextcloud-vue v2.55.1 widget set offers no code generator widget".

## What changes

- Index pages get a "Scan" action: the app user scans a code with the phone
  camera, and the page opens the matching record, filters to the matches, or says
  there is none.
- Forms get a "Scan" field type that fills a field from a scanned code, with
  typing as a fallback.
- Detail pages get a "Code" widget that shows a QR code or a barcode for the
  record: the value of a field, or the record's address in the app.
- The camera is allowed only on apps that use a scan action or field, through the
  device feature list of `forms-signature-audio-and-location`.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | pg-scan-code | Scan a QR code or barcode with a phone to open or act on a record. | no | a scan action on index pages and a scan field in forms |
| buildiq | pg-generate-code | Generate a QR code or barcode for a record on its page. | no | a code widget on detail pages |

## Existing work it builds on

- Change `forms-signature-audio-and-location` (this pass): `deviceFeatures[]` on
  the app version and the feature policy on the built app page. This change adds
  `camera` to it.
- Specs `openspec/specs/page-designer-ui/spec.md` and
  `openspec/specs/page-editor-coverage/spec.md`: the index, detail and form page
  editors.
- Spec `openspec/specs/component-blocks/spec.md`: a code widget can be saved in a
  block like any widget.

## Sibling halves

- nextcloud-vue: the renderer. It owes a scanner (camera preview, decoding QR,
  Code 128 and EAN-13, typed fallback) used by a `scan` index action and a `scan`
  form field type, the lookup that opens or filters by the scanned value, and a
  `code` widget that draws a QR code or barcode as SVG in the browser. At 2.57.1
  it has none of these and no decoding or drawing library in its dependencies. No
  change for it exists on nextcloud-vue development.
- None other.

## Out of scope

- Printing labels in bulk. A detail page shows one record's code.
- Formats beyond QR, Code 128 and EAN-13.
- Scanning without a camera page, for example with a handheld scanner acting as a
  keyboard. The typed fallback covers that input.
