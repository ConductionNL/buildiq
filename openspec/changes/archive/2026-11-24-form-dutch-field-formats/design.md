# Design: form-dutch-field-formats

## Screens

Two boards on canvas `5NkFW28vZUUij43xzxHg5a`.

| Board | Element | Change |
| --- | --- | --- |
| BqFormulierOntwerper | Block "Controle": Verplicht, Minimum, Maximum, Patroon (regex), Eigen melding | "Formaat" select above "Patroon (regex)". When a format is chosen, Patroon is disabled with the hint "Het formaat controleert dit veld al." |
| BqVeldBewerken | "Formaat (niet verplicht)", "geen" | The Dutch entries follow the JSON Schema ones, under a group label "Nederlandse formaten". |

The labels: BSN, IBAN, Kenteken, Telefoonnummer (Nederlands), Telefoonnummer (internationaal), Postcode, KvK-nummer, Vestigingsnummer. Each option has a one-line hint shown under the select ("Controleert de elfproef", "Controleert het controlegetal", "Met of zonder streepjes", "1234 AB, de spatie mag weg").

## D1. Names

The stored values are portaliq's: `bsn`, `iban`, `nl-licence-plate`, `phone-nl`, `phone-international`, `postcode`, `kvk`, `kvk-branch`. One list in `src/services/dutchFormats.js` feeds both selects and the client check.

## D2. Inheritance

A form field on a schema property with a Dutch format shows that format, disabled, with "Komt uit het schema". A form field on a property without one may set any format.

## D3. The server check

`DutchFormatValidator` implements the eight rules exactly as portaliq's design lists them. Buildiq's published app forms run it on save through the form save listener for fields whose published form carries `format`. The rule tables (RDW side codes 1 to 14, the IBAN country lengths) are data files shared by the PHP and JS checks, generated from one source so they cannot drift.
