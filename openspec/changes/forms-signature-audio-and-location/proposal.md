---
kind: code
---

# Proposal: forms-signature-audio-and-location

## Why

Three rows of the buildiq matrix ask a form, or a page, to use something the
device has: a pen, a microphone, a position.

**buildiq matrix, row `form-signature`**, "Collect a signature in a form.",
rated `no`, `built.state` `none`. Three competitors rate it `yes`:

- Budibase: "packages/client/manifest.json:5348 signaturesinglefield ... Signature
  column type at packages/builder/src/constants/backend/index.ts:138 ... Reached
  on: design > add component > Form > Signature" (source read at v3.46.0).
- Mendix: "the platform-supported Signature widget lets a user draw a signature
  that is stored with the object" (https://docs.mendix.com/appstore/widgets/signature/).
- Microsoft Power Apps: "the pen input control lets a user draw, for example a
  signature, and the image is saved"
  (https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/controls/control-pen-input).

Today, from `built.evidence`: "nextcloud-vue v2.55.1 ships
src/components/CnSignatureCapture/CnSignatureCapture.vue but it is not a
formField type (cnFormFieldRenderer.js:127) and buildiq never imports or
registers it (src/registry.js, src/runtimeRegistry.js)".

**buildiq matrix, row `form-audio-record`**, "Record audio in a form, for example
a spoken note.", rated `no`, `built.state` `none`. Two competitors rate it `yes`:

- Appsmith: "app/client/src/widgets/index.ts:275 AUDIO_RECORDER_WIDGET registered
  in the widget list" (source read at v2.4.2).
- Microsoft Power Apps: "the Microphone control records audio with the device
  microphone and the latest clip is available through its Audio property"
  (https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/controls/control-microphone).

Today, from `built.evidence`: "grep -iE 'MediaRecorder|audio' over src/ and
nextcloud-vue v2.55.1 CnFormPage: no recorder field".

**buildiq matrix, row `pg-device-location`**, "Read the user's current location
from their device inside an app.", rated `no`, `built.state` `none`. Three
competitors rate it `yes`:

- Appsmith: "app/client/src/components/editorComponents/ActionCreator/constants.ts:21
  getGeolocation and :22 watchGeolocation in the no-code action picker used by
  widget events" (source read at v2.4.2).
- Mendix: "Nanoflow Commons has Get current location, Get current location with
  minimum accuracy and Request location permission actions"
  (https://docs.mendix.com/appstore/modules/nanoflow-commons/).
- Microsoft Power Apps: "the Location signal returns the device's latitude,
  longitude and altitude in Power Apps"
  (https://learn.microsoft.com/en-us/power-platform/power-fx/reference/signals).

Today, from `built.evidence`: "grep -iE 'geolocation' over src/: no hits; the map
page (pg-map) plots records, not the user's position".

One more fact decides the design. Nextcloud's default feature policy grants no
page the camera, the microphone or the position: `cameraDomains`,
`geolocationDomains` and `microphoneDomains` are empty in
`lib/public/AppFramework/Http/FeaturePolicy.php` (nextcloud/server master, lines
28, 35 and 38). A browser refuses those devices on a page served under that
policy, whatever the form does.

## What changes

- The form field builder gets three field types: signature, audio recording and
  location.
- A signature and an audio clip are stored in a file property of the form's
  schema. A location fills a latitude and a longitude property, the same pair the
  map page already reads.
- The map page gets "Show my position".
- The page that serves a built app allows the microphone and the position for
  that app only when its manifest uses them, and nothing else.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | form-signature | Collect a signature in a form. | no | a signature field type in the form builder, stored on the record |
| buildiq | form-audio-record | Record audio in a form, for example a spoken note. | no | an audio field type in the form builder, stored on the record |
| buildiq | pg-device-location | Read the user's current location from their device inside an app. | no | a location field in forms and "Show my position" on map pages |

## Existing work it builds on

- Specs `openspec/specs/page-designer-ui/spec.md` and
  `openspec/specs/form-editor-logic/spec.md`: the form field builder and field
  logic.
- Spec `openspec/specs/page-editor-coverage/spec.md`: the map page editor
  (REQ-PEC-003).
- Spec `openspec/specs/schema-designer-ui/spec.md`: the field editor, which gains
  the `file` type these fields store into.

## Sibling halves

- nextcloud-vue: the renderer. At 2.57.1 `cnFormFieldRenderer.js` knows
  `boolean`, `number`, `password`, `string`, `enum`, `json` and `file` (line 127).
  It owes `signature` (wrapping `CnSignatureCapture`, which exists), `audio` (a
  recorder field) and `location` (a position field with a map fallback through
  `CnMapPoiPicker`, which exists), the `formField.type` values in the manifest
  schema, and a `showUserLocation` option on `CnMapPage`. No change for it exists
  on nextcloud-vue development.
- openregister: nothing new. A `file` property stores the content the `file`
  field already sends (`cnFormFieldRenderer.js` header, lines 25-33).
- nextcloud/server: nothing to change. Its policy is widened per response by the
  app, through `OCP\AppFramework\Http\FeaturePolicy`.

## Out of scope

- Tracking a position over time, or in the background.
- Video recording and photos. The camera is `pages-qr-and-barcodes`'s concern for
  scanning only.
- Legal qualification of a signature. A drawn or typed signature is an image or
  a name, not a qualified electronic signature.
