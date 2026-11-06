---
kind: code
depends_on: []
---

# Proposal: forms-designer-file-camera-children-and-assign

## Why

nextcloud-vue now renders four form capabilities that buildiq's form designer cannot set. On nextcloud-vue development (`e7b3a09b6`) these shipped:
- file fields with several files, drag and drop and upload after save (`form-file-and-camera-fields`);
- `capture` for the device camera (same change);
- a `child-records` field that edits another schema's records as a table inside the form (`form-child-records-table`);
- `assign` rules that fill one field from another answer (`form-live-values`).

The page designer offers six field types (`FIELD_TYPES = ['string', 'number', 'boolean', 'select', 'textarea', 'date']` in `FormFieldBuilder.vue:151` and `FormLayoutBuilder.vue:253`), and none of these options. So a maker cannot build them without editing raw JSON.

Rows: `form-file-upload`, `form-camera-capture`, `form-subtable-children`, `form-conditional-values`.

## What changes

- The field type list gains `file` and `child-records`.
- A file field gets settings for allowed types, maximum size, several files, and camera (`capture`: off, back camera, front camera).
- A child-records field gets its child schema, the field on the child that points at the parent, and the columns shown in the table.
- Every field gets an "Fill in from another answer" editor that writes `assign` rules: a condition on another field and a value (a literal, `@answer.<field>` or a token).
- The keys written are exactly the ones nextcloud-vue reads. buildiq adds no vocabulary.

## Screens

BqFormulierOntwerper draws the field panel; the board does not yet draw these four settings (matrix list (b)). See `screens.md`.

## Out of scope

- Rendering, which nextcloud-vue owns.
- Signature, audio and location fields (`forms-signature-audio-and-location`) and the product list (`forms-multi-product-request`).
