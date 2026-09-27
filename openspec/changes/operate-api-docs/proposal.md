---
kind: code
---

# Proposal: operate-api-docs

## Why

buildiq matrix, row `ops-api-docs`, "Get generated API documentation for each
built app.", rated `no`. No tender, featureRequest or roadmap row carries it. Two
competitors rated `yes`:

- NocoBase: "packages/plugins/@nocobase/plugin-api-doc/src/client-v2/plugin.tsx:18
  API documentation settings page rendering Swagger for every collection and
  plugin resource (each plugin ships src/swagger, for example
  packages/plugins/@nocobase/plugin-multi-app-manager/src/swagger/index.ts:1);
  builtIn packages/presets/nocobase/package.json:131".
- Mendix: "every published REST service is automatically documented"
  (https://docs.mendix.com/refguide/published-rest-services/).

Budibase and Power Apps are rated `partial`, and both fall short on the same
point: Budibase has "one static OpenAPI spec for the whole public API ...
nothing generates documentation of a specific app's tables and fields", and Power
Apps documents Dataverse "per environment, not generated per app".

What buildiq does today, per the matrix `built.evidence`: "grep for
OpenAPI/swagger/'api-docs'/apiDocs across lib/ and src/: the only hits
(lib/Service/SettingsService.php:296,367) are internal comments about
OpenAPI-SHAPED data structures ... not generated API documentation for a built
app."

The document itself already exists one layer down. OpenRegister generates an
OpenAPI document per register (`oas#generate`, `GET /api/registers/{id}/oas`,
`openregister/appinfo/routes.php:1670`), deliberately public ("OAS is API
documentation, not protected data", its `oas-generation` spec). A built app's data
lives in its version's register (`ApplicationVersion.register`) and in any bound
data registers. What is missing is the per-app view: the document cut to the
schemas the app uses, readable inside Nextcloud, and reachable from the app. Today
OpenRegister's own register sidebar opens it on an outside site,
`https://redocly.github.io/redoc/?url=...` (`src/sidebars/register/RegisterSideBar.vue:557`),
which cannot reach an instance that is not on the public internet.

## What changes

- The app detail page gets an "API" tab. For the selected version it shows the API
  of the app's data: each schema the app uses, its list, read, create, update and
  delete calls, and the fields with their types and rules.
- The document is cut from OpenRegister's per-register documents to the schemas the
  version's pages bind, across the version's own register and its data registers,
  and titled after the app.
- The tab names the base address, says how to sign in (an app password with basic
  authentication), and offers "Download OpenAPI file".

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | ops-api-docs | Get generated API documentation for each built app. | no | an API reference per app and version, cut to its schemas and readable in the builder |

## Existing work it builds on

- openregister `oas-generation` (spec, done): the per-register OpenAPI document.
- `openspec/specs/application-detail-ui`: the app detail page and its sidebar tabs,
  which gain "API".
- `openspec/specs/openbuild-exporter`: an exported app imports its register into
  OpenRegister on install, so the target instance generates the same document
  for it; the export needs no copy.
- `data-registers-runtime` (open, every task ticked): `Application.dataRegisters`,
  whose schemas count when a page binds them.

## Sibling halves

- nextcloud-vue owes the renderer. An OpenAPI reference that renders inside the
  Nextcloud page, with the nextcloud-vue look and keyboard access, and fetches
  nothing from outside the instance, is a shared component (`CnApiReference`, name
  to be settled there): hermiq, integriq and openregister's own register sidebar
  need the same thing. No nextcloud-vue component or open change for it exists at
  2.57.1 or development `c8aa858`. Buildiq's half is cutting the document and the
  tab.

## Out of scope

- A "try it" console that sends requests from the page.
- Documenting endpoints the app does not have: built apps expose no API of their
  own beyond OpenRegister's objects API.
- API keys or tokens for outside callers (integriq's, ADR-091).
