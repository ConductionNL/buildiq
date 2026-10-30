---
kind: code
---

# Proposal: experience-multilingual-apps

## Why

buildiq matrix, row `ux-multilingual-ui`, "Offer a built app's interface in
several languages.", rated `no`. No tender, featureRequest or roadmap row carries
it. Two competitors rated `yes`:

- NocoBase: "packages/plugins/@nocobase/plugin-localization/src/client-v2/plugin.tsx:19
  Localization settings page to translate menu titles, field labels and other
  texts per language; optional preset plugin packages/presets/nocobase/package.json:67;
  users pick a language from the user menu".
- Mendix: "all text presented to end users can be translated to different
  languages" (https://docs.mendix.com/refguide/translatable-texts/).

What buildiq does today, per the matrix `built.evidence`: "src/builder.js:157-167,
translateForApp(key, vars) comment: 'Virtual-app manifests usually carry plain
strings; t() returns them unchanged when no translation is registered.' There is
no per-built-app translation catalogue or locale-keyed label shape anywhere in the
manifest schema or page-editor fields". The matrix note separates this from the
builder's own chrome, which is translated through `l10n/`: "a built app's own
content/labels stay in whichever language they were typed in".

The plumbing is half there. nextcloud-vue's `CnAppRoot` takes a `translate`
function and provides it as `cnTranslate` (`src/components/CnAppRoot/CnAppRoot.vue:832`,
prop at line 1332 at 2.57.1), and 39 other component files read it, so most labels
already pass through one function. Buildiq hands it
`translateForApp()` (`src/builder.js:166`, passed at line 411), which looks the
label up in buildiq's own catalogue, where a maker's labels never are. What is
missing is a place for an app's own translations, a screen to write them, and a
lookup that uses them.

## What changes

- An app has languages: the one its labels are written in, and any number of
  extra languages the maker adds.
- A "Translations" view lists every label of a version (menu entries, page
  titles, columns, actions, form fields, widget titles, empty states), one column
  per language, with a count of what is missing.
- An app user sees the app in their Nextcloud language when the app has it, and
  in the written language when it does not. A missing label falls back per label,
  never per page.
- The live preview gets a language switch.
- Translations travel with the version: promotion, GitHub sync, and export, where
  they become the exported app's `l10n/` files.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | ux-multilingual-ui | Offer a built app's interface in several languages. | no | a per-app translation catalogue, a screen to fill it, and a runtime that uses it |

## Existing work it builds on

- `openbuild-nl-locale-parity` (open): the builder's own Dutch strings. This
  change is about the maker's labels, not buildiq's.
- `openspec/specs/page-designer-ui` and `openspec/specs/openbuild-page-designer`:
  the page editors whose label fields the view collects.
- `openspec/specs/openbuild-exporter` and `openspec/specs/github-app-repo-format`:
  the export and the repository format, which carry the manifest and so the
  translations.
- hydra ADR-025 (i18n source of truth): the written language is the source, the
  others are translations of it.

## Sibling halves

- nextcloud-vue owes two things. The app manifest schema
  (`src/schemas/app-manifest-v2.schema.json`, `additionalProperties: false` at the
  top level at 2.57.1) needs an `i18n` block, so a manifest carrying translations
  validates. And `CnAppRoot` should resolve a label from the manifest's `i18n`
  for the user's language before it calls the host's `translate`, so the builder
  runtime, the preview and an exported app all behave the same. Any component
  that shows a manifest label without `cnTranslate` needs to be listed and fixed
  there. No nextcloud-vue change for it exists at development `c8aa858`.
- openregister: none for this change. Record content in several languages is
  OpenRegister's `register-i18n`, and it is out of scope here.

## Out of scope

- Translating records (OpenRegister's register-i18n).
- Translating schema property titles that pages show without a label of their
  own. The view lists them as "from the schema", untranslatable here.
- Machine translation suggestions.
- A language picker inside the app. The user's Nextcloud language decides.
