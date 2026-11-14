---
kind: code
depends_on: []
---

# Proposal: form-dutch-field-formats

## Why

Portaliq's `data-lookups-and-checks-in-forms` (merged in portaliq #1387) checks Dutch formats on a form field with `format`: `bsn` (elfproef), `iban` (ISO 13616 mod 97), `nl-licence-plate`, `phone-nl`, `phone-international`, `postcode`, `kvk` and `kvk-branch`, while typing and on the server. It says "buildiq authors formats, reference-list choices and fetch steps in the form designer". The FormulierVelden board (canvas `5NkFW28vZUUij43xzxHg5a`) draws BSN, IBAN with "Dit IBAN klopt niet. Controleer de cijfers.", Kenteken and Telefoonnummer.

Portaliq row `int-dutch-field-checks` (decision 104). Open Formulieren 4.0.1 ships these as components (`src/openforms/formio/components/custom.py:514` bsn, `:1112` iban, `:1149` licenseplate) with validators (`src/openforms/validations/validators/formats.py:40`, `:69`).

What buildiq has:

- The form designer's "Controle" block (BqFormulierOntwerper): Verplicht, Minimum, Maximum, "Patroon (regex)", "Eigen melding" (`src/components/page-editor/fields/FieldValidationBuilder.vue`).
- The schema field editor's "Formaat (niet verplicht)" (BqVeldBewerken), which offers JSON Schema formats such as `email`, `date` and `uri`.
- `data-field-types-and-choice-lists` (open) adds typed fields and shared choice lists, with no Dutch formats.

A maker can only reach a BSN check today by writing a regex, and no regex can do the elfproef or the IBAN checksum.

## What changes

- **Formaat in the designer's Controle block.** A select "Formaat" with: geen, BSN, IBAN, Kenteken, Telefoonnummer (Nederlands), Telefoonnummer (internationaal), Postcode, KvK-nummer, Vestigingsnummer. It writes `format` on the published form field with portaliq's names.
- **The same list on the schema field.** BqVeldBewerken's "Formaat" gains the same entries, so a schema property can carry the format and every form on that schema inherits it; a form field may narrow but not drop it.
- **Checks in buildiq's own forms too.** Buildiq's published app forms (not only portal forms) check these formats on the server on save, with the same rules and messages as portaliq.
- **A pattern next to a format is refused** in the designer with a message, because two checks on one field disagree in ways a maker cannot see.

## Rows this closes

| matrix | row id | row name | what is missing |
|-|-|-|-|
| portaliq | int-dutch-field-checks | Check Dutch formats such as BSN, IBAN, licence plate and phone number in a form. | the designer option that writes `format` |

## Out of scope

- The checks on the portal and their messages there: portaliq's `DutchFormats.php` and `formats.js`.
- An address field from postcode and house number: buildiq `form-address-lookup` is decided-no; portaliq reads the address itself.

## Cross-app

- portaliq `data-lookups-and-checks-in-forms` reads `format` from the published form.
- OpenRegister validates a schema property's `format` with its JSON Schema validator and does not know these names. Buildiq checks them in its own save listener (T04); teaching OpenRegister the Dutch formats, so every write path checks them, is a follow-up for OpenRegister's lane.

## Impact

- Specs: new capability `form-dutch-field-formats`.
- New: `lib/Service/DutchFormatValidator.php`, `src/services/dutchFormats.js`.
- Changed: `src/components/page-editor/fields/FieldValidationBuilder.vue`, the schema field editor's format list, `lib/Listener/FormLiveValuesListener.php` or a sibling save listener, `l10n/`.
