# Design: pages-qr-and-barcodes

Read at buildiq development `d21e42f`; nextcloud-vue at the pinned 2.57.1.

## Where it sits

- Index page editor: `src/components/page-editor/IndexPageEditor.vue`, fieldsets
  "Columns" (line 66), "Actions" (line 75) and "Sidebar" (line 83).
- Form field builder: `src/components/page-editor/fields/FormFieldBuilder.vue`,
  `FIELD_TYPES` (line 122), extended by `forms-signature-audio-and-location`.
- Detail page editor: `src/components/page-editor/DetailPageEditor.vue` and the
  widget list `src/components/page-editor/fields/WidgetBuilder.vue`, where a
  widget is `{id, title, type}` (lines 13-31, new rows default to `custom` at
  line 92).
- Built app pages: `DashboardController::builder()`, `builderSlash()` and
  `builderPath()` (`lib/Controller/DashboardController.php:125`, `158`, `193`),
  served at `/apps/buildiq/builder/{slug}/{path}` (`appinfo/routes.php:162`).
- Device features: `deviceFeatures[]` on the app version and the per-response
  feature policy, from `forms-signature-audio-and-location` D4.

## D1. Scan on an index page

`config.scan = {enabled, field, onMatch}` on a `type: index` page. `field` is a
property of the page's schema that holds the code (for example `serienummer`).
`onMatch` is `open` (one match opens its detail page) or `filter` (the list
filters to the matches). With no match the page says "No record has this code."
The index page editor gets a "Scan" section with the switch and the field picker.

## D2. Scan into a form field

A form field type `scan` with `formats[]` (`qr`, `code128`, `ean13`) fills a
string field from a scanned code. Typing stays possible, so a handheld scanner or
a refused camera still works.

## D3. A code on a detail page

A widget type `code` with `format` (`qr`, `code128`, `ean13`) and `source`:
`field` (a property of the record) or `address` (the record's address in the
built app, `/apps/buildiq/builder/{slug}/{detail route}` with the record id). A
Code 128 or EAN-13 source must be a field, and EAN-13 requires digits only. The
widget offers "Download as SVG". Drawing happens in the browser; the value never
leaves it.

## D4. The camera, only where scanning is used

On manifest save, buildiq adds `camera` to the version's `deviceFeatures[]` when
any index page has `scan.enabled` or any form has a `scan` field. The three
built-app responses (`builder()`, `builderSlash()`, `builderPath()`) apply the
policy of `forms-signature-audio-and-location` D4, so the camera is allowed for
`'self'` on those apps only.

## D5. Validation

`src/services/manifestValidation/` gains checks: `scan.field` exists on the
page's schema; `scan` is refused on `public` forms (portaliq's surface, ADR-108);
a `code` widget's field exists and fits its format.

## Risks

- Decoding in the browser depends on the device. The typed fallback keeps the
  page usable.
- A QR code with the record's address shows that address to whoever sees the
  code. Opening it still needs a signed-in user with access to the record.

## What it does not do

- It does not print labels in bulk.
- It does not send a scanned value anywhere but the page's own lookup.
