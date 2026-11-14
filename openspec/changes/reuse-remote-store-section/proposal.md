---
kind: code
---

# Proposal: reuse-remote-store-section

## Why

**buildiq matrix, row `reuse-remote-store`**, "Install templates from a shared
remote catalogue.", rated `no`, `built.state` `building`.

The backend exists and nobody can reach it. From `built.evidence`:
`store#search` (`GET /api/store/templates`) and `store#install`
(`POST /api/store/templates/{slug}/install`) are routed in
`appinfo/routes.php:292-293` and served by `lib/Controller/StoreController.php`,
which delegates to OpenRegister's `GenericStoreService`. The first-run setup
wizard even asks for the store URL (`src/manifest.json` setup step `store`).
But no page calls the search, and the install is reachable only through a
`CloneTemplateDialog` branch that no parent enables.

The main spec still says otherwise. `template-catalogue-ui` requires the
remote store as the primary surface of the Templates page. The page was
rebuilt since (built-in templates, organisation templates, a category filter,
GitHub apps, blocks, forms), and the design board `BqStore` draws that page
without a remote section. This change puts the shared catalogue back on the
page as one section among the others, in the shape the board uses for every
source.

## What changes

- The "Templates" view of the app store gets a section "Shared catalogue",
  between "Built-in templates" and "Apps on GitHub", shown only when an admin
  configured a store.
- The section has a search field and a card grid. Each card has an "Install"
  button that opens the existing clone dialog in store mode.
- The category filter of the "Templates" view also narrows the store cards.
- Store errors show as a note in the section. The rest of the page keeps
  working.
- Admins without a configured store see one line pointing to the setup.
- The requirement that made the remote store the primary surface of the page
  is removed: it contradicts the board and the page as built.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | reuse-remote-store | Install templates from a shared remote catalogue. | no | a page section that calls `store#search` and opens the install |

## Existing work it builds on

- Spec `openspec/specs/openbuild-remote-template-store/spec.md`: the registry
  settings, the server-side proxy, the SSRF guard and the two store routes.
  Unchanged.
- Spec `openspec/specs/template-catalogue-ui/spec.md`: the gallery, the clone
  dialog and its store install route (requirement "Install through
  CloneTemplateDialog calls the store endpoint", unchanged).
- Change `reuse-gallery-categories-and-form-library`: the category filter and
  `?category=` this section joins.
- Change `openbuild-first-time-setup`: the setup step that stores
  `registry_url`.

## Sibling halves

None. `GenericStoreService` on OpenRegister is already on development; this
change is the buildiq page only.

## Out of scope

- Publishing a template to a remote catalogue. The store stays consume-only.
- A second store. One registry URL per instance, as today.
