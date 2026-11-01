# Tasks: platform-properties

## 1. Tags
- [ ] 1.1 Add `@spec openspec/specs/platform-properties/spec.md#REQ-PLAT-002` to the class docblock of `lib/Service/CopilotService.php`
- [ ] 1.2 Add `#REQ-PLAT-003`, `#REQ-PLAT-004` and `#REQ-PLAT-012` to the class docblock of `lib/Controller/DashboardController.php`, and `#REQ-PLAT-003` to `lib/Controller/GeneratedDocumentController.php` beside its `#[PublicPage]` explanation
- [ ] 1.3 Add an XML comment `<!-- @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-007, #REQ-PLAT-009 -->` above `<licence>` in `appinfo/info.xml`
- [ ] 1.4 Add `#REQ-PLAT-008` to `src/views/BuilderHost.vue` (the nested CnAppRoot mount)

## 2. Checks that keep the properties true
- [ ] 2.1 `tests/Unit/Platform/RouteProtectionTest.php`: reflects every controller method routed in `appinfo/routes.php`; asserts none carries `#[PublicPage]` except `GeneratedDocumentController::download` (allow-listed with its token check), and that `#[NoCSRFRequired]` appears only on methods routed with verb GET (REQ-PLAT-003, REQ-PLAT-004, REQ-PLAT-012)
- [ ] 2.2 `tests/Unit/Platform/AppInfoPropertiesTest.php`: reads `appinfo/info.xml` and `LICENSE`; asserts `<licence>EUPL-1.2</licence>`, that `LICENSE` is the EUPL-1.2 text, and that no file under `lib/` contains a seat or licence-key check (`maxUsers`, `seatCount`, `licenseKey`, `licenceKey`) (REQ-PLAT-007, REQ-PLAT-009)
- [ ] 2.3 Same test: asserts no file under `lib/Service/` names a model vendor client or key (`openai`, `anthropic`, `api_key`, `apiKey` for a model) and that `CopilotService` resolves `OCP\TaskProcessing\IManager` (REQ-PLAT-002)
- [ ] 2.4 `src/services/manifestValidation/labels.js` (a new rule beside `formLogic.js`): report an action, widget or form field with an empty label as a validation warning in the page designer, with a vitest spec (REQ-PLAT-005)

## 3. Scale-out gap
- [ ] 3.1 `lib/Service/ExportService.php` stages exports under `sys_get_temp_dir()` and `lib/BackgroundJob/CleanupExpiredExports.php` purges that local path: copy the finished ZIP into `IAppData` (`exports/{jobId}.zip`) when staging ends, serve the download from there in `lib/Controller/ExportsController.php`, and purge both places in the cleanup job, so a download on another web server finds the file (REQ-PLAT-010)
- [ ] 3.2 Unit test: the download reads from `IAppData` when the local staging dir is gone (REQ-PLAT-010)

## 4. Matrix
- [ ] 4.1 When the tasks are done, archive this change and set `built.spec` of the twelve rows in `openspec/parity/capabilities.json` to `openspec/specs/platform-properties`
