# Tasks: apps-unified-search-deep-links

## 1. Static entry
- [x] 1.1 `src/manifest.json`: set `deepLinks` to `[{"registerSlug": "buildiq", "schemaSlug": "built-app", "urlTemplate": "/apps/buildiq/applications/{uuid}", "displayName": "App"}]` (REQ-OBDL-002)

## 2. Built-app records
- [x] 2.1 `lib/Listener/BuiltAppDeepLinkListener.php` extends `OCA\OpenRegister\AppHost\Listener\GenericDeepLinkRegistrationListener` and overrides `loadDeepLinks()`: returns the manifest entries plus, for every published `built-app-route`, the production version's manifest (through `ManifestResolverService::resolve($slug, null, null)` without caller filtering) mapped to one entry per page of type `detail` that names `config.register` and `config.schema`; `urlTemplate` is `/apps/buildiq/builder/{slug}` plus the page route with its single `:param` replaced by `{uuid}`; a page without a `:param`, or with more than one, is skipped (REQ-OBDL-003)
- [x] 2.2 `lib/AppInfo/Application.php`: pass `'deepLinks' => false` to `Bootstrap::register` and register `BuiltAppDeepLinkListener` for `OCA\OpenRegister\Event\DeepLinkRegistrationEvent` by its string name inside the existing OpenRegister guard, so a missing OpenRegister still never fatals (REQ-OBDL-001)
- [x] 2.3 The register slug of a version is resolved the way `ApplicationCreationService` provisions it (`buildiq-{appSlug}-{versionSlug}`, or the version's stored register); reuse that code, do not rebuild the slug in the listener (REQ-OBDL-003)

## 3. Tests
- [x] 3.1 `tests/Unit/Listener/BuiltAppDeepLinkListenerTest.php`: an app with a production version holding an index page and a detail page `/zaken/:id` on schema `zaak` yields the entry `/apps/buildiq/builder/{slug}/zaken/{uuid}` for register `buildiq-{slug}-{version}`; a detail page without a parameter yields none; no published apps yields only the manifest entry; a non-matching event is ignored (REQ-OBDL-001, REQ-OBDL-002, REQ-OBDL-003)
- [x] 3.2 Live check on the dev instance: search a record title of the seeded hello-world app in the Nextcloud search bar and confirm the hit opens on the app's detail page; search an app name and confirm it opens on `/apps/buildiq/applications/{uuid}`
  > Live 25 Nov 2026 on Nextcloud 34.0.6 (MariaDB, `php -S` with a front-controller router so pretty URLs resolve), OpenRegister main 55f2aa3e plus openregister#11164 (without it `GET /ocs/v2.php/search/providers` answers 500 "Filter name is already used" and the search bar finds nothing), buildiq development with buildiq#1902, seeded by `tests/e2e/ci-seed.sh`. The `openregister_objects` search for `Hello World` gives the hit `Hello World` -> `/apps/buildiq/applications/<uuid>`; for `Edit me` the seeded message -> `/apps/buildiq/builder/hello-world/messages/<uuid>`. Opened in Chromium both land on their page: the app's detail page, and the message's detail page (title `Edit me` with its body). Only a published app links its records: the fixture is seeded as a draft, and its hits open in OpenRegister until it is published (`POST /api/applications/{uuid}/publish`), as REQ-OBDL-003 says.

## 4. Close out
- [x] 4.1 Flip row `apps-unified-search` to `built` in `openspec/parity/capabilities.json` with the listener and manifest as evidence, archive this change, and set `status: done` on `specs/deep-link-registration` again
