## Context

This is spec #8 in the 9-spec OpenBuilt chain (per ADR-032), depending
on:

- **`bootstrap-openbuilt`** (#1) — provides the `openbuilt` register
  namespace, `Application` + `BuiltAppRoute` schemas, the
  nested-`CnAppRoot` runtime, and the canonical `SeedHelloWorld.php`
  pattern this spec replicates.
- **`openbuilt-page-editor`** (#5) — provides the visual page-level
  editor that the clone flow redirects into for customisation
  (REQ-OBTC-006).
- **`openbuilt-schema-editor`** (#4) — provides the visual schema
  editor a user reaches by navigating from the cloned Application to
  a cloned companion schema. Not strictly required by the clone flow
  itself, but the gallery experience is incomplete without the schema
  editor present because a cloned Application points at cloned
  schemas the user will want to edit.

The chain order matters because "Use this template" is only a useful
on-ramp if the user lands somewhere they can keep working. Chain #1's
textarea editor is a degraded fallback (REQ-OBTC-006); the page editor
is the intended landing surface.

The user-stories that motivate the four seeded templates live in
`concurrentie-analyse/app-builder/README.md` §"User Stories":

| Template | User story | Persona |
|---|---|---|
| `permit-tracker` | US-1 — "create a permit tracking app" | municipal department head |
| `stakeholder-consultation` | US-2 — "build a stakeholder consultation app" | policy advisor |
| `incident-reporter` | US-3 — "compose an incident reporting app" | safety-region team coordinator (e.g. VGGM) |
| `employee-onboarding` | US-4 — "create an employee onboarding app" | HR manager |

US-5 ("client intake app that prefills from BRP via OpenConnector") is
explicitly **not** seeded in this spec because it requires a working
OpenConnector source binding that the OpenBuilt manifest does not yet
express in v1.4.x — that template lands in a follow-up once the
manifest gains OpenConnector binding metadata.

## Goals / Non-Goals

**Goals**

- Ship the `ApplicationTemplate` OR schema in the existing `openbuilt`
  register namespace.
- Seed four Conduction-curated templates via a new
  `SeedApplicationTemplates.php` repair step that follows the
  `SeedHelloWorld.php` idempotent guard pattern.
- Ship a gallery view (`TemplateGallery.vue`) reachable from the
  OpenBuilt left-nav and from the empty-state of the Application list.
- Ship a clone action (`createFromTemplate`) that lands the user
  inside the page editor with a fully editable, namespaced copy of the
  template's manifest + companion schemas.
- Preserve traceability — every clone records `templateOrigin.slug` +
  `templateOrigin.version` so support staff can answer "which template
  did this app come from?" later.

**Non-Goals (deferred)**

- Community / user-submitted templates. The schema already carries
  `isSeeded` so the follow-up spec for community submissions does not
  require a migration.
- Publishing an existing Application as a template (the inverse
  flow). Deferred to a follow-up issue
  (`#openbuilt-template-publishing`).
- Template versioning / upgrade-from-template. Clones are one-shot
  snapshots (REQ-OBTC-007).
- Files-API screenshot uploads. Screenshots ship as static assets in
  `img/templates/` for v1 — a follow-up spec can route them through
  OR Files when community submissions arrive.
- US-5 (client intake app) — depends on manifest-level OpenConnector
  binding metadata not yet in v1.4.x; deferred to a follow-up.

## Decisions

### Decision 1 — Templates as OR objects, not static JSON files

**Decision**: `ApplicationTemplate` is an OR schema; templates are
records, not files.

**Why this matters**: a competing approach is to ship templates as
JSON files under `lib/Settings/templates/*.json` and have the gallery
read them directly off disk. That is tempting because (a) the seed
data is conceptually "fixtures", (b) it skips a schema declaration,
and (c) the gallery becomes a static asset.

We reject it because OpenBuilt's whole architectural commitment is
ADR-022: **consume OpenRegister, do not invent app-local stores**.
Treating templates as files would create a second source of truth
that does not get RBAC, audit, GraphQL, MCP, CloudEvents, or any of
the things every other Conduction app gets for free by virtue of
being on OR. The marginal cost of declaring one more schema is small;
the marginal cost of building an app-local file-based registry — and
then watching the community-submission spec migrate it to OR — is
large.

This decision also keeps the **clone semantics consistent**: the
clone action is "read one OR object, write another OR object", which
is the canonical OR operation. If templates were files, the clone
action would be heterogenous (read-file, write-OR-object), making
the chain harder to reason about.

**Storage trade-off**: the four seed template manifests are still
human-readable JSON in the repo (under `lib/Settings/templates/{slug}.json`)
because they are non-trivial to review embedded in a PHP repair step.
The repair step loads them from disk at install time and writes them
into OR — the file is the **source**, OR is the **runtime**. This
mirrors the pattern `SeedHelloWorld.php` uses for its
`hello-message` sample objects.

**Alternatives considered**

- *Static JSON gallery, no OR schema*. Rejected per the ADR-022 logic
  above. Also makes future "edit a template in the UI" impossible
  without a migration.
- *Hybrid — seed templates as OR objects, but read them through a new
  `TemplateService` PHP class*. Rejected per ADR-022 (no wrapper
  services) and ADR-031 (declarative-first). The gallery reads OR's
  REST directly.

### Decision 2 — Per-org namespace with `isSeeded` flag

**Decision**: templates live per-organisation, scoped via OR's
standard `organisation` field. The four Conduction-curated templates
ship with `isSeeded: true` and are seeded into each organisation that
installs OpenBuilt.

**Why this matters**: the alternative is a single "global" Conduction
namespace that every org reads from. That sounds simpler but has two
problems:

1. **Cross-org isolation** — when chain spec #7 (`openbuilt-rbac`)
   lands per-built-app permissions, the global namespace becomes a
   special case that has to be threaded through every RBAC check.
   Per-org isolation matches every other OR record in OpenBuilt and
   uses no special-case code.
2. **Future org-local templates** — the community-submission follow-up
   (deferred) will want org-local templates that admins can curate
   for their staff. Per-org from day one means the schema does not
   need a migration when that arrives.

The `isSeeded: true` flag is the **only** distinction between
Conduction-curated and org-local templates. The gallery treats
`isSeeded: true` templates as read-only in the UI (REQ-OBTC-008) so a
distracted admin cannot accidentally delete `permit-tracker` from
their org. Backend deletion via OR REST is still governed by OR's
standard RBAC and is intentionally not blocked at the schema level —
this is a UI affordance, not an authorisation rule.

**Operational consequence**: when an org installs OpenBuilt, the seed
step iterates over every existing organisation in the system and
seeds the four curated templates into each. Newly-created
organisations get seeded on their next OpenBuilt repair-step run
(typically the next deploy). The repair step's idempotency guard
(per-slug existence check, per-org scope) keeps this safe.

**Alternatives considered**

- *Single global org "openbuilt" hosting the curated templates*.
  Rejected per the RBAC and migration arguments above.
- *Per-user templates, not per-org*. Rejected — collaboration breaks
  if Alice's templates are invisible to her colleague Bob in the same
  organisation. OR's organisation scope is the right grain.

### Decision 3 — Slug-prefix the cloned companion schemas

**Decision**: on clone, the new Application's `slug` is joined by a
hyphen to each cloned companion-schema's `slug`. Example:
`permit-tracker` template → cloned into Application
`slug: my-permits` → the `permit-application` companion schema is
cloned as `slug: my-permits-permit-application`. The cloned manifest's
page-config `schema` references are rewritten to match.

**Why this matters**: without a prefix, two clones of the same
template into the same organisation collide on the schema slug. With
a UUID-suffix prefix (e.g. `permit-application-{8-char-uuid}`) the
slug becomes user-hostile in the schema-editor URL. With the
Application's slug as the prefix, the schema slug stays human-readable
and unambiguously identifies its owning Application.

**Trade-off**: schema slugs become long (`my-permits-permit-application`
is 30 characters). OR's schema-slug column is generously sized, so
this is fine technically; the readability hit is minor and the
naming convention is consistent.

**Risk**: if the user changes the new Application's `slug` after
clone, the companion-schema slugs do not auto-rename. We accept this
— renaming an Application is a rare operation and Cascade renames
across schemas + manifest references introduce complexity out of
proportion to the benefit. Document it in the
`docs/integrator-guide.md` update.

**Alternatives considered**

- *No prefix; reject the clone if a schema-slug collides*. Rejected
  because it makes "clone twice" a confusing UX.
- *UUID-suffix the cloned schemas*. Rejected for the readability hit.
- *Per-application sub-namespace in OR (e.g. register
  `openbuilt-{app-slug}`)*. Rejected because it explodes the register
  namespace count (one per virtual app) without a clear benefit, and
  chain spec #1's `BuiltAppRoute` already gives us the per-app
  routing layer.

### Decision 4 — Screenshots committed to repo for v1, Files API in follow-up

**Decision**: the four seeded templates' screenshots ship as PNGs in
`img/templates/{slug}.png`, served via Nextcloud's standard
`apps/openbuilt/img/templates/{slug}.png` static-asset path.
`screenshotUrl` on a seeded template stores a relative path
(`img/templates/permit-tracker.png`); the gallery resolves it via
Nextcloud's `OC.imagePath('openbuilt', 'templates/permit-tracker.png')`
or the Vue-side equivalent (`generateUrl('/apps/openbuilt/img/...')`).

**Why this matters**: putting screenshots in the repo for the seeded
four is **free** — they're tracked binaries no different to icons —
and it keeps the install footprint zero-network. The follow-up
community-submission spec will need user-uploaded screenshots, at
which point OR Files becomes the storage. The schema's
`screenshotUrl` accepts either a relative path or an OR Files URL,
so the migration is additive (no schema change).

**Trade-off**: the repo grows by ~4 PNGs. Acceptable.

**Alternatives considered**

- *Ship screenshots via OR Files from day one*. Rejected because it
  requires a working Files-upload flow in the seed step, which is
  more complex than copying a PNG into `img/`. Defer the complexity
  to the spec that actually needs it.
- *No screenshots in v1; text-only gallery*. Rejected because the
  whole point of templates is the visual on-ramp. A "Permit Tracker"
  label without a screenshot is not meaningfully more inviting than
  a blank-manifest editor.

### Decision 5 — Template versioning is deferred; clones are one-shot snapshots

**Decision**: `ApplicationTemplate.version` exists on the schema and
is recorded on the cloned Application under `templateOrigin.version`,
but the system performs **no** upgrade or propagation. A template
update never modifies existing clones (REQ-OBTC-007).

**Why this matters**: real versioning means "decide what to do when a
template is updated and an Application was cloned from the old
version" — propagate? prompt the user? offer a diff?  show a
deprecated banner?  These are all real product decisions and they
belong in a follow-up spec where the rest of the chain (#6 versioning)
provides the diff/snapshot machinery the answer would need. Spec #8
ships the **catalogue** and the **on-ramp**; spec-versioning ships
the **upgrade flow**.

`templateOrigin.version` is recorded anyway so the follow-up spec
does not need a migration to figure out which Applications were
cloned from which template version. Forward-compatibility for free.

**Alternatives considered**

- *No `version` field on templates at all*. Rejected — leaves the
  follow-up versioning spec with no anchor for "what did this clone
  come from?".
- *Auto-propagate template updates to existing clones*. Rejected as a
  silent-data-overwrite anti-pattern; this is exactly the kind of
  behaviour the user would never expect a clone to perform.

### Decision 6 — Mixed-spec rationale (ADR-032)

**Decision**: this spec is `kind: mixed` per ADR-032 because it
touches **both** declarative JSON (the `ApplicationTemplate` schema
declaration + the four seed manifest fixtures) and code (the
`createFromTemplate` controller method + the seed step + the gallery
SFC). ADR-032 normally rejects `kind: mixed`, but admits a thin-glue
exception when the code change is ≤20 LOC across ≤2 files and is
tightly coupled to the config.

The code surface this spec ships:

- **File 1: `lib/Controller/ApplicationsController.php`** — adds one
  new method `createFromTemplate(string $templateSlug, array $body):
  JSONResponse`. ~30 LOC. Reads one OR object, writes one or several
  OR objects with slug-prefix rewrites. Carries SPDX + EUPL-1.2
  docblock per memory rule. `#[NoAdminRequired]` so the
  route-auth gate passes.
- **File 2: `lib/Repair/SeedApplicationTemplates.php`** — new repair
  step modelled on `SeedHelloWorld.php`. ~80 LOC including the
  per-slug idempotency guard, the manifest-validation precheck, and
  the per-org seeding loop. This is config-shaped data loading
  (loading four JSON files into OR), not application logic; it fits
  the spirit of the ADR-032 exception.
- **File 3: `src/views/TemplateGallery.vue`** — gallery SFC,
  Options-API + `createObjectStore` per the memory rule (no custom
  Pinia stores layered over OR). ~120 LOC; mostly template + simple
  computed filtering. This is config-shaped UI (data presentation) —
  again, fits the spirit of the exception.

If, during apply, the controller method exceeds ~50 LOC or the SFC
exceeds ~200 LOC and grows real business logic, this spec MUST be
split into a chain — `openbuilt-template-schema` (config only) +
`openbuilt-template-clone` (code only). The thin-glue threshold is a
review gate, not a deferral; the apply agent should call it out
loudly if breached.

**Foundational ADRs honoured**

- **ADR-001** (every schema-introducing change ships seed data) — the
  four templates plus their companion schemas are the seed payload.
- **ADR-016** (single registration path for routes) — the clone route
  is declared in `appinfo/routes.php`, not via attribute-only
  registration.
- **ADR-022** (consume OR, do not wrap it) — templates are OR
  objects; the gallery reads OR REST directly; no
  `TemplateService` PHP class.
- **ADR-024** (canonical app-manifest schema) — every seeded manifest
  validates against the pinned schema.
- **ADR-031** (declarative-first business logic) — templates are
  data; the seed step is canonical config loading; the clone action
  is unavoidably a controller method but is the **minimum** code
  needed to express "read OR object A, write OR object B with field
  rewrites".
- **ADR-032** (thin-glue mixed exception) — see this decision.

**Anti-patterns explicitly avoided**

- No `TemplateCatalogueService` / `TemplateCloneService` /
  `TemplateStateMachine` PHP class. Anything that looks like one is
  an ADR-031 review-block on the apply PR.
- No custom Pinia store that wraps OR's REST. The gallery uses
  `createObjectStore` per the project-wide memory rule.

### Decision 7 — Declarative-vs-imperative decision (ADR-031)

| Candidate behaviour | Path |
|---|---|
| Template catalogue persistence (list, read, write) | **Declarative** — OR's stock REST against the `ApplicationTemplate` schema. No `TemplateService`. |
| Template seeding | **Declarative-shaped** — canonical ADR-001 repair step (loads JSON fixtures, calls `ConfigurationService::importFromApp()` per the memory rule, idempotent on per-slug guard). No state machine. |
| Manifest validation on seed | **Declarative** — relies on the canonical app-manifest schema pinned in `package.json` (ADR-024). No bespoke validator. |
| Template lifecycle (publish/archive) | **N/A — explicitly absent**. Templates do not have a draft/published/archived state machine in this spec. They are either present (`isSeeded:true` for curated, freshly-created for org-local in a follow-up) or removed. |
| Clone action | **Unavoidably imperative** — read one record, write several with field rewrites. ~30 LOC controller method. Documented in Decision 6 as the ADR-032 thin-glue exception. |
| Gallery rendering | **Declarative-shaped** — Vue SFC reading OR REST, no app-local store. |

**Anti-pattern explicitly avoided**. This spec ships no
`TemplateService.list()`, `TemplateService.clone()`, or
`TemplateCatalogue.refresh()` class. The clone action lives on the
controller as one method; that's it.

## Risks / Trade-offs

- **Risk — Schema-clone permission interaction with chain spec #7
  (`openbuilt-rbac`)**. When per-built-app RBAC lands (#7), cloning
  a template needs to grant the calling user ownership of the new
  Application + the cloned companion schemas. **Mitigation**: the
  `createFromTemplate` controller method SHALL set the calling user
  as the owner of the new Application (via OR's standard ownership
  metadata) and add the user's group to the permissions of the cloned
  companion schemas. Tested via Newman in `tests/api/openbuilt-templates.postman_collection.json`.
  If chain #7 changes the permissions vocabulary after this spec
  lands, the clone method needs a one-line update.

- **Risk — Slug-rewrite drift between manifest references and cloned
  schema names**. The controller method must rewrite **every** page-
  config `schema` reference in the cloned manifest, not just top-level
  ones. **Mitigation**: a small recursive walker in the controller
  method, exercised by a PHPUnit test that asserts every cloned
  manifest's page `config.schema` matches an existing cloned schema
  slug. If the manifest grows new schema-reference shapes in
  v1.5.x+, the walker must be extended; document this dependency in
  the apply tasks.

- **Risk — Repair step is slow if many orgs are present**. The seed
  step iterates orgs × four templates × per-template schemas. On an
  instance with 100 orgs that's 100 × ~5 = 500 OR-writes per repair
  run. **Mitigation**: the idempotency guard (per-slug existence
  check) short-circuits after the first run, so the steady state is
  one OR-read per (org, template) which is fine. Document the
  first-run cost in the migration plan.

- **Risk — Template manifest drift from the canonical schema**. The
  seeded manifests reference page types from v1.4.x. If the canonical
  schema bumps to v1.5.x and changes a page-type shape, the seeded
  templates break on validation. **Mitigation**: the seed step runs
  `validateManifest` and fails loudly. The repair step also
  surfaces a banner ("seeded template X is on schema vA.B.C; current
  canonical is vX.Y.Z — repair?") per the OQ-3 pattern from chain #1's
  `hello-world` seed.

- **Trade-off — Four seeded templates is a curated subset, not
  exhaustive**. US-5 (client intake with BRP prefill) is the obvious
  missing fifth. Acceptable for v1 because US-5 needs OpenConnector
  binding metadata not yet in the manifest. The proposal explicitly
  surfaces this in §"Deferred Work".

- **Trade-off — Gallery is rendered server-side-blind**. The gallery
  fetches the template list via OR REST on mount, so the empty-state
  on first paint flashes briefly. **Mitigation**: a skeleton-loader
  state in the SFC. Not a hard problem; documented for the apply
  agent.

## Migration Plan

This is a chain spec that adds one new schema, four seeded templates,
one new controller method, and one new SFC. No existing OR data is
modified.

1. Land the change on a feature branch from `development`.
2. CI runs PHPUnit + Newman + Playwright. The canonical green-light
   signals are:
   - Newman asserts the four seeded templates are GET-able from
     `/index.php/apps/openregister/api/objects/openbuilt/applicationtemplate`.
   - Playwright walks the gallery → clone → page-editor flow and
     asserts the cloned Application's first page renders.
3. Merge into `development`. The migration runs on next deploy via
   the new repair step; the `ApplicationTemplate` schema appears in
   the existing `openbuilt` register, and the four seeded templates
   appear per-org.
4. **Rollback** — disable the `openbuilt` app via `occ app:disable
   openbuilt`. The seeded `ApplicationTemplate` records remain in OR
   (harmless). To fully rollback, delete the four
   `isSeeded:true` templates via OR's admin UI per org. The new
   schema in the register stays; no other Conduction app reads from
   `openbuilt/applicationtemplate` so it is inert.

## Seed Data

Per ADR-001, every schema-introducing change ships seed data. This
spec seeds **four** Conduction-curated templates plus their companion
schemas. Each template's manifest blob is stored in a human-readable
JSON file under `lib/Settings/templates/{slug}.json` and is loaded by
`SeedApplicationTemplates.php` at repair time; the repair step
validates each manifest against the canonical schema before writing
it to OR.

### Template 1 — `permit-tracker` (US-1)

- `category: government-services`
- `title: openbuilt.templates.permit-tracker.title` (en: "Permit
  Tracker", nl: "Vergunningvolger")
- `useCase: openbuilt.templates.permit-tracker.useCase` (en:
  "Municipal building-permit workflow", nl: "Werkproces voor
  gemeentelijke bouwvergunningen")
- `screenshotUrl: img/templates/permit-tracker.png`
- `sourceUrl:
  https://github.com/ConductionNL/concurrentie-analyse/blob/main/app-builder/README.md#user-stories`
- `manifest`:
  - `version: 1.0.0`, `dependencies: ["openregister"]`
  - Menu items: `Applications` (index), `Submit` (form).
  - Pages: `Applications` (`type: index` over
    `permit-application`), `ApplicationDetail` (`type: detail`),
    `ApplicationForm` (`type: form`), `ApplicationKanban` (`type:
    kanban` grouped by `status`).
- `companionSchemas`:
  - `permit-application` — `{ applicant: string, address: string,
    buildingType: string, status: enum[draft, submitted, under-review,
    approved, rejected], submittedAt: datetime, decision: text }`.

### Template 2 — `stakeholder-consultation` (US-2)

- `category: citizen-engagement`
- `title: openbuilt.templates.stakeholder-consultation.title` (en:
  "Stakeholder Consultation", nl: "Stakeholderconsultatie")
- `useCase: openbuilt.templates.stakeholder-consultation.useCase` (en:
  "Gather structured input on policy proposals", nl: "Gestructureerde
  inbreng verzamelen op beleidsvoorstellen")
- `screenshotUrl: img/templates/stakeholder-consultation.png`
- `manifest`:
  - Menu items: `Consultations` (index), `Submit` (form).
  - Pages: `Consultations` (index), `ConsultationDetail` (detail
    with embedded comment-thread), `ConsultationForm` (form),
    `CommentForm` (form).
- `companionSchemas`:
  - `consultation` — `{ title: string, description: text, openFrom:
    date, closeAt: date, status: enum[draft, open, closed] }`.
  - `consultation-comment` — `{ consultationUuid: UUID, authorName:
    string, body: text, createdAt: datetime }`.

### Template 3 — `employee-onboarding` (US-4)

- `category: internal-operations`
- `title: openbuilt.templates.employee-onboarding.title` (en:
  "Employee Onboarding", nl: "Medewerker-onboarding")
- `useCase: openbuilt.templates.employee-onboarding.useCase` (en:
  "Guided onboarding with checklist, documents, and approval", nl:
  "Begeleide onboarding met checklist, documenten en goedkeuring")
- `screenshotUrl: img/templates/employee-onboarding.png`
- `manifest`:
  - Menu items: `Onboardings` (index), `Tasks` (index).
  - Pages: `Onboardings` (index), `OnboardingDetail` (detail),
    `OnboardingTaskChecklist` (`type: checklist`).
- `companionSchemas`:
  - `onboarding-task` — `{ employeeName: string, startDate: date,
    department: string, status: enum[pending, in-progress, done] }`.
  - `onboarding-document` — `{ taskUuid: UUID, documentName: string,
    uploadedFile: string, approved: boolean }`.

### Template 4 — `incident-reporter` (US-3)

- `category: field-work`
- `title: openbuilt.templates.incident-reporter.title` (en: "Incident
  Reporter", nl: "Incidentmelder")
- `useCase: openbuilt.templates.incident-reporter.useCase` (en:
  "Field incident intake for safety regions", nl: "Veldmeldingen voor
  veiligheidsregio's")
- `screenshotUrl: img/templates/incident-reporter.png`
- `manifest`:
  - Menu items: `Incidents` (index), `Report` (form).
  - Pages: `Incidents` (index), `IncidentDetail` (detail),
    `IncidentForm` (form, mobile-friendly).
- `companionSchemas`:
  - `incident` — `{ reportedBy: string, location: string,
    incidentType: string, severity: enum[low, medium, high, critical],
    description: text, reportedAt: datetime, status: enum[new,
    triaged, resolved] }`.

The apply agent SHALL generate the four
`lib/Settings/templates/{slug}.json` fixture files and the
`SeedApplicationTemplates.php` repair step from this section, plus
the four PNG screenshots in `img/templates/`. The repair step SHALL
follow `SeedHelloWorld.php`'s idempotency pattern (per-slug existence
check) and SHALL run `validateManifest` on each fixture before
writing it to OR (REQ-OBTC-009).

## Open Questions

- **OQ-1 — Screenshot generation source**. Should the four seeded
  screenshots be (a) hand-drawn mockups, (b) actual screenshots of a
  rendered seeded template, or (c) AI-generated illustrations? They
  block on (b) only if the apply agent has a running OpenBuilt with
  the four templates cloned. *Provisional decision*: ship (a) hand-
  drawn / simple Figma exports as PNGs for v1. The apply tasks
  reference a placeholder image; the design-team follow-up replaces
  them with real screenshots once the templates render.

- **OQ-2 — Cross-org cloning**. Can a user in org A clone a template
  visible only in org B? *Provisional decision*: no. The gallery
  reads OR REST scoped by the caller's organisation, so templates
  outside the caller's org are not listed; the clone endpoint
  asserts the source template is in the caller's org and 4xxs
  otherwise. Re-confirm during apply.

- **OQ-3 — Slug-prefix length cap**. The clone slug-prefix pattern
  (`{app-slug}-{schema-slug}`) can produce schema slugs > 64
  characters if both are long. OR's schema-slug column appears to
  accept this generously, but verify the canonical schema cap during
  apply. *Provisional decision*: hard-cap the new Application's slug
  at 32 characters in the clone-request validation; that bounds the
  joined slug at ~64 characters in practice.

- **OQ-4 — i18n storage convention for seeded template strings**.
  Store the four `title` / `description` / `useCase` strings as
  literal English with Dutch in `l10n/nl.json` (matching chain #1's
  `openbuilt.helloworld.*` pattern), or store i18n keys directly in
  the seeded record? *Provisional decision*: store i18n keys
  (`openbuilt.templates.permit-tracker.title`) in the seeded record
  and resolve them via Nextcloud's i18n at gallery-render time. This
  matches how the seeded manifest's `menu[].label` already works in
  chain #1.

- **OQ-5 — Page-editor fallback shape**. REQ-OBTC-006 says "fall back
  to the textarea editor if the page editor view from chain #5 is not
  present". How is "not present" detected at runtime — feature flag,
  route existence, capability registration? *Provisional decision*:
  feature-detect via `router.resolve('/applications/:slug/edit').matched.length`
  on the OpenBuilt frontend; if the page-editor route is registered,
  redirect there, else fall back to the textarea editor. Re-confirm
  with the apply agent of chain #5 when both specs land.
