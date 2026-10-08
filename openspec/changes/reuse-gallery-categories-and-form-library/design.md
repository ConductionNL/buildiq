# Design: reuse-gallery-categories-and-form-library

Read at buildiq development `d21e42f`.

## Where it sits

- Gallery: `src/views/TemplateGallery.vue` (954 lines). A tablist toggles
  "Templates" and "Blocks" (lines 14-30). Under "Templates": built-in templates
  from `application-template` (`OR_TEMPLATES` line 327, `fetchTemplates()` line
  463, cards with a category chip at lines 101-103, `CATEGORY_LABELS` lines
  329-334, `categoryLabel()` line 689) and the GitHub source with a search field
  (lines 125-130). Under "Blocks": a category `NcSelect` (lines 252-259),
  `blockCategoryOptions()` (line 416) and `filteredBlocks()` (line 430).
- Template category: `ApplicationTemplate.category` in
  `lib/Settings/openbuild_register.json:328-337`, a closed enum of four values
  "used for filtering in the gallery".
- GitHub cards: `lib/Service/GitHubCatalogService.php` searches the discovery
  topics `topic:buildiq-app` and `topic:openbuild-app` (lines 77-80) and maps a
  descriptor's `category` onto each card (line 769).
- Blocks, the pattern to follow: `lib/Settings/register.d/60-component-blocks.json`
  (`componentBlock` with `category`, `schemaDependencies`, `fragment`,
  `sourceApplicationSlug`, `createdBy`), `src/dialogs/SaveBlockDialog.vue`,
  `src/dialogs/BlockRemapDialog.vue`, `src/services/blockCapture.js` (which reuses
  `deNamespaceSlug` and `rewriteSchemaRefs` from `src/services/templateCapture.js`)
  and `src/services/blockExport.js` (`exportBlockPayload()` line 49,
  `parseBlockImport()` line 92, envelope `kind`).
- Forms: form pages authored in `src/components/page-editor/FormPageEditor.vue`;
  registration forms in `lib/Settings/register.d/50-registration-forms.json`
  (`registrationForm` 0.4.0) authored in
  `src/components/page-editor/fields/RegistrationFormEditor.vue`.

## D1. Browse templates by category

`TemplateGallery.vue` gains a category `NcSelect` under "Templates", built like
the blocks filter, and groups built-in and organisation template cards under a
heading per category. The filter narrows the GitHub cards too, by the `category`
their descriptor carries. A card without a category shows under "Other". The
selection is kept in the URL query (`?category=`), so a link opens the same
view.

## D2. The library item

A new fragment `lib/Settings/register.d/83-form-library.json` declares
`formTemplate` (slug `form-template`): `slug`, `name`, `description`,
`category` (the template enum), `kind` (`form-page` or `registration-form`),
`form` (the form config: fields, steps, logic, presets, sections and the
confirmation text; never object data), `schemaFragment` (the property
definitions the form's fields need, de-namespaced), `publisher` (organisation
name, free text), `version`, `sourceApplicationSlug` and `createdBy`. Access
follows OpenRegister's RBAC on the object, like blocks.

## D3. Save to the library

A "Save to form library" action in `FormPageEditor.vue` and in
`RegistrationFormEditor.vue` opens `src/dialogs/SaveFormToLibraryDialog.vue`.
A new `src/services/formCapture.js` captures the form, de-namespaces its schema
references with `deNamespaceSlug` and `rewriteSchemaRefs`, and copies the
definitions of the properties its fields bind to into `schemaFragment`. A form
whose fields bind to a property the schema lacks is refused with the property
named.

## D4. Use a library form

The gallery's "Forms" view lists `form-template` objects with search and the
category filter. "Use this form" opens `src/dialogs/UseLibraryFormDialog.vue`:
pick the app and version, pick the target (a new form page, or a registration
form for a schema and type value), and map the form's schema to one of the app's
schemas, as `BlockRemapDialog.vue` does for blocks. Properties the target schema
lacks are listed with "Add these properties", which adds them from
`schemaFragment` through the schema designer's save path. Nothing is written
before the maker confirms.

## D5. Between organisations

- By file: `src/services/formExport.js`, following `blockExport.js`, with the
  envelope `kind: "form-template"` and a schema version. Import validates the
  envelope and the form config before it creates the library item.
- Through GitHub: a form repository carries the topic `buildiq-form` and a
  `form.json` at its root holding the export envelope. `GitHubCatalogService`
  gains a form search on that topic, cached like the app search, and the "Forms"
  view shows those cards beside the local ones. Installing one creates a local
  library item; using it is D4.

## Risks

- A shared form can carry a confirmation text or presets that do not fit another
  organisation. The use dialog shows the whole form before it is added.
- The GitHub topic starts empty. The file route works from day one.

## What it does not do

- It does not moderate what others publish.
- It carries no object data.
- It does not move forms into OpenRegister's `forms` register.
