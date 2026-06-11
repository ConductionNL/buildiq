---
kind: code
depends_on: []
chain:
  - save-as-template
---

## Why

Every serious no-code builder (Retool, Appsmith, Budibase, Glide) closes the template loop in both directions: start *from* a template, and turn *your* app *into* one. OpenBuild only has the first half. The template catalogue (`openbuild-template-catalogue`) ships four Conduction-curated seeds, a filterable gallery, and a one-click clone endpoint — but a citizen developer who builds a great kapvergunning app has no way to hand it to the neighbouring team as a starting point. The gap is not hypothetical leftover scope: the original chain was named "templates-**marketplace**", REQ-OBTC-008 explicitly defers "org-local user-submitted templates (`isSeeded: false`)... to a follow-up" that never landed, and the 2026-06-11 feature re-evaluation ranks shipping it as recommendation #3 ("cheapest high-leverage expected-gap... completes the marketplace loop and directly multiplies citizen-developer output").

Everything hard already exists:

- **`ApplicationTemplate` schema** (REQ-OBTC-001) already models user templates: `isSeeded: false` is in the contract, plus `manifest`, `companionSchemas[]`, `category`, `version`, org scoping.
- **The clone flow** (`POST /api/applications/from-template/{templateSlug}`, REQ-OBTC-004/005) reads any `ApplicationTemplate` record by slug and namespaces companion schemas under the new app — it works for user templates without modification.
- **The gallery** (REQ-OBTC-003) lists every template visible via OR REST; org-local records appear as soon as they exist.

What's missing is purely the authoring direction: capture a virtual app's manifest + schemas as a template record, de-namespace the companion schemas (the exact inverse of the clone's REQ-OBTC-005 prefixing), and let owners manage their org-local templates in the gallery. All of it is OR object CRUD plus frontend logic — zero new PHP, the purest ADR-022 change in this wave.

## What Changes

- **NEW** `src/dialogs/SaveAsTemplateDialog.vue` — builder UI (standalone dialog per the modal-isolation rule) opened from a "Save as template" action on the application-detail surface: metadata form (title, slug auto-suggested, description, useCase, category from the REQ-OBTC-001 enum, optional sourceUrl), a summary of what will be captured (manifest + N companion schemas), and a validation gate — the captured manifest MUST pass the canonical `validateManifest` before the template can be saved.
- **NEW** `src/services/templateCapture.js` — capture logic: deep-copies the app's current manifest, collects the app's schemas as `companionSchemas[]`, **de-namespaces** them (strips the `<appSlug>-` prefix from schema slugs and rewrites every manifest reference to the unprefixed slug — the exact inverse of clone-time REQ-OBTC-005), sets `isSeeded: false` and `version` from the source app's current version, and creates the `ApplicationTemplate` via standard OR REST (`useObjectStore` — no new controller).
- **NEW** Update-in-place flow: saving onto an existing org-local template slug the user may edit offers "update template" (replace manifest/companions, bump version) versus "pick a new slug"; seeded slugs are always rejected.
- **MODIFIED** Template gallery — org-local templates (`isSeeded: false`) render with an "Organisation template" badge and, for users with edit rights on the record, Edit-metadata and Delete actions (with confirm). Seeded templates remain read-only exactly per REQ-OBTC-008; the clone flow is untouched.
- **NEW** Round-trip guarantee: save-as-template followed by clone-from-template yields a working app — pinned by an e2e test (the de-namespace/re-namespace pair must compose to a consistent rename).
- **NO** new PHP routes, controllers, or services; **NO** changes to the clone endpoint, the seed repair step, or the `ApplicationTemplate` schema (the existing contract already covers user templates); **NO** cross-instance sharing (see Open Questions).

### Capabilities

#### New Capabilities

- `save-as-template`: the SaveAsTemplateDialog with validation gate, the templateCapture de-namespacing service, the update-in-place flow, and org-local template management in the gallery.

#### Modified Capabilities

- `openbuild-template-catalogue`: the gallery gains the org-local badge + owner Edit/Delete actions; REQ-OBTC-008's deferral is fulfilled — its seeded read-only constraint is preserved verbatim. Clone behaviour is unchanged.

## Impact

- **New frontend code**: ~800 LOC (dialog ~300, capture service ~250, gallery management ~150, validation wiring ~100) + Vitest suites. **Zero new PHP** — template create/update/delete are OR REST object operations under OR's standard RBAC and org scoping; the clone path reuses the existing `from-template` endpoint untouched.
- **Data**: only `ApplicationTemplate` records with `isSeeded: false`, org-scoped by OR's standard `organisation` field (REQ-OBTC-001), so templates are visible exactly to the org that authored them. No template data leaves the instance.
- **Security**: capture copies only the app's own manifest + schema definitions — never object data/rows (a template is a definition, not a dataset). Who may save a template for an app is gated by the existing openbuild-rbac surface (editor/owner on the Application); who may edit/delete a template record is OR's object RBAC; seeded records stay UI-read-only per REQ-OBTC-008.
- **Dependencies / flagged follow-ups**: none external — this change composes existing openbuild + OR surfaces only. (Cross-instance template transport is deliberately out of scope; see OQ-1.)
- **Interaction with sibling changes**: manifests captured into templates may carry the new `runtime.workflows[]` / `runtime.documents[]` / `runtime.theme` blocks; capture copies them verbatim and the validation gate tolerates them via `additionalProperties: true` — one scenario pins this.

## Open Questions

- **OQ-1**: Cross-instance template sharing (export a template as a portable file / publish to a Conduction-hosted community catalogue) — deferred; needs a transport format and trust/curation story. This change deliberately completes the *intra-instance* marketplace loop first.
- **OQ-2**: Screenshot capture for `screenshotUrl` (auto-render a page thumbnail at save time) — deferred to v2; v1 leaves the field empty (gallery already tolerates absent screenshots).
- **OQ-3**: Should "update template" offer a changelog/description-of-changes field surfaced in the gallery? Deferred; v1 bumps `version` silently per REQ-OBTC-007's one-shot semantics (clones are never affected either way).
