---
kind: code
---

# Proposal: reuse-gallery-categories-and-form-library

## Why

**buildiq matrix, row `reuse-template-gallery`**, "Browse a gallery of starter
templates by category.", rated `partial`, `built.state` `built`. Three
competitors rate it `yes`:

- Appsmith: "app/client/src/pages/Templates/TemplateFilters/index.tsx:140 filter
  by categories (functions); gallery page app/client/src/pages/Templates/index.tsx:121
  ... templates come from Appsmith cloud, not shown when airgapped" (source read
  at v2.4.2).
- Mendix: "the Marketplace lists dozens of platform-supported starter apps (web,
  native mobile, GenAI, augmented reality) plus partner templates, filterable by
  component type" (https://docs.mendix.com/quickstarts/part1/).
- Microsoft Power Apps: "the Power Apps home page lets makers discover new app
  templates"
  (https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/intro-maker-portal).

Today, from `built.evidence`: "src/views/TemplateGallery.vue:463 fetchTemplates()
reads /apps/openregister/api/objects/buildiq/application-template (:327) and
renders cards with a category chip (:101-103, labels :329-334); four seeded
templates lib/Settings/templates/*.json each carry a category". The schema even
says what the category is for: "Top-level category used for filtering in the
gallery" (`lib/Settings/openbuild_register.json:328-337`). There is no filter
for templates; the only category filter on the page is the one for blocks
(`TemplateGallery.vue:248-262`, `filteredBlocks()` at line 430). The missing
half: browse and filter templates by category.

**buildiq matrix, row `reuse-shared-form-library`**, "Share built forms in a
library that other organisations can pick up and reuse.", rated `partial`,
`built.state` `built`. A tender demand row asks for it: TenderNed announcement
382064 (https://www.tenderned.nl/aankondigingen/overzicht/382064). No competitor
rates it `yes`; Mendix and Power Apps are `partial`:

- Mendix: "modules containing pages and forms can be published to the public
  Marketplace for other organisations after review; there is no dedicated form
  library" (https://docs.mendix.com/appstore/submit-content/).
- Microsoft Power Apps: "the catalog shares templates and components within one
  organisation; sharing with other organisations goes through AppSource
  publishing"
  (https://learn.microsoft.com/en-us/power-apps/maker/data-platform/catalog-overview).

Today, from `built.evidence`: "No form-level library. Whole apps, forms
included, can be saved as a template (src/dialogs/SaveAsTemplateDialog.vue) and
published to or installed from GitHub (reuse-github-shop,
lib/Service/GitHubCatalogService.php), which other organisations can pick up".
The missing half: one form, shared on its own.

## What changes

- The app store gets a category filter and category headings for templates,
  across the built-in, organisation and GitHub sources.
- A form page or a registration form can be saved to a form library: its fields,
  steps, logic and presets, plus the schema properties it needs. Never data.
- The app store gets a "Forms" view next to "Templates" and "Blocks", with
  search and the same category filter.
- "Use this form" adds the form to an app, as a form page or as a registration
  form, and offers to add the missing schema properties.
- Other organisations pick a form up by file (export and import) or from GitHub,
  where a form repository carries its own discovery topic.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | reuse-template-gallery | Browse a gallery of starter templates by category. | partial | a category filter and category browsing for templates |
| buildiq | reuse-shared-form-library | Share built forms in a library that other organisations can pick up and reuse. | partial | a library of single forms, shared by file and through GitHub |

## Existing work it builds on

- Specs `openspec/specs/template-catalogue-ui/spec.md` and
  `openspec/specs/openbuild-template-catalogue/spec.md`: the gallery and the
  template record.
- Spec `openspec/specs/component-blocks/spec.md` (archived
  `2026-07-24-component-blocks`): save a piece of a manifest, de-namespace its
  schema references, browse it by category, export and import it as a file. The
  form library follows that pattern for a whole form.
- Spec `openspec/specs/save-as-template/spec.md`: `deNamespaceSlug` and
  `rewriteSchemaRefs` in `src/services/templateCapture.js`.
- Open changes `github-shop-catalogue` and `github-app-repo-format`: GitHub
  discovery by topic and the parser that reads a published repository.
- Open change `registration-form-builder`: the registration form editor a
  library form can be saved from and added to.

## Sibling halves

None. A form library item is an OpenRegister object in buildiq's register, and
GitHub discovery already runs in buildiq.

## Out of scope

- Review or moderation of what others publish. Buildiq installs what the maker
  picks, as it does for apps.
- Sharing object data with a form.
- ADR-085 `form` objects in OpenRegister's `forms` register. When that register
  ships, a library form can be written into it; this change keeps form pages and
  registration forms as they are.
