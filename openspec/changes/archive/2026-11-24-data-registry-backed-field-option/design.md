# Design: data-registry-backed-field-option

## Board

**BqVeldBewerken** draws the field dialog "Veld bewerken: onderwerp": Naam,
Type, Formaat (optioneel), Verplicht, Omschrijving, then grouped sections
"Controles voor tekst", "Controles voor getallen" ("Alleen bij type number of
integer"), "Lijsten en relaties" ("Alleen bij type array of een relatie"), and
"Annuleren | Toepassen". It does not draw a registry source. This change adds
one more grouped section after "Controles voor tekst", in the same form:
heading "Waarde uit een register" ("Value source"), a grey lead line "Alleen bij
type string of object", then the controls. Nothing else on the board moves.

**BqOntwerperKoppelingen** draws a page-level external source ("Gegevensbron
van de pagina Bedrijven", source "KvK Handelsregister", endpoint
`kvk/companies`). That is a list page reading an integriq endpoint and already
exists; it is not this field-level declaration. The two do not share UI.

## D1. A source beside the type, not a new type

integriq's design (D1 of `registry-backed-field-source`) rejects a field type
per registry: xxllnc carries 39 attribute types, nine of them BAG lookups. A
property keeps `string` or `object` and carries a source declaration. So the
field editor does not grow a "KvK" type in the type picker. It grows a "Value
source" section, shown for `string` and `object` only, and the type picker
stays as it is.

## D2. The provider list comes from integriq

`GET {integriq}/api/property-sources` returns `results[]` with `id`, `label`,
`identifier`, `stalenessBudget`, `listShaped`. The integriq path is built with
`fleetAppPath('integriq', '/api/property-sources')` from
`src/services/fleetAppId.js`, never a literal app id. The picker shows
`label`, with `identifier` as the secondary line ("keyed on kvkNummer"). The
list is fetched once per schema designer session.

When integriq is not installed or the call fails:

- a field without a declaration shows the note "Registry lookups need the
  integriq app." and no picker;
- a field with a declaration shows the provider id and mode as read-only text
  and the note, and a save writes the declaration back unchanged.

## D3. What is written

```json
"kvkNummer": {
  "type": "string",
  "x-openregister-property-source": { "provider": "kvk", "mode": "live", "config": {} }
}
```

`config` is written as `{}` for a new declaration and carried through
unchanged for an existing one; v1 has no provider-specific config UI because
`describe()` publishes no config schema. `x-openregister-property-source` is
added to `EDITOR_OWNED_KEYS` in `FieldEditor.vue`, so choosing "Typed in"
removes it. `x-openregister-object-source` is a different key (a whole schema's
objects) and the editor never writes it.

## D4. Refusals

OpenRegister refuses a malformed declaration at schema save with
`PropertySourceException`, naming the property path. The schema designer's save
error handler maps a refusal that names a property to that field row and opens
it, the same way a name clash is shown today.

## D5. The fields table

The "Format" column of the fields table on BqVeldBewerken shows
"{provider label}, live" or "{provider label}, starting value" for a field with
a declaration, so a maker sees which fields read a registry without opening
each one.
