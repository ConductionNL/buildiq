# Design: ai-smart-paste-into-forms

Read at buildiq development `d21e42f`.

## Where it sits

- Editor: `src/components/page-editor/FormPageEditor.vue`. The "Submit" fieldset
  holds method, `mode` (`public`, `create`, `edit`; lines 108-117), submit label
  and success message; the "Fields" fieldset mounts `FormFieldBuilder` with
  `showLogic` (lines 136-143). `validatedConfigKeys()` (lines 365-377) lists the
  config keys whose validator errors mark inline.
- Validation: `src/services/manifestValidation/formLogic.js` is buildiq's own
  check for form logic (REQ-OBFEL-005), returning `<pointer>: <code>` strings
  that light up inline marks.
- Renderer: `@conduction/nextcloud-vue` 2.57.1 `CnFormPage`
  (`src/components/CnFormPage/CnFormPage.vue`), props `fields`, `steps`,
  `submitHandler` (lines 299-330). The manifest schema describes the form config
  in the page `config` description of `src/schemas/app-manifest.schema.json`
  (line 298) and allows extra keys on `config`.
- Preview: `src/components/page-editor/PreviewSandbox.vue` renders the page being
  edited.

## D1. The manifest shape

`pages[].config.smartPaste` on a `type: form` page:

```json
{ "enabled": true, "fields": ["naam", "adres", "telefoon"], "hint": "Paste an email or letter" }
```

`fields` is a list of keys from `config.fields[]`. Empty is not allowed when
`enabled` is true: the maker names what the AI may fill.

## D2. Buildiq validates it

`formLogic.js` gains three checks: `smartPaste.fields` names only existing field
keys; `smartPaste.enabled` is refused when `config.mode` is `public`; and a
field of a type the fill cannot set (file, signature, relation) is refused. The
errors light up the new fieldset inline, and `smartPaste` joins
`validatedConfigKeys()`.

## D3. The editor

A new fieldset "Fill from pasted text" under "Fields": a switch, a checklist of
the form's fields filtered to fillable types, and an optional hint. The switch is
disabled with the reason "Only signed-in forms can use AI." while `mode` is
`public`.

## D4. What the renderer does, specified for the sibling

Buildiq specifies the behaviour it authors, so the preview and the rendered app
agree:

- The control shows only when `smartPaste.enabled` is true, the form is not
  `public`, and the fill endpoint reports available.
- The request carries the pasted text and, for each allowed field, key, label,
  type and allowed values. It never carries other field values.
- Proposals fill only empty, visible, allowed fields, unless the user chooses
  "Replace what I typed". Each filled field is marked as a suggestion until the
  user edits or accepts it.
- Every proposal passes the field's own validation before it is shown.
- Nothing is submitted automatically.

## D5. Preview

`PreviewSandbox.vue` shows the control in the preview with a fixed sample
answer, so a maker can see the behaviour without a model call.

## Risks

- Pasted text can hold personal data. The request carries only the pasted text
  and the field list, the fill endpoint is off until an administrator and a DPO
  turn it on in Hermiq, and nothing is stored by buildiq.
- The renderer lives in nextcloud-vue. Until it ships the control, the setting
  is inert in rendered apps; the editor says so with a note.

## What it does not do

- It adds no endpoint to buildiq.
- It does not read images or files.
- It does not save anything for the user.
