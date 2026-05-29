---
kind: mixed
depends_on: [bootstrap-openbuilt, openbuilt-page-editor, openbuilt-schema-editor]
chain:
  - bootstrap-openbuilt
  - openbuilt-page-editor
  - openbuilt-schema-editor
  - openbuilt-templates-marketplace  # THIS spec
---

## Why

Spec #1 of the OpenBuilt chain (`bootstrap-openbuilt`) ships a textarea
manifest editor and a single seeded `hello-world` Application. That is
enough for an integrator to prove the plumbing, but for the citizen
developers OpenBuilt actually targets — municipal department heads,
policy advisors, HR managers, social workers — the activation energy is
still too high. The user-stories captured in
`concurrentie-analyse/app-builder/README.md` (US-1 through US-5) describe
people who want to "create a permit tracking app", "build a stakeholder
consultation app", "compose an incident reporting app", "create an
employee onboarding app", "build a client intake app" — every one of
them starts not from a blank manifest but from a recognisable use case.
This spec ships the **template gallery** that turns those user-stories
into one-click starting points.

Crucially, this is the surface where OpenBuilt earns its market position
against Mendix, OutSystems, Budibase, Appsmith and ToolJet — every
low-code competitor in `app-builder/README.md` ships a starter-template
gallery on day one. OpenBuilt has visual editors (chain #4 + #5) and a
manifest contract (chain #1); the templates marketplace is the missing
"on-ramp" that closes the loop. Spec #8 is the right place in the chain
because the editors must exist first — "Use this template" is only
useful if the citizen developer can then customise the result, and
customisation lives in the page editor (#5) and schema editor (#4).

The marketplace also exercises a foundational architectural commitment:
templates themselves are OR objects (ADR-022 — consume OR, do not wrap
it), seeded declaratively (ADR-031 — declarative-first), and lifecycle-
scoped per organisation. Conduction-curated templates ship via the same
ADR-001 seed pattern that `SeedHelloWorld.php` already established in
chain spec #1.

## What Changes

- **NEW** OR schema `ApplicationTemplate` declared in
  `lib/Settings/openbuilt_register.json` — `{ uuid, slug, title,
  description, useCase, category, screenshotUrl?, manifest (JSON blob),
  companionSchemas (array of JSON-schema blobs), isSeeded, sourceUrl?,
  version }`. Includes `x-openregister-lifecycle` only insofar as OR's
  standard publish/archive applies — no bespoke state machine
  (ADR-031).
- **NEW** Repair step `lib/Repair/SeedApplicationTemplates.php` modelled
  on the existing `SeedHelloWorld.php` (chain #1). Idempotent: guarded
  by existing-slug per template. Seeds **four** Conduction-curated
  templates derived from the user-stories in
  `concurrentie-analyse/app-builder/README.md`:
  1. **Permit Tracker** — US-1, municipal building-permit workflow.
  2. **Stakeholder Consultation** — US-2, policy-advisor consultation
     with comment threads and document sharing.
  3. **Employee Onboarding** — US-4, HR checklist + document upload +
     approval workflow.
  4. **Incident Reporter** — US-3, safety-region field incident
     intake.
- **NEW** Frontend view `src/views/TemplateGallery.vue` — gallery
  layout showing each template's title, description, use case,
  category, and optional screenshot. Filterable by `category` and
  `useCase`.
- **NEW** Top-bar / left-nav entry "Templates" added to the OpenBuilt
  shell, plus a "Create from template" CTA on the empty Application
  list (the empty-state surfaces the gallery instead of the textarea
  editor).
- **NEW** PHP controller method
  `ApplicationsController::createFromTemplate(string $templateSlug,
  array $body): JSONResponse` — the **only** new code in this spec
  beyond the seed step. ~30 LOC. Reads the template, deep-copies its
  `manifest` and `companionSchemas` into a new Application owned by
  the calling user, slug-prefixes cloned schemas with the new
  Application's slug to avoid collisions, and returns the new
  Application's UUID. CRUD on templates themselves uses OR's REST
  directly (ADR-022).
- **NEW** Route in `appinfo/routes.php` —
  `POST /api/applications/from-template/{templateSlug}` →
  `applications#createFromTemplate` (`#[NoAdminRequired]`).
- **NEW** capability `openbuilt-template-catalogue` — the
  `ApplicationTemplate` schema, the four seeded templates, the gallery
  view, and the clone-from-template action.

### Capabilities

#### New Capabilities

- `openbuilt-template-catalogue`: The OR-backed template registry, the
  Conduction-curated seed (four starter packs derived from
  `concurrentie-analyse/app-builder` user-stories), the gallery UI, and
  the clone-into-new-Application action. Owns the citizen-developer
  on-ramp from "I want a permit-tracker" to "here is an editable
  draft Application that already has a permit-tracker manifest plus a
  permit schema".

#### Modified Capabilities

None. This is purely additive — `openbuilt-application-register` and
`openbuilt-runtime` from chain #1 remain unchanged; the new schema
declaration is appended to `openbuilt_register.json` without altering
existing schemas. The two visual-editor capabilities from chain #4 and
#5 (`openbuilt-page-editor` and `openbuilt-schema-editor`) are
consumed read-only as the post-clone landing surface.

## Impact

- **New code** — `lib/Controller/ApplicationsController.php` adds one
  method (`createFromTemplate`, ~30 LOC), `lib/Repair/SeedApplicationTemplates.php`
  (new file, follows the `SeedHelloWorld.php` pattern from chain #1),
  `lib/Settings/openbuilt_register.json` gets a new
  `ApplicationTemplate` schema entry plus four seed-data references,
  `appinfo/routes.php` gets one new route, `src/views/TemplateGallery.vue`
  is the only new SFC, and the existing left-nav of the OpenBuilt shell
  gets one new entry. Four template-manifest JSON fixtures live under
  `lib/Settings/templates/{slug}.json` so they can be human-reviewed
  diff-by-diff (rather than embedded as encoded strings in the repair
  step). Screenshot PNGs for the four seeded templates ship in
  `img/templates/{slug}.png` and are referenced via the standard
  Nextcloud static-asset path.
- **External dependency** — `@conduction/nextcloud-vue` for the
  gallery's `CnAppRoot` chrome; no new library dependency. The
  template manifests reference the canonical app-manifest schema
  pinned in `package.json` (ADR-024).
- **OpenRegister** — adds one new schema (`ApplicationTemplate`) to
  the existing `openbuilt` register namespace established by chain #1.
  No new register namespace. Multi-tenancy via the existing
  `organisation` field; templates are scoped per-organisation with an
  `isSeeded:true` flag for the Conduction-shipped four (so admins can
  identify the curated ones vs. org-local ones added in a follow-up
  spec).
- **No breaking changes** — the spec is additive on top of chain #1.
  No existing OR objects change shape.
- **Foundational ADRs honoured** — ADR-001 (every schema-introducing
  change ships seed data — four templates plus their companion
  schemas), ADR-016 (route in `routes.php`), ADR-022 (consume OR
  abstractions — templates are OR objects, no wrapper service),
  ADR-024 (template manifests validate against the canonical schema),
  ADR-031 (templates are declarative; the seed step is canonical),
  ADR-032 (kind: mixed with thin-glue exception — see `design.md` for
  the LOC accounting).

## Deferred Work

Tracked for follow-up specs / issues rather than absorbed into this
change:

- **Community-submitted templates** — v1 ships read-only,
  Conduction-curated only. The schema already carries `isSeeded` so
  a follow-up spec can introduce org-local user-submitted templates
  without a migration.
- **Publish-existing-app-as-template** — the inverse flow ("turn my
  permit-tracker Application into a template my org can reuse") is
  out of scope; deferred to a follow-up issue
  (`#openbuilt-template-publishing`).
- **Template versioning** — re-cloning an updated template against an
  existing instance is explicitly not supported (one-shot snapshot
  semantics). A future versioning spec can address propagating
  upstream improvements; for now, `ApplicationTemplate.version` is
  recorded on clone for traceability.
- **Files-API screenshot uploads** — screenshots in v1 ship in
  `img/templates/` committed to the repo. A follow-up spec can move
  these to OR Files for community-submitted templates so users do not
  need repo write access to add a screenshot.
