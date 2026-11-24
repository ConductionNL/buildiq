# Design: reuse-remote-store-section

Read at buildiq development `c8329a1cf`.

## The board

Canvas `5NkFW28vZUUij43xzxHg5a`, board **`BqStore`** ("buildiq: store",
`~/memcap-work/zuiddrecht/v2/project/BqStore.dc.html`). It draws the app store
as a page title "Store", a lead line, tabs, a category filter and one section
per source: "Ingebouwde sjablonen" (built-in, with organisation templates
marked "Organisatiesjabloon") and "Apps op GitHub" (search field "GitHub
doorzoeken", cards with title, category chip, source, description, a meta line
and a primary "Installeren" button). The board has no remote store section.

The new section copies the GitHub section's shape exactly, so the page reads as
one list of sources:

| Element | Board source | This section |
|-|-|-|
| Section heading | "Apps op GitHub" | "Shared catalogue" (nl: "Gedeelde catalogus") |
| Lead line | "Repositories met het onderwerp buildiq-app" | the store's host name, for example "From store.example.nl" |
| Search field | "GitHub doorzoeken" | "Search the catalogue" (nl: "Catalogus doorzoeken") |
| Card title | app name | template `title` |
| Category chip | "Buitendienst" | template `category`, through `categoryLabel()` |
| Source line | `ConductionNL/meldingen-or` | template `useCase` |
| Description | one sentence | template `description` |
| Meta line | "virtueel · v0.3.0 · 6 okt bijgewerkt" | `v{version}` |
| Action | primary "Installeren" | primary "Install" (nl: "Installeren") |

Order on the page: built-in templates, shared catalogue, apps on GitHub. The
shared catalogue sits above GitHub because an organisation chose it: an admin
configured it in setup step 3 of board **`BqBuildiqInstellen`** ("Externe
sjabloonwinkel").

## Where it sits in the code

- `src/views/TemplateGallery.vue`: the "Templates" view renders the built-in
  section (`data-testid="builtin-templates"`, around line 75) and the GitHub
  section (heading at line 170, search at 175-185, cards at 225-300). The new
  section goes between them, as its own component
  `src/components/store/SharedCatalogueSection.vue` so the gallery does not
  grow past its 1,148 lines.
- `src/modals/CloneTemplateDialog.vue:300` already posts to
  `/apps/buildiq/api/store/templates/{slug}/install` in store mode. The section
  opens it with `mode: 'store'` and the card as template.
- `lib/Controller/StoreController.php` `search()` returns
  `{outcome, cards}` with outcome `ok`, `not_configured`, `store_unreachable`
  or `store_invalid_response`, and never the registry URL or token.

## D1. Show the section only when a store answers

On mount, the section calls `GET /api/store/templates` with no query.
`not_configured` hides the section for everyone. An admin then sees one note
in the built-in section: "Connect a shared template catalogue in the Buildiq
setup." with a button that opens the setup wizard at step `store`.
Non-admins see nothing.

Hiding on `not_configured` keeps an instance without a store exactly as it is
today, which the existing "No-registry fallback" requirement asks for.

## D2. Search

The search field debounces 300 ms and calls `GET /api/store/templates?q=...`.
An empty result shows `NcEmptyContent` "No templates in the catalogue match
your search". The category filter of the view (`selectedTemplateCategory`)
narrows the returned cards client-side, like it narrows GitHub cards.

## D3. Errors

`store_unreachable` and `store_invalid_response` show an `NcNoteCard`
(warning) inside the section: "The shared catalogue could not be reached. Try
again later." No detail, no URL. Built-in templates and GitHub apps stay
usable.

## D4. Install

"Install" opens `CloneTemplateDialog` in store mode. On success the dialog
already redirects to the new app. A 404 `template_not_found` shows in the
dialog as "This template is no longer in the catalogue." and refreshes the
section.

## D5. The host name

The browser never gets the registry URL (`openbuild-remote-template-store`).
The lead line needs the host only, so `search()` adds `source: <host>` to its
response, the host part of `registry_url` without scheme, path or token.

## ADRs

- ADR-022: the catalogue is OpenRegister's `GenericStoreService`; buildiq adds
  a page section, no new store code.
- ADR-004: the section is a component, the dialog stays in `src/modals/`, the
  search field is an `NcTextField` with a visible label.
