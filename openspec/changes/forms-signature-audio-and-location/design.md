# Design: forms-signature-audio-and-location

Read at buildiq development `d21e42f`; nextcloud-vue at the pinned 2.57.1.

## Where it sits

- Form field builder: `src/components/page-editor/fields/FormFieldBuilder.vue`,
  `FIELD_TYPES` `['string', 'number', 'boolean', 'select', 'textarea', 'date']`
  (line 122), rendered as a select (line 41).
- Schema field editor: `src/components/schema-editor/FieldEditor.vue`,
  `SUPPORTED_TYPES` (lines 319-327) without `file`.
- Map page editor: `src/components/page-editor/MapPageEditor.vue` (REQ-PEC-003),
  `center`, `zoom`, `height`, `markers` with `latField` and `lngField`.
- Built app page: `DashboardController::builder()`
  (`lib/Controller/DashboardController.php:125`) returns the `TemplateResponse`
  for a virtual app and provides `builderSlug` and `builderVersion`.
- Renderer: `@conduction/nextcloud-vue` 2.57.1 `cnFormFieldRenderer.js`
  (`KNOWN_TYPES` at line 127; `file` renders `CnFileField` and sends a `data:`
  URL, default cap 1 MB), `CnSignatureCapture` (props `allowTyped`, `allowDrawn`,
  `initialMode`), `CnMapPoiPicker`, `CnMapPage`.
- Browser policy: Nextcloud's default `FeaturePolicy` has empty camera,
  geolocation and microphone lists.

## D1. Three field types

`FIELD_TYPES` gains `signature`, `audio` and `location`, each with its own
options in the builder:

- `signature`: `allowTyped`, `allowDrawn` (at least one), target `file` property.
- `audio`: `maxSeconds` (5 to 120, default 60), target `file` property. The
  builder shows the size a clip of that length takes and refuses a length that
  passes the renderer's inline file cap.
- `location`: `latField` and `lngField` (two number properties), optional
  `accuracyField`, and `allowMapPick` (default true) so a user who refuses the
  browser prompt can still place a pin.

## D2. Where the value lands

The builder checks the form's target schema. A signature or audio field needs a
`file` property; a location field needs two number properties. When they are
missing, the builder offers "Add the property to <schema>", which adds them
through the schema designer's save path. `FieldEditor.vue` gains `file` in
`SUPPORTED_TYPES` for that and for makers who add one by hand.

## D3. Show my position on a map

`MapPageEditor.vue` gains "Show my position" (`config.showUserLocation`, default
false) and "Center on my position when the page opens"
(`config.centerOnUser`).

## D4. The page asks the browser only for what the app uses

When a manifest is saved, buildiq derives the device features it needs:
`microphone` for an `audio` field, `geolocation` for a `location` field or
`showUserLocation`. It stores them on the `ApplicationVersion` as
`deviceFeatures[]`. The three responses that serve a built app,
`DashboardController::builder()`, `builderSlash()` and `builderPath()`
(`lib/Controller/DashboardController.php:125`, `158`, `193`), read the served
version's `deviceFeatures` and set a `FeaturePolicy` that adds `'self'` for those
features only. An app that uses none gets Nextcloud's default policy
unchanged. `pages-qr-and-barcodes` adds `camera` through the same list.

## D5. Validation

`src/services/manifestValidation/formLogic.js` checks the new types: the target
properties exist with the right types, `maxSeconds` is in range, and a signature
allows at least one mode. A form in `public` mode may use `signature` but not
`audio` or `location`, because an anonymous public page is portaliq's surface
(ADR-108).

## Risks

- A hybrid app rendered by another host gets that host's policy, not buildiq's.
  The builder says the host must allow the microphone or the position.
- An audio clip is inline content with a size cap. Longer recordings need a file
  upload flow, which is out of scope.
- A user can refuse the position. The map pick fallback keeps the form usable.

## What it does not do

- It does not track position over time.
- It does not record video or take photos.
- It does not widen the browser policy for apps that do not use these fields.
