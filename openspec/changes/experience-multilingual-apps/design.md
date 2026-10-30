# Design: experience-multilingual-apps

Read at buildiq development `d21e42f` and `@conduction/nextcloud-vue` 2.57.1.

## Where it sits

- Runtime: `src/builder.js` defines `translateForApp()` (lines 158-168, calling
  `t('buildiq', key, vars)`) and passes it to `CnAppRoot` as `translate` (line
  411).
- Preview: `src/views/PageDesigner.vue:179` passes `translateForPreview`
  (lines 1059-1061, the same `ncT('buildiq', key)`) to `PreviewSandbox`, which
  forwards it in `rootProps()` (`src/components/page-editor/PreviewSandbox.vue:138`).
- Renderer: `CnAppRoot` provides `cnTranslate` (`CnAppRoot.vue:832`) from its
  `translate` prop (line 1332); `CnAppNav` falls back to the injected one
  (`src/components/CnAppNav/CnAppNav.vue:373`, used at line 541).
- Schema: `src/schemas/app-manifest-v2.schema.json` has no `i18n` key and
  refuses unknown top-level keys. Buildiq validates through
  `src/composables/useManifestValidator.js` (library call, then app checks at
  lines 80-86).
- Settings: `src/modals/AppSettingsModal.vue`.
- Export: the template ships `l10n/en.json` and `l10n/nl.json`
  (`lib/Resources/template/l10n/`), shaped `{"translations": {...}}`.

## D1. Translations live in the manifest, keyed by the written text

A version's manifest gets `i18n: {sourceLanguage, languages[], labels: {<lang>: {<source text>: <translation>}}}`.
The key is the text as written, the way Nextcloud's own `t()` works, so a label
needs no id and an unchanged label keeps its translations. Keeping the block in
the manifest means promotion, GitHub sync, export and undo carry it without new
plumbing. A label whose source text changes loses its translations, and the view
shows it as missing, which is the ADR-025 source-of-truth behaviour.

## D2. One resolver, in the renderer

The label lookup belongs where every host passes through: `CnAppRoot` (sibling
half) looks in `manifest.i18n.labels[userLanguage]`, then falls back to the host's
`translate`, then to the text itself. Buildiq's own change to `translateForApp()`
and `translateForPreview()` is only to stop hiding a maker's text behind buildiq's
catalogue: they keep translating buildiq's strings and leave the rest to the
renderer.

## D3. Collect labels from the manifest, not from the screen

`src/services/manifestLabels.js` walks the known label places of a version's
manifest: `menu[].label`, `pages[].title`, index `config.columns[].label` and
`config.actions[].label`, form field `label`, `placeholder` and `help`, widget
`title`, and empty-state text. It returns each text with where it is used. The
Translations view (`src/views/TranslationsView.vue`, reached from the app's page
designer) shows one row per distinct text, one column per language, a missing
count per language, and a filter for missing only. Saving writes `i18n.labels`
through the same manifest save the designer uses, so undo and the version's
semver behave as for any edit.

## D4. The preview can switch language

The preview gets a language picker listing the app's languages. It hands
`PreviewSandbox` a translate function bound to the picked language, so a maker
checks a page in Dutch without changing their own Nextcloud language.

## D5. The export writes the app's own l10n files

`ExportAppContentBundler` writes one `l10n/<lang>.json` per language of the
exported version, in the template's `{"translations": {...}}` shape, merged with
the template's own strings. An exported app then translates like any Nextcloud
app, with no dependency on buildiq.

## Risks

- A label that nextcloud-vue shows without `cnTranslate` stays untranslated. The
  sibling list of such components is the control, and the Playwright test checks
  a page of each type.
- The same text in two places always gets the same translation. That is the
  Nextcloud convention and fits labels; a maker who needs two meanings writes two
  texts.
- A large app has hundreds of labels. The view filters to missing and to one page.

## What it does not do

- It stores no translations outside the manifest.
- It translates no records and no schema titles.
