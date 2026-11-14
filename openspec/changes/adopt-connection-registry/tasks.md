# adopt-connection-registry tasks

## 1. Declare

- [x] 1.1 Write `lib/Settings/connections.json` with `store`, `github`, `documents` and `rule-webhooks`.
- [x] 1.2 Vendor integriq's schema in `tests/Fixtures/Integriq/` and guard the file in `tests/Unit/Settings/ConnectionsDeclarationTest.php`.
- [x] 1.3 Give the Template registry block the `section-store` id.

## 2. Page

- [x] 2.1 Add `src/manifest.d/80-connection-registry.json` with the page and its settings-gear menu entry.
- [x] 2.2 Add `src/services/connectionRegistry.js` with the two formatters and the Add integration handler.
- [x] 2.3 Wire the formatters and the handler in `src/App.vue`; register `PowerPlugOutline` in `src/icons.js`.
- [x] 2.4 Add the strings to `l10n/en` and `l10n/nl`.
- [x] 2.5 Cover it in `tests/vitest/connectionRegistry.spec.js`.

## 3. Reports and refresh

- [x] 3.1 Add `lib/Service/Connection/ConnectionReporter.php` and `ConnectionObservations.php`.
- [x] 3.2 Refresh from `SettingsService::updateSettings()`.
- [x] 3.3 Report from `StoreController`, `ShopController`, `GitHubSyncController`, `DocumentGenerationService` and `RuleActionDispatcher`.
- [x] 3.4 Add the integriq event stubs to `tests/Stubs`, `tests/bootstrap.php` and `psalm.xml`.
- [x] 3.5 Cover it in unit tests.

## 4. End to end

- [x] 4.1 Write `tests/e2e/integrations-page.spec.ts`.
- [x] 4.2 Install integriq in the CI `additional-apps`.

## 5. After integriq ships hydra#674

- [ ] 5.1 Run the e2e spec against an instance with both apps, then archive this change.
