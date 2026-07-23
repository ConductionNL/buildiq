---
kind: code
---

## Why

`docudesk-document-templates` already lets an end user click a button to generate a Docudesk document for one object (Forms#103 17↑; vergunning/subsidie decision-letter flows are the canonical municipal case). What is missing is the *automated* half: generate the decision letter automatically when an object's status changes, with no human click. `AutomationCompilerService`'s v1 matrix has no action kind for this at all today. Budibase paywalls PDF generation; OpenBuild can ship it free by triggering the exact same, already-integration-tested Docudesk contract (`POST /apps/docudesk/api/correspondence/generate`) server-side instead of from a browser click.

## What Changes

- **New automation action kind `generateDocument`**: `templateId` (a Docudesk template, picked from the same `GET /apps/docudesk/api/templates` list `docudesk-document-templates`' builder UI already calls), `output` (`attach`|`download-link`|`notify` — attach as an NC file reference on the triggering object, generate a downloadable link surfaced in a follow-up notification, or both), compiled by `AutomationCompilerService` to a listener on the trigger's event, parallel to how `approval` (a sibling change) compiles to a listener.
- **`DocumentGenerationService`** (new): on trigger fire, calls Docudesk's existing `POST /apps/docudesk/api/correspondence/generate` — the **exact same pinned route** `docudesk-document-templates` REQ-DDT-006 already closes the integration surface to — with `dataRefs: [{register, schema, id}]` naming the triggering object (Docudesk's own `DataResolverService` flattens the object's fields into template variables; OpenBuild performs no flattening itself, matching the existing manual-generation contract exactly).
- **Server-side call needs an NC session; REQ-DDT-006 requires "the caller's session"**: because an automation fires with no interactive browser session, `DocumentGenerationService` issues the HTTP call impersonating the Application owner's NC user for the duration of that one internal call (mirrors `public-forms-runtime`'s owner-context write pattern) — **still an HTTP call to the one pinned route, never a Docudesk PHP class import**, preserving REQ-DDT-006's closed-contract guarantee unchanged in kind, only extended to a second caller shape (impersonated server-side vs. interactive browser).
- **Graceful degradation when Docudesk is absent**: mirrors `docudesk-document-templates` REQ-DDT-005 exactly — the `generateDocument` action is disabled in the automation editor (with the same missing-app hint pattern) when `useAppStatus('docudesk')` reports it absent, and the compiler throws `UnsupportedAutomationCombinationException` naming the missing dependency if a stale automation somehow reaches compile with Docudesk absent.
- **Template picker in `AutomationEditDialog`**: reuses the same `GET /apps/docudesk/api/templates` list the Documents-section builder UI already renders — one shared picker component, not a second implementation.
- **Object-data-to-template-variable mapping is `dataRefs`, not a new flattening layer**: documented explicitly (design.md) as reuse of Docudesk's existing `DataResolverService` resolution, identical to the manual-generation path — no new mapping code in OpenBuild.

## Capabilities

### New Capabilities
- `automation-document-action`: the `generateDocument` action kind, `DocumentGenerationService` (owner-impersonated internal call to the pinned Docudesk route), the attach/download-link/notify output modes, the template picker, and the graceful-degradation/compile-time-exception behaviour when Docudesk is absent.

### Modified Capabilities
- `automation-designer`: the editor's composable action vocabulary gains `generateDocument`; the fail-closed matrix gains the event/lifecycle-transition + `generateDocument` cell; the compilation matrix documents the new listener-backed branch. (Delta spec at `specs/automation-designer/spec.md`.)
- `docudesk-document-templates`: REQ-DDT-006's closed-contract statement ("all calls use the caller's Nextcloud session") is extended to name the automation-triggered, owner-impersonated call as a second, still-single-route caller shape — the pinned route list itself does not grow. (Delta spec at `specs/docudesk-document-templates/spec.md`.)

## Impact

- **Schema:** none in OpenBuild's own register beyond the existing `automation` schema's `actions[]` shape gaining the `generateDocument` action kind (no new top-level schema).
- **Backend:** new `DocumentGenerationService` (owner-impersonation + HTTP call to the pinned `correspondence/generate` route + NC-file attach via `OCP\Files\IRootFolder`); `AutomationCompilerService` gains a `generateDocument` compile branch; new listener dispatching at trigger-fire time, parallel to `automation-approval-steps`'s trigger-fire listener.
- **Frontend:** `AutomationEditDialog` gains the `generateDocument` action editor (template picker reusing the existing Docudesk-template-list component, output-mode select).
- **RBAC:** unaffected — follows the existing `automation-designer` REQ-AUTD-008 authoring/enable RBAC; the impersonated call carries only the permissions the Application owner already has via their own NC session, never elevated beyond that.
- **ADR-031 note:** document generation is an explicitly valid imperative exception (external integration) — consistent with `docudesk-document-templates`'s own existing imperative Docudesk-call pattern.
