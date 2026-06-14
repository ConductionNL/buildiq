## Context

A virtual app's objects often need municipal *handling*: intake → assessment → decision, with assignment, deadlines, and an audit trail. That machinery is Procest's whole job, exposed as ZGW-standard APIs (ZTC for case types, ZRC for cases/statuses) under `/apps/procest/api/zgw/...`. Rebuilding any of it in openbuild — even a thin "task" schema — would violate the leaves-not-app-schemas rule and ADR-022. The right shape is an *attachment*: the virtual app declares "objects of schema X are handled as cases of type Y", openbuild starts the case and shows its progress, and every handling action happens in Procest behind a deep link.

The integration must survive three awkward realities: Procest may be absent (degrade, don't break); case start may fail after the object already exists (never roll back user data); and the user viewing the object may lack rights on the case (distinguish "no access" from "no case").

## Goals / Non-Goals

**Goals:**

- Declarative, manifest-carried attachment of a Procest case type to a virtual-app schema.
- Automatic case start on object create, with a durable bidirectional link (object → case via `linkProperty`; case → object via a ZGW `kenmerken` entry).
- Read-only case status/history surfaced on the object's detail page, with a deep link into Procest for handling.
- Strict consumption of Procest's **existing public API surface**; anything missing is a filed dependency, not an assumption.
- Graceful absence of Procest at design time and runtime.

**Non-Goals:**

- **Case handling UI in openbuild** — no status transitions, assignments, document handling, or decisions; the deep link exists precisely so handling stays in Procest.
- **Workflow/case-type design** — authored in Procest; the attach picker lists published case types only.
- **Triggers beyond on-create** — update-condition triggers and manual start buttons are OQ-1 (v2).
- **Status push-back / mirroring into the OR object** — OQ-2; v1 reads live.
- **zaakeigenschappen field mapping** — OQ-3 (v2).

## Decisions

### Decision 1 — Attachment lives in the manifest `runtime` block as `workflows[]`, keyed by schema

`runtime.workflows[] = { id, schema, caseTypeUuid, caseTypeName, trigger: "on-create", linkProperty, descriptionTemplate? }`, max one attachment per schema in v1.

**Rationale**: the attachment is an app-composition concern (like pages and menu), not a data concern — it belongs in the manifest the builder ships, versions, and exports, not in mutable app config. Keying by schema (not by page) means *every* create path for that schema starts a case — index-page "Add" modals and form pages behave identically. The `runtime` block is the v2 manifest's app-level configuration home; the addition rides app-side validation until the canonical-schema follow-up lands (same pattern as `openconnector-api-sources` Decision 1).

**Alternatives considered**:
- *Schema-level `x-openbuild-workflow` extension on the OR schema* — rejected: OR schemas are shared/promotable artifacts; polluting them with a presentation-app's integration config couples layers and survives outside the manifest's versioning story.
- *Per-form-page config* — rejected: trivially bypassed by any other create path for the same schema; produces objects with and without cases.

### Decision 2 — Frontend-direct ZGW calls; case start from the create flow; no openbuild PHP

`useProcestCase` runs in the browser against `/apps/procest/api/zgw/...` with the NC session, immediately after the OR object create succeeds.

**Rationale**: mirrors the sibling change's Decision 2 (a PHP forwarder would be a redundant pass-through and a second auth surface). The ordering (object first, case second) makes the object the source of truth and the case start an *enhancement* — consistent with Decision 4's no-rollback rule. A server-side OR event listener was considered for robustness but rejected for v1: openbuild has no backend hook into OR object creation today, and adding one creates exactly the cross-app PHP coupling this change avoids. Trade-off acknowledged: objects created outside the virtual-app UI (raw OR REST, imports) do not auto-start cases in v1; the `_zoek`-based reconcile affordance (Decision 4) covers repair, and a server-side listener is the natural v2 if this bites.

### Decision 3 — Bidirectional link: `linkProperty` on the object + `kenmerken` on the case

The created case stores `kenmerken: [{ kenmerk: "<objectUuid>", bron: "openbuild:<appSlug>:<schema>" }]`; the object stores the case URL + UUID in its `linkProperty`.

**Rationale**: the object-side link makes the detail panel a single GET; the case-side kenmerk makes the link recoverable from Procest's side (`POST /zaken/_zoek` by kenmerk) when the object write-back failed mid-flight, and is the standard ZGW way to reference an external subject. The `bron` namespace prevents collisions with other kenmerk producers.

### Decision 4 — Case-start failure never blocks or rolls back object creation

If the ZRC POST fails: the object stands, the user sees a non-blocking warning ("saved, but the case could not be started"), and the detail panel shows a "Start case now" retry that first runs `_zoek` by kenmerk (to catch the half-completed case whose write-back failed) before creating anew.

**Rationale**: citizen data loss is strictly worse than a missing case; the reconcile-before-recreate step makes the retry idempotent in the common failure mode (case created, link write-back lost).

### Decision 5 — Status panel is a registered runtime component, referencable as a detail sidebar tab

`ProcestCaseStatusPanel` registers under the name `procest-case-status` in the virtual-app runtime's component registry and is referenced from `sidebarProps.tabs` (the existing open-enum tab mechanism) or as a detail widget. The attach dialog offers a one-click "add status tab to the detail page" convenience that injects the tab into the manifest.

**Rationale**: reuses the detail page's existing extension point (REQ-OBPD-005's tab model) instead of inventing a parallel slot; the ADR-036 resolver accepts non-page registry components for tabs/widgets.

### Decision 6 — Tasks block is conditional on a flagged Procest API

ZGW ZRC has no `taken` resource and Procest publishes no per-case task-list route today. The panel ships status + history only; the tasks block renders only when the (to-be-filed) Procest endpoint is detected via a feature probe, and the dependency is tracked as an explicit issue.

**Rationale**: per the no-invented-API rule — agents assuming endpoints that don't exist is a known failure mode; the spec encodes the gap instead of hiding it.

## Risks / Trade-offs

- **Client-side case start can be skipped** (non-UI object creation, browser crash mid-flight): accepted for v1; mitigated by `_zoek` reconcile + the retry affordance; server-side listener is the documented v2 path.
- **Deep-link route fragility**: Procest's frontend routes are not a published contract. Mitigated by a single `buildProcestCaseUrl()` helper and a Playwright assertion that the link lands on the case (catches drift in CI against the dev instance).
- **Authorization mismatch**: the form submitter may create the object but lack ZRC create rights in Procest. Surfaces as the Decision-4 warning path; the attach dialog documents that the case is started *as the submitting user* so app owners can arrange Procest permissions accordingly.
- **ZGW payload strictness**: ZRC create requires fields like `bronorganisatie`/`verantwoordelijkeOrganisatie`; defaults come from the attachment's case type + app settings, verified against the deployed Procest during apply (Newman pins the contract).
