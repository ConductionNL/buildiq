# Spec: form-smart-paste

## Purpose

A maker lets the users of a form fill it from pasted text. The app user pastes
an email or a letter, the AI proposes values for the fields the maker allowed,
and the user checks them before submitting.

## ADDED Requirements

### Requirement: A maker turns on smart paste for chosen fields (REQ-BQSP-001)

The form page editor SHALL offer a "Fill from pasted text" setting with a switch,
a checklist of the form's fillable fields and an optional hint. Saving SHALL
write `config.smartPaste` with `enabled`, `fields[]` and `hint`.

#### Scenario: A maker allows three fields

- **GIVEN** a maker editing the form page `Nieuwe klant` in `create` mode
- **WHEN** they switch on "Fill from pasted text", tick `naam`, `adres` and `telefoon`, and save
- **THEN** the page config carries `smartPaste` with `enabled` true and those three field keys

### Requirement: Smart paste is validated (REQ-BQSP-002)

Buildiq's manifest validation SHALL refuse `smartPaste` when it names a field key
the form does not have, when it names a field of a type the fill cannot set, when
it is enabled with no fields, or when the form's `mode` is `public`. Each error
SHALL mark the "Fill from pasted text" fieldset inline.

#### Scenario: A public form cannot use smart paste

- **GIVEN** a form page in `public` mode
- **WHEN** the maker tries to switch on "Fill from pasted text"
- **THEN** the switch stays off and says "Only signed-in forms can use AI."

#### Scenario: A removed field is caught

- **GIVEN** a form with smart paste allowed on `telefoon`
- **WHEN** the maker deletes the `telefoon` field and saves
- **THEN** validation marks the fieldset with an unknown field `telefoon`

### Requirement: The authored behaviour is fixed for the renderer (REQ-BQSP-003)

A form with smart paste enabled SHALL, when rendered, offer "Paste to fill" only
to signed-in users, send the pasted text and the allowed fields only, fill only
empty, visible, allowed fields unless the user chooses to replace, mark filled
values as suggestions, and never submit on its own. The page designer preview
SHALL show this behaviour with a sample answer.

#### Scenario: An app user fills a form from an email

- **GIVEN** an app user on the `Nieuwe klant` form with smart paste on `naam`, `adres` and `telefoon`
- **WHEN** they click "Paste to fill", paste an email signature and confirm
- **THEN** the three fields show proposed values marked as suggestions, the other fields stay empty, and the form waits for Submit
