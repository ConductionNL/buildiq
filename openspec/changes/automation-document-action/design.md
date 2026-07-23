## Context

Two capabilities already exist and this change composes them, adding nothing new to either's core mechanism. `docudesk-document-templates` gives OpenBuild a closed, Newman-pinned integration contract with Docudesk: exactly four routes, `POST /apps/docudesk/api/correspondence/generate` being the generation call, accepting `{templateId, dataRefs: [{register, schema, id}], options: {format, huisstijlId, caseReference}, filename}` and returning the rendered document bytes; `dataRefs` resolution (object fields → template variables) happens entirely inside Docudesk's own `DataResolverService` — OpenBuild has never needed to flatten object data itself and does not start now. `AutomationCompilerService` gives OpenBuild a proven pattern for a new listener-backed action kind (the `approval` action in the sibling `automation-approval-steps` change follows the identical shape: compile-time chain/config upsert + a trigger-fire listener).

Hard constraint carried over from `docudesk-document-templates` REQ-DDT-006: **"OpenBuild SHALL NOT import Docudesk PHP classes or read its tables"** and **"all calls use the caller's Nextcloud session."** An automation trigger fires from a background/event-listener context with no interactive browser session — there is no "caller" in the REQ-DDT-006 sense. This is the one real design problem this change solves; everything else is direct reuse.

## Goals / Non-Goals

**Goals:**
- Reuse the exact same Docudesk route (`correspondence/generate`) the manual generation path already uses — no new Docudesk-side contract to pin.
- Preserve REQ-DDT-006's "no Docudesk PHP class imports" guarantee unchanged — the automation path is an HTTP call, exactly like the manual path, just issued server-side instead of from the browser.
- Object-data-to-template-variable mapping is `dataRefs`, documented, not reimplemented.
- Graceful degradation mirrors the existing `docudesk-document-templates` REQ-DDT-005 pattern exactly (editor-disabled + compile-time exception), so there is exactly one "Docudesk absent" story across the whole app, not two.

**Non-Goals:**
- A new Docudesk-side API for background/service-account calls — out of this change's boundary; the impersonation approach (D1) needs no Docudesk-side change.
- Attaching non-Docudesk file types or documents from other sources — this action kind is Docudesk-templates only.
- `manual`/`schedule` trigger support for `generateDocument` — v1 scopes it to event/lifecycle-transition triggers only, mirroring `automation-approval-steps`' D1 for the identical reason (a schedule/manual trigger has no single bound object to generate a document *for*).

## Decisions

### D1 — The automation-triggered call impersonates the Application owner's NC session for the duration of one internal HTTP call
**Choice:** `DocumentGenerationService::generate()` resolves the Application's owner NC user, uses `IUserSession::setUser()` (or NC's equivalent background-job user-impersonation idiom) to make that user the active session for the duration of one internal HTTP call to `POST /apps/docudesk/api/correspondence/generate` (via `OCP\Http\Client\IClientService`, a loopback request — never a Docudesk PHP class import), then restores the previous session state (none, in a background-listener context).
**Why:** REQ-DDT-006 requires "the caller's Nextcloud session" — for a triggered background action, the only coherent "caller" is the Application owner who configured the automation and, by enabling it, authorized actions on their app's behalf (the identical authorization story `automation-approval-steps` D3 and `public-forms-runtime` D3 already use for their own owner-context server actions). The call remains a plain HTTP request to the one pinned route; REQ-DDT-006's "no PHP class imports" clause is satisfied unchanged.
**Alternative considered:** Import `OCA\DocuDesk\Service\DocumentService` / `CorrespondenceService` directly and call the PHP method in-process. Rejected outright — this is exactly what REQ-DDT-006 forbids, and doing it would silently break the Newman contract-pinning guarantee that catches Docudesk-side drift (an in-process call bypasses the controller entirely, so a Newman assertion against the HTTP contract would no longer protect this new caller).
**Alternative considered:** A dedicated Docudesk "service account" / API-token auth path. Rejected — no such primitive exists in Docudesk today; inventing one is an upstream ask outside this change's boundary (parallel to `automation-approval-steps`' decision not to ask OR for a new endpoint).

### D2 — `generateDocument` compiles to a trigger-fire listener, mirroring the `approval` action's shape
**Choice:** The compiler's `generateDocument` branch requires no compile-time upsert (unlike `approval`'s `ApprovalChain`) — there is no persistent Docudesk-side config to create. Compilation only validates the action's config (`templateId` exists, `output` is a known value) and registers the automation for trigger-fire dispatch; at fire time, a `DocumentGenerationListener` calls `DocumentGenerationService::generate()` with the fired object's `{register, schema, id}` as the single `dataRefs` entry.
**Why:** Matches Docudesk's own statelessness for this call (`correspondence/generate` has no server-side "job" to create ahead of time, unlike an `ApprovalChain`) — compilation only needs to validate, not provision.
**Alternative considered:** Pre-generate and cache a document at compile time. Rejected — nonsensical; the whole point is generating a document *for a specific object instance* at the moment it is created/transitioned, which does not exist yet at compile time.

### D3 — Output mode `attach` writes the returned bytes to Nextcloud Files and stores a file reference on the object; `download-link`/`notify` do not persist a file
**Choice:** `output: "attach"` calls `OCP\Files\IRootFolder` to write the returned document bytes into a per-Application documents folder, then sets a `fileId` reference on the triggering object's designated attachment field (the same `{ "ref": "<fileId>" }` shape `app-icon-management`/`ShareToken`'s `logoRef` already use for OR-attached files, per ADR-001). `output: "download-link"` generates a short-lived signed download URL surfaced via the automation's follow-up notification, without persisting a file. `output: "notify"` triggers a plain notification referencing that the document is available (paired with `attach` or `download-link` — `notify` alone with neither is rejected at editor-validation time as incomplete).
**Why:** "Attach to object as an NC file reference" (proposal scope) needs a real persisted file; giving citizen developers the lighter "just a download link" option avoids forcing Files storage growth on flows that only need a one-time download (e.g. immediate print), matching the proposal's explicit `output` enumeration.
**Alternative considered:** Always persist to Files regardless of output mode. Rejected — the proposal explicitly separates "attach" from "offer download" as distinct choices; conflating them removes a real, requested option.

### Declarative-vs-imperative decision (ADR-031)
The `generateDocument` action's config (`templateId`, `output`) is declarative, stored on the `Automation` object exactly like the other action kinds. The owner-impersonated HTTP call, the Files write, and the object file-reference update are imperative — justified under ADR-031's external-integration exception, the same justification `docudesk-document-templates`'s own manual-generation path already relies on for calling Docudesk (this change extends that existing precedent to a second caller shape, it does not establish a new exception category).

## Risks / Trade-offs

- **Owner impersonation runs with the owner's full NC permissions, not a narrower automation-specific grant** → acceptable and consistent — the owner explicitly enabled this automation (REQ-AUTD-008's owner-only-enable-on-production gate already requires owner-level trust for anything that fires unattended); no new privilege is created beyond what the owner's own session already has.
- **Docudesk changes/removes `correspondence/generate`'s contract** → caught by the SAME Newman collection `docudesk-document-templates` REQ-DDT-006 already runs — this change adds no second contract-pinning surface, it rides the existing one.
- **A background listener context may not cleanly support `IUserSession::setUser()`** → verified against NC's background-job user-impersonation idiom (used elsewhere for job-owner-scoped execution, e.g. `JobOwnerImpersonator.php` already present in OpenBuild's `lib/Service/`) — reuse that existing utility rather than inventing a new impersonation mechanism.
- **`attach` output on a high-volume trigger (e.g. every object update) could grow Files storage quickly** → out of scope for this change to rate-limit; documented as an operational consideration for the app owner, consistent with how OpenBuild treats other unrate-limited automation actions (notifications, webhooks) today.

## Migration Plan

1. Add the `generateDocument` action kind to the editor's action vocabulary and the compilation matrix (additive).
2. Implement `DocumentGenerationService` reusing `JobOwnerImpersonator` for the owner-impersonated internal HTTP call to the pinned `correspondence/generate` route.
3. Implement `DocumentGenerationListener` (trigger-fire dispatch) + the Files-attach / download-link / notify output-mode branches.
4. Add the template picker to `AutomationEditDialog`, reusing the existing Docudesk-template-list component from the Documents section.
5. Wire the same `useAppStatus('docudesk')` degradation the Documents section already uses into the automation editor's `generateDocument` option.
6. No migration for existing automations — `generateDocument` is a new, opt-in action kind.

**Rollback:** Remove the `generateDocument` matrix cell (compiler throws `UnsupportedAutomationCombinationException` again); remove the editor's template-picker action option. Docudesk and its existing manual-generation path are untouched throughout.

## Open Questions

- Exact NC background-job user-impersonation idiom to reuse — confirmed at implementation time against `JobOwnerImpersonator.php`'s existing usage (referenced as the precedent, not re-derived from scratch here).
- Should `download-link` URLs be time-limited, and if so for how long? Lean: short-lived (e.g. 24h), matching the general "don't leave a permanently public artifact behind" posture `public-forms-runtime`'s edit-link expiry already establishes as a project norm.

## Seed Data

Example `Automation` object with a `generateDocument` action:

```json
{
  "uuid": "00000000-0000-0000-0000-000000000000",
  "applicationSlug": "vergunning-app",
  "name": "Generate decision letter on approval",
  "enabled": true,
  "trigger": { "type": "lifecycle-transition", "schema": "permit-application", "transition": "approve" },
  "actions": [
    {
      "type": "generateDocument",
      "templateId": "00000000-0000-0000-0000-000000000000",
      "output": "attach"
    }
  ]
}
```

Resulting internal call to Docudesk (owner-impersonated):

```json
{
  "templateId": "00000000-0000-0000-0000-000000000000",
  "dataRefs": [
    { "register": "vergunning-app", "schema": "permit-application", "id": "00000000-0000-0000-0000-000000000000" }
  ],
  "options": { "format": "pdf" },
  "filename": "decision-letter-00000000-0000-0000-0000-000000000000.pdf"
}
```

Resulting file reference written to the triggering object:

```json
{
  "generatedDocument": { "ref": "00000000-0000-0000-0000-000000000000" }
}
```
