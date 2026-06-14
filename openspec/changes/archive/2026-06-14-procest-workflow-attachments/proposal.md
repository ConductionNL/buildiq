---
kind: code
depends_on: []
chain:
  - procest-workflow-attachments
---

## Why

OpenBuild's app-store summary promises composing virtual apps from "registers, connectors, **workflows**, and documents"; the description names "Procest-processen" and the README says workflows are "consumed via workflow attachments". The 2026-06-11 feature re-evaluation found **zero spec and zero change coverage** for this — every grep hit on "workflow" in the repo is an unrelated use of the word. This is the second half of the highest-severity ecosystem-composition gap (the OpenConnector half is the sibling change `openconnector-api-sources`).

The concrete user story: a citizen developer builds a "Kapvergunning aanvragen" virtual app. Submissions are OR objects — but the municipality *handles* them as cases in Procest. Today the builder has no way to say "when an object of schema X is created, start a Procest case of case-type Y, and show the case's progress on the object's detail page". The handling itself (status transitions, assignments, documents, decisions) stays entirely in Procest; openbuild only attaches, starts, displays, and deep-links.

Procest already exposes the API surface this needs, as standard ZGW endpoints served by the app itself:

- **ZTC** `GET /apps/procest/api/zgw/catalogi/v1/zaaktypen` — list case types for the attach picker.
- **ZRC** `POST /apps/procest/api/zgw/zaken/v1/zaken` — create a case; `GET .../zaken/{uuid}` — case detail; `GET .../statussen?zaak=...` — status history; `POST .../zaken/_zoek` — find cases by kenmerk.

OpenBuild stays a pure API consumer of that existing public surface (ADR-022 posture: no duplication of case logic, no Procest internals). One genuinely missing piece — a public per-case **open-tasks list** for external consumers — is flagged below as an explicit Procest dependency rather than assumed.

## What Changes

- **NEW** Manifest v2 workflow-attachment declaration: a `workflows[]` array carried in the manifest's `runtime` block (the v2 manifest's app-level runtime configuration), each entry `{ id, schema, caseTypeUuid, caseTypeName, trigger: "on-create", linkProperty, descriptionTemplate? }`. Declarative only; validated app-side by openbuild's manifest validation layer (canonical-schema codification filed as a `nextcloud-vue` follow-up, same pattern as the sibling change).
- **NEW** `src/dialogs/WorkflowAttachmentDialog.vue` — builder UI (standalone dialog per the modal-isolation rule) to attach a Procest case type to a virtual-app schema: case-type picker fed by Procest's ZTC list endpoint, target-schema picker (the virtual app's own schemas), link-property picker (string/url property on that schema that will store the case reference, with one-click "add `zaakUrl` property for me" delegation to the schema designer flow).
- **MODIFIED** Schema-designer / application-detail surface — a "Workflows" section listing the app's attachments with add/edit/detach actions opening the dialog.
- **NEW** `src/composables/useProcestCase.js` — runtime integration: (a) after a create-mode form submit / object create on an attached schema, POST a zaak to Procest's ZRC with the case type, a `kenmerken` entry referencing the OR object UUID, and the rendered description; write the created case URL+UUID back onto the object's `linkProperty`; (b) fetch case detail + status history for display; failure-tolerant (object creation never rolls back because case start failed — warning + retry instead).
- **NEW** `src/components/runtime/ProcestCaseStatusPanel.vue` — a detail-page panel/sidebar-tab widget (registered in the virtual-app runtime's component registry, referencable from `sidebarProps.tabs` per the existing detail-page tab mechanism) rendering the linked case's identification, current status with statustype description, status history timeline, and the deep link of the next bullet. Renders an "open tasks" block ONLY once the flagged Procest tasks API exists (hidden otherwise).
- **NEW** Deep links into Procest for handling: "Open case in Procest" action on the status panel, targeting Procest's case view for the linked zaak UUID. The exact frontend route is verified against the deployed Procest during apply; the link MUST be built by one shared helper so a route change is a one-line fix.
- **NEW** Capability check + graceful absence: `"procest"` auto-managed in the manifest `dependencies[]` when ≥1 attachment exists; designer soft-check disables the attach action with a hint when Procest is absent; runtime degrades per-surface (panel shows an unavailable state; object create proceeds *without* case start, queued for retry) rather than blanking the app.
- **NO** new openbuild PHP controllers or routes; no Procest code changes inside this change (missing APIs are dependencies, not silent assumptions).

### Capabilities

#### New Capabilities

- `procest-workflow-attachments`: the manifest `workflows[]` declaration, the WorkflowAttachmentDialog builder UI, the `useProcestCase` runtime (case start on create + status read), the ProcestCaseStatusPanel with deep links, and capability-checked graceful absence.

#### Modified Capabilities

- `openbuild-page-designer`: the application-detail/designer surface gains the Workflows attachments section; detail pages gain the `procest-case-status` referencable panel. Existing flows untouched; everything is additive and hidden when no attachment exists.

## Impact

- **New frontend code**: ~1,100 LOC (dialog ~300, status panel ~300, composable ~250, attachments section ~150, validation ~100) + Vitest suites. Zero new PHP.
- **Integration contract (pinned to Procest's existing public surface)** — openbuild calls exactly:
  1. `GET /apps/procest/api/zgw/catalogi/v1/zaaktypen` (ZTC index) — attach picker; published case types only.
  2. `POST /apps/procest/api/zgw/zaken/v1/zaken` (ZRC create) — start a case on object create.
  3. `GET /apps/procest/api/zgw/zaken/v1/zaken/{uuid}` (ZRC show) — case detail for the panel.
  4. `GET /apps/procest/api/zgw/zaken/v1/statussen?zaak={zaakUrl}` (ZRC index, resource `statussen`) — status history.
  5. `POST /apps/procest/api/zgw/zaken/v1/zaken/_zoek` — reconcile/find a case by the object-UUID kenmerk (retry + repair path).
  All calls ride the caller's NC session; Procest's own authorization applies. Any payload-shape mismatch discovered during apply is fixed on the openbuild side or filed against Procest — never worked around by importing Procest internals.
- **Explicit Procest dependencies (flagged, NOT assumed)**:
  1. **Open-tasks list per case for external consumers** — ZGW ZRC has no native "taken" resource and no public Procest route for per-case task lists was found in `appinfo/routes.php`. The status panel's tasks block is therefore spec'd as conditional (REQ-PWA-004) and a Procest issue MUST be filed during apply requesting a stable read endpoint (e.g. `GET /api/zgw/zaken/v1/zaken/{uuid}/taken` or equivalent). v1 ships status-only.
  2. **Stable case deep-link route** — the canonical Procest frontend URL for "open this zaak" must be confirmed (and ideally documented by Procest); tracked in the same issue if undocumented.
- **Security**: no credentials stored; openbuild persists only case-type UUID/name in the manifest and the case URL/UUID on the OR object. Whether a given user may *see* the case remains Procest's call — a 403 on case fetch renders a "no access to the linked case" state, deliberately distinct from "no case linked".
- **No breaking changes** — purely additive; apps without attachments serialize byte-identical manifests.

## Open Questions

- **OQ-1**: Additional triggers (`on-update` when a field crosses a condition, manual "start case" action button) — deferred to v2; v1 ships `on-create` only.
- **OQ-2**: Should case status changes push back into the OR object (status mirror property) via Procest→OR notifications instead of read-on-render? Depends on the fleet notification engine's cross-app story; deferred — v1 reads live with short-TTL caching.
- **OQ-3**: Mapping form fields into ZGW `zaakeigenschappen` at case start (beyond the description template) — deferred to v2 to keep the v1 contract minimal.
