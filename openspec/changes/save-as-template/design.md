## Context

The template catalogue was designed as half of a marketplace: REQ-OBTC-001 already reserves `isSeeded: false` for org-local templates, REQ-OBTC-008 defers their authoring flow to "a separate change", and the clone endpoint reads any `ApplicationTemplate` by slug. This change is that separate change. The design question is therefore not *whether* user templates fit the model — they were modelled in — but how to capture a live virtual app into the record shape such that the existing clone flow reproduces it faithfully.

The crux is naming symmetry. Clone-time REQ-OBTC-005 prefixes every companion schema slug with the new app's slug (`permit-application` → `my-permits-permit-application`) and rewrites manifest references accordingly. A captured app's schemas already carry *its* prefix. If capture stored them as-is, cloning "my-permits" into "vggm-permits" would produce `vggm-permits-my-permits-permit-application` — prefix stacking. Capture must therefore apply the exact inverse transform: strip the source app's prefix, store canonical unprefixed slugs, and let the clone re-prefix. Save→clone must compose to a clean rename.

## Goals / Non-Goals

**Goals:**

- One-dialog "Save as template" from the application-detail surface, gated on manifest validity.
- Faithful capture: manifest + companion schemas, de-namespaced so the existing clone flow round-trips correctly.
- Org-local template management in the gallery (badge, edit metadata, delete) without disturbing the seeded catalogue's read-only contract.
- Update-in-place for re-publishing an improved app over its own template slug.
- Zero new PHP; OR REST + existing clone endpoint only.

**Non-Goals:**

- **Cross-instance transport / community catalogue** — OQ-1; this change is intra-instance.
- **Object data in templates** — a template captures the app *definition*; rows are never copied (privacy + the template-as-blueprint semantics of the seeded four).
- **Back-propagation** — REQ-OBTC-007's one-shot clone semantics are preserved untouched in both directions.
- **Schema changes** — the `ApplicationTemplate` contract from REQ-OBTC-001 is sufficient; no new fields, no migration.
- **Automatic screenshots** — OQ-2.

## Decisions

### Decision 1 — Capture is a frontend service writing through OR REST; zero new PHP

`templateCapture.js` assembles the record (manifest deep-copy, de-namespaced companion schemas, metadata, `isSeeded: false`, `version` from the app) and creates/updates it via `useObjectStore` against the `ApplicationTemplate` schema.

**Rationale**: ADR-022 — the operation is plain object CRUD on data the builder's session can already read (their own app + schemas) and write (an org-scoped template record under OR RBAC). A PHP "TemplateService" would be a redundant pass-through (hydra gate-17 territory). The clone direction kept a ≤30 LOC controller only because it must *create schemas* with rewritten slugs atomically; the capture direction creates one object and needs no server-side atomicity (a failed save leaves no partial state — the record either exists or doesn't).

**Alternatives considered**:
- *Mirroring the clone's controller with a `to-template` endpoint* — rejected: nothing in the capture needs server authority; it would duplicate the frontend's manifest knowledge in PHP.

### Decision 2 — De-namespacing is the exact inverse of REQ-OBTC-005, with a strict-failure rule

Capture strips the leading `<appSlug>-` from every companion schema slug and rewrites all manifest references; any schema slug that does NOT carry the prefix is captured unchanged but flagged in the dialog summary; a resulting slug collision (two schemas de-namespacing to the same canonical slug) hard-blocks the save with a named error.

**Rationale**: round-trip correctness (save→clone = rename) is the whole contract; prefix stacking is the failure mode that silently breaks it. Apps built from templates always carry the prefix (the clone created them that way); hand-attached shared schemas may not — those are genuinely shared infrastructure, and capturing them unchanged (clone will prefix a *copy*) is the least surprising behaviour, surfaced transparently in the summary. Collisions are ambiguous by construction, so they fail loudly instead of guessing.

### Decision 3 — Validation gate before save: a template that won't clone can't be published

The dialog runs the canonical `validateManifest` (plus openbuild's app-side validation layer, including the sibling `runtime.*` blocks) against the *captured, de-namespaced* manifest and blocks Save on any error.

**Rationale**: mirrors REQ-OBTC-009's posture for seeded templates ("fail loudly rather than seeding a broken template") — user templates deserve the same guarantee, otherwise the gallery accumulates templates that explode at clone time for a different user, the worst possible failure owner. Validating the *de-namespaced* output (not the live manifest) checks exactly what clones will consume.

### Decision 4 — Slug collision resolves to update-in-place (own template) or rename; seeded slugs always rejected

Saving over an existing `isSeeded: false` template the user may edit offers "Update template" — replacing `manifest`/`companionSchemas` and bumping `version` (minor bump; the field is informational provenance per REQ-OBTC-007) — or picking a new slug. Colliding with an `isSeeded: true` slug is a hard error.

**Rationale**: re-publishing an improved app over its own template is the dominant repeat flow; forcing slug-proliferation (`my-permits-v2`, `-v3`) would litter the gallery. One-shot clone semantics make updates safe by construction — existing clones recorded `templateOrigin.version` and are never touched. Seeded-slug rejection keeps the curated namespace unambiguous and REQ-OBTC-008 intact.

### Decision 5 — Gallery management is additive and rights-driven; clone path untouched

Org-local templates get a badge plus Edit-metadata/Delete actions rendered only when OR reports the caller may write the record; seeded cards keep exactly the REQ-OBTC-008 read-only rendering; "Use this template" behaves identically for both kinds.

**Rationale**: the gallery already lists whatever OR returns, so user templates appear with zero list-side changes; gating management actions on OR's per-object answer (rather than an openbuild-local role model) keeps authorization single-sourced. Edit covers *metadata only* (title/description/useCase/category/sourceUrl) — content updates go through Decision 4's re-capture flow so the manifest and companions can never drift apart by hand-editing.

## Risks / Trade-offs

- **Stale templates** as apps evolve: a template is a snapshot; the source app moving on is by design (REQ-OBTC-007), but users may expect templates to track the app. Mitigated by the low-friction update-in-place flow; OQ-3 (changelog) if it bites.
- **Shared-schema capture surprise**: an app referencing genuinely shared schemas captures them as companions, so clones get independent *copies*. Surfaced explicitly in the dialog's capture summary; acceptable because the alternative (templates referencing live shared schemas) breaks the template's self-containedness and the clone contract.
- **Gallery clutter** in large orgs: many user templates could swamp the curated four. Mitigated today by the existing category filter + free-text search and the org-local badge; curation tooling is marketplace-v2 territory (OQ-1).
- **Concurrent update-in-place** by two editors: last-write-wins at the OR layer, version bump makes the race visible. Accepted for v1 (same posture as ordinary OR object edits).
