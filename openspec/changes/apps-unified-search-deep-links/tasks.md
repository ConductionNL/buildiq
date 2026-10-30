# Tasks: apps-unified-search-deep-links

## 1. Static entry
- [ ] 1.1 `src/manifest.json`: set `deepLinks` to `[{"registerSlug": "buildiq", "schemaSlug": "built-app", "urlTemplate": "/apps/buildiq/applications/{uuid}", "displayName": "App"}]` (REQ-OBDL-002)

## 2. Built-app records
- [ ] 2.1 `lib/Listener/BuiltAppDeepLinkListener.php` extends `OCA\OpenRegister\AppHost\Listener\GenericDeepLinkRegistrationListener` and overrides `loadDeepLinks()`: returns the manifest entries plus, for every published `built-app-route`, the production version's manifest (through `ManifestResolverService::resolve($slug, null, null)` without caller filtering) mapped to one entry per page of type `detail` that names `config.register` and `config.schema`; `urlTemplate` is `/apps/buildiq/builder/{slug}` plus the page route with its single `:param` replaced by `{uuid}`; a page without a `:param`, or with more than one, is skipped (REQ-OBDL-003)
- [ ] 2.2 `lib/AppInfo/Application.php`: pass `'deepLinks' => false` to `Bootstrap::register` and register `BuiltAppDeepLinkListener` for `OCA\OpenRegister\Event\DeepLinkRegistrationEvent` by its string name inside the existing OpenRegister guard, so a missing OpenRegister still never fatals (REQ-OBDL-001)
- [ ] 2.3 The register slug of a version is resolved the way `ApplicationCreationService` provisions it (`buildiq-{appSlug}-{versionSlug}`, or the version's stored register); reuse that code, do not rebuild the slug in the listener (REQ-OBDL-003)

## 3. Tests
- [ ] 3.1 `tests/Unit/Listener/BuiltAppDeepLinkListenerTest.php`: an app with a production version holding an index page and a detail page `/zaken/:id` on schema `zaak` yields the entry `/apps/buildiq/builder/{slug}/zaken/{uuid}` for register `buildiq-{slug}-{version}`; a detail page without a parameter yields none; no published apps yields only the manifest entry; a non-matching event is ignored (REQ-OBDL-001, REQ-OBDL-002, REQ-OBDL-003)
- [ ] 3.2 Live check on the dev instance: search a record title of the seeded hello-world app in the Nextcloud search bar and confirm the hit opens on the app's detail page; search an app name and confirm it opens on `/apps/buildiq/applications/{uuid}`

## 4. Close out
- [ ] 4.1 Flip row `apps-unified-search` to `built` in `openspec/parity/capabilities.json` with the listener and manifest as evidence, archive this change, and set `status: done` on `specs/deep-link-registration` again
