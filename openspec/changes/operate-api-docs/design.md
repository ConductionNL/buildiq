# Design: operate-api-docs

Read at buildiq development `d21e42f`, openregister development `ae898b0` and
`@conduction/nextcloud-vue` 2.57.1.

## Where it sits

- Document, openregister: `oas#generate` and `oas#generateAll`
  (`appinfo/routes.php:1670-1671`) on `OasController::generate()`
  (`lib/Controller/OasController.php:115`), public with an anonymous rate limit
  (line 114), built by `OasService::createOas()`.
- App detail, buildiq: the `VirtualAppDetail` page in `src/manifest.json` (line
  442) with `sidebarTabs` (lines 467-514); each tab names a component registered
  with `tab()` in `src/registry.js` (helper at line 141, entries at lines
  214-218).
- Version data: `src/services/appRegister.js` reads the register off the
  `ApplicationVersion` record; `Application.dataRegisters` lists bound registers
  (`lib/Settings/register.d/20-data-registers.json:7-36`).

## D1. Cut the document to what the app uses

A pure module, `src/services/appOpenApi.js`, takes the version's manifest and the
per-register documents and returns one OpenAPI 3 document:

- the schemas are those named by `pages[].config.schema` (and widget
  `content.schema`) in the version's manifest, in the version's register or a
  bound data register;
- `paths` and `components.schemas` are kept for those schemas only;
- `info.title` is the app name, `info.version` the version's `semver`, and
  `servers[0].url` this instance's OpenRegister API base.

"Download OpenAPI file" saves exactly this document, so what a maker reads and
what an integrator receives are the same.

## D2. Read inside Nextcloud, nothing fetched from outside

The tab renders the cut document with nextcloud-vue's reference component (sibling
half). It never opens an outside viewer. Until the component ships, the tab shows
the document as a structured list (schema, then its calls, then its fields) built
from the same cut, which is enough to read and to download.

## D3. Visible to whoever can open the app

The tab follows the detail page's own access: owners, editors and viewers of the
app. OpenRegister's document is public by design, so the tab adds no secret; it
adds scope and readability. It shows no credentials, and it explains sign-in with
an app password rather than printing one.

## Risks

- OpenRegister's document can change shape between releases. The cut only relies
  on `paths` and `components.schemas`, which are OpenAPI's own keys.
- A page bound to a connector (OpenConnector origin) has no OpenRegister schema.
  The tab lists it under "Not documented here" with the connector endpoint path.

## What it does not do

- It adds no API to built apps.
- It stores nothing; the document is built on request.
