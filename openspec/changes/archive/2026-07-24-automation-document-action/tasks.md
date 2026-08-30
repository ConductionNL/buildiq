## 1. Compiler: generateDocument action kind

- [x] 1.1 Extend the v1 matrix: `object-created|object-updated|object-deleted|lifecycle-transition` + `generateDocument` is supported; `schedule`/`manual` + `generateDocument` stay fail-closed.
- [x] 1.2 Compile-time validation only (no Docudesk-side artifact to upsert): `templateId` present, `output` a known value, `notify`-only rejected as incomplete.
- [x] 1.3 Throw `UnsupportedAutomationCombinationException` naming the missing `docudesk` dependency when Docudesk is absent at compile time.

## 2. DocumentGenerationService

- [x] 2.1 Owner-impersonated internal HTTP call to `POST /apps/docudesk/api/correspondence/generate`, reusing `JobOwnerImpersonator` — no `OCA\DocuDesk\*` class import anywhere in the call path.
- [x] 2.2 `dataRefs` carries exactly the triggering object's `{register, schema, id}` — no field flattening in OpenBuild.
- [x] 2.3 `attach` output: write bytes to Nextcloud Files via `IRootFolder`, set `{ "ref": "<fileId>" }` on the object's attachment field.
- [x] 2.4 `download-link` output: short-lived signed URL, no persisted file.
- [x] 2.5 `notify` output: dispatches a notification referencing the generated document (paired with attach/download-link).

## 3. Trigger-fire dispatch

- [x] 3.1 `DocumentGenerationListener` on the trigger's event calls `DocumentGenerationService::generate()`.

## 4. Editor UI

- [x] 4.1 `AutomationEditDialog` gains the `generateDocument` action type: template picker reusing the existing Documents-section component, output-mode select.
- [x] 4.2 Action disabled with the missing-app hint when `useAppStatus('docudesk')` reports Docudesk absent (mirrors REQ-DDT-005).
- [x] 4.3 Fail-closed validation blocks `generateDocument` on `schedule`/`manual` triggers.

## 5. Contract pinning

- [x] 5.1 Extend the existing Newman collection (`openbuild-docudesk-documents.postman_collection.json`) with a scenario asserting the automation-triggered call target is the same pinned `correspondence/generate` route, owner-impersonated.
- [x] 5.2 Source-tree scan assertion: no `OCA\DocuDesk\*` import exists anywhere in `DocumentGenerationService` or the listener.

## 6. Tests

- [x] 6.1 PHPUnit: compiler generateDocument branch (validation, fail-closed matrix, missing-dependency exception), `DocumentGenerationService` (impersonation, attach/download-link/notify branches).
- [x] 6.2 Playwright: compose a document-generation automation on a lifecycle transition, trigger it, confirm the generated document is attached to the object as a file reference. (Test file written; CI-run only per project policy — not executed against the shared dev instance in this session, live trigger-fire covered by the PHPUnit listener/service tests.)

## 7. Verify

- [x] 7.1 `composer check:strict` and hydra mechanical gates (spec-coverage, no-phantom-cross-app-rpc, controller-exception-translation) green on the diff.
- [x] 7.2 `openspec validate "automation-document-action"` passes and `openspec status` shows all artifacts complete before archiving.
