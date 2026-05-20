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
  a cloned companion schema.

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
  `img/templates/` for v1.
- US-5 (client intake app) — depends on manifest-level OpenConnector
  binding metadata not yet in v1.4.x.

## Decisions

### Decision 1 — Templates as OR objects, not static JSON files

**Decision**: `ApplicationTemplate` is an OR schema; templates are
records, not files.

**Why this matters**: a competing approach is to ship templates as
JSON files under `lib/Settings/templates/*.json` and have the gallery
read them directly off disk. We reject it because OpenBuilt's whole
architectural commitment is ADR-022: **consume OpenRegister, do not
invent app-local stores**. Treating templates as files would create a
second source of truth that does not get RBAC, audit, GraphQL, MCP,
CloudEvents, or any of the things every other Conduction app gets for
free by virtue of being on OR.

The clone semantics are also consistent: "read one OR object, write
another OR object" is the canonical OR operation.

**Storage trade-off**: the four seed template manifests are still
human-readable JSON in the repo (under `lib/Settings/templates/{slug}.json`)
because they are non-trivial to review embedded in a PHP repair step.
The repair step loads them from disk at install time and writes them
into OR — the file is the **source**, OR is the **runtime**.

**Alternatives considered**

- *Static JSON gallery, no OR schema*. Rejected per ADR-022. Also
  makes future "edit a template in the UI" impossible without a
  migration.
- *Hybrid — seed templates as OR objects, but read them through a new
  `TemplateService` PHP class*. Rejected per ADR-022 (no wrapper
  services) and ADR-031 (declarative-first). The gallery reads OR's
  REST directly.

### Decision 2 — Per-org namespace with `isSeeded` flag

**Decision**: templates live per-organisation, scoped via OR's
standard `organisation` field. The four Conduction-curated templates
ship with `isSeeded: true` and are seeded into each organisation that
installs OpenBuilt.

**Why this matters**: a single "global" Conduction namespace would
create a special case that has to be threaded through every RBAC check
when chain spec #7 (`openbuilt-rbac`) lands. Per-org isolation matches
every other OR record in OpenBuilt. The `isSeeded: true` flag is the
**only** distinction between Conduction-curated and org-local
templates. The gallery treats `isSeeded: true` templates as read-only
in the UI (REQ-OBTC-008) so a distracted admin cannot accidentally
delete `permit-tracker` from their org.

**Operational consequence**: when an org installs OpenBuilt, the seed
step iterates over every existing organisation in the system and
seeds the four curated templates into each. The repair step's
idempotency guard (per-slug existence check, per-org scope) keeps
this safe.

**Alternatives considered**

- *Single global org "openbuilt" hosting the curated templates*.
  Rejected per the RBAC and migration arguments above.
- *Per-user templates, not per-org*. Rejected — collaboration breaks
  if Alice's templates are invisible to her colleague Bob in the same
  organisation.

### Decision 3 — Slug-prefix the cloned companion schemas

**Decision**: on clone, the new Application's `slug` is joined by a
hyphen to each cloned companion-schema's `slug`. Example:
`permit-tracker` template → cloned into Application
`slug: my-permits` → the `permit-application` companion schema is
cloned as `slug: my-permits-permit-application`. The cloned manifest's
page-config `schema` references are rewritten to match.

**Why this matters**: without a prefix, two clones of the same
template into the same organisation collide on the schema slug. With
the Application's slug as the prefix, the schema slug stays
human-readable and unambiguously identifies its owning Application.

**Trade-off**: schema slugs become long (`my-permits-permit-application`
is 30 characters). OR's schema-slug column is generously sized. The
new Application's slug is hard-capped at 32 characters in the
clone-request validation (OQ-3), bounding the joined slug at ~64
characters in practice.

**Risk**: if the user changes the new Application's `slug` after
clone, the companion-schema slugs do not auto-rename. This is
acceptable — renaming an Application is a rare operation.

**Alternatives considered**

- *No prefix; reject the clone if a schema-slug collides*. Rejected
  because it makes "clone twice" a confusing UX.
- *UUID-suffix the cloned schemas*. Rejected for the readability hit.
- *Per-application sub-namespace in OR*. Rejected because it explodes
  the register namespace count without a clear benefit.

### Decision 4 — Screenshots committed to repo for v1, Files API in follow-up

**Decision**: the four seeded templates' screenshots ship as PNGs in
`img/templates/{slug}.png`, served via Nextcloud's standard
`apps/openbuilt/img/templates/{slug}.png` static-asset path.
`screenshotUrl` on a seeded template stores a relative path
(`img/templates/permit-tracker.png`); the gallery resolves it via
`generateUrl('/apps/openbuilt/img/...')`.

**Why this matters**: putting screenshots in the repo for the seeded
four is **free** — they're tracked binaries no different to icons.
The schema's `screenshotUrl` accepts either a relative path or an OR
Files URL, so the migration is additive (no schema change) when the
community-submission spec arrives.

**Alternatives considered**

- *Ship screenshots via OR Files from day one*. Rejected because it
  requires a working Files-upload flow in the seed step.
- *No screenshots in v1; text-only gallery*. Rejected because the
  whole point of templates is the visual on-ramp.

### Decision 5 — Template versioning is deferred; clones are one-shot snapshots

**Decision**: `ApplicationTemplate.version` exists on the schema and
is recorded on the cloned Application under `templateOrigin.version`,
but the system performs **no** upgrade or propagation. A template
update never modifies existing clones (REQ-OBTC-007).

**Why this matters**: real versioning means "decide what to do when a
template is updated and an Application was cloned from the old
version" — propagate? prompt the user? offer a diff? These are real
product decisions that belong in a follow-up spec where chain #6
versioning provides the diff/snapshot machinery the answer would need.

`templateOrigin.version` is recorded anyway so the follow-up spec
does not need a migration to figure out which Applications were
cloned from which template version.

**Alternatives considered**

- *No `version` field on templates at all*. Rejected — leaves the
  follow-up versioning spec with no anchor for "what did this clone
  come from?".
- *Auto-propagate template updates to existing clones*. Rejected as a
  silent-data-overwrite anti-pattern.

### Decision 6 — Mixed-spec rationale (ADR-032)

**Decision**: this spec is `kind: mixed` per ADR-032 because it
touches **both** declarative JSON (the `ApplicationTemplate` schema
declaration + the four seed manifest fixtures) and code (the
`createFromTemplate` controller method + the seed step + the gallery
SFC). ADR-032 admits a thin-glue exception when code change is ≤20
LOC across ≤2 files and is tightly coupled to the config.

The code surface this spec ships:

- **File 1: `lib/Controller/ApplicationsController.php`** — adds one
  new method `createFromTemplate(string $templateSlug, array $body):
  JSONResponse`. ~30 LOC. `#[NoAdminRequired]` attribute set.
- **File 2: `lib/Repair/SeedApplicationTemplates.php`** — new repair
  step modelled on `SeedHelloWorld.php`. ~80 LOC including the
  per-slug idempotency guard, the manifest-validation precheck, and
  the per-org seeding loop.
- **File 3: `src/views/TemplateGallery.vue`** — gallery SFC,
  Options-API + `createObjectStore`. ~120 LOC; mostly template +
  simple computed filtering.

If the controller method exceeds ~50 LOC or the SFC exceeds ~200 LOC
and grows real business logic, this spec MUST be split into a chain.
The thin-glue threshold is a review gate, not a deferral.

**Foundational ADRs honoured**

- **ADR-001** (every schema-introducing change ships seed data)
- **ADR-016** (single registration path for routes)
- **ADR-022** (consume OR, do not wrap it)
- **ADR-024** (canonical app-manifest schema)
- **ADR-031** (declarative-first business logic)
- **ADR-032** (thin-glue mixed exception)

**Anti-patterns explicitly avoided**

- No `TemplateCatalogueService` / `TemplateCloneService` /
  `TemplateStateMachine` PHP class.
- No custom Pinia store that wraps OR's REST. The gallery uses
  `createObjectStore` per the project-wide memory rule.

### Decision 7 — Declarative-vs-imperative decision (ADR-031)

| Candidate behaviour | Path |
|---|---|
| Template catalogue persistence (list, read, write) | **Declarative** — OR's stock REST against the `ApplicationTemplate` schema. No `TemplateService`. |
| Template seeding | **Declarative-shaped** — canonical ADR-001 repair step (loads JSON fixtures, calls `ConfigurationService::importFromApp()`, idempotent on per-slug guard). No state machine. |
| Manifest validation on seed | **Declarative** — relies on the canonical app-manifest schema pinned in `package.json` (ADR-024). No bespoke validator. |
| Template lifecycle (publish/archive) | **N/A — explicitly absent**. Templates do not have a draft/published/archived state machine in this spec. |
| Clone action | **Unavoidably imperative** — read one record, write several with field rewrites. ~30 LOC controller method. Documented in Decision 6 as the ADR-032 thin-glue exception. |
| Gallery rendering | **Declarative-shaped** — Vue SFC reading OR REST, no app-local store. |

## Reuse Analysis (ADR-001)

The following existing OpenRegister services are leveraged — no new
service classes are introduced:

| OR service / component | Usage in this spec |
|---|---|
| `ObjectService::getObject()` | Fetch `ApplicationTemplate` by slug in `createFromTemplate` |
| `ObjectService::saveObject()` | Write cloned `Application` + companion schemas |
| `ObjectService::searchObjects()` | Slug-uniqueness check during clone and seed idempotency guard |
| `ConfigurationService::importFromApp()` | Persist seed records into OR at repair time |
| `createObjectStore(name)` | Pinia CRUD store in `TemplateGallery.vue` |
| `CnCardGrid` + `CnObjectCard` | Gallery card grid layout |
| `CnFilterBar` | Category + free-text filter controls |
| `CnEmptyState` | Empty Application list state with CTA |

No overlap with existing OpenBuilt services found. `createFromTemplate`
is the only bespoke controller method and has no equivalent in the
existing `ApplicationsController`.

## Risks / Trade-offs

- **Risk — Schema-clone permission interaction with chain spec #7
  (`openbuilt-rbac`)**. When per-built-app RBAC lands (#7), cloning
  a template needs to grant the calling user ownership of the new
  Application + the cloned companion schemas. **Mitigation**: the
  `createFromTemplate` controller method SHALL set the calling user
  as the owner of the new Application via OR's standard ownership
  metadata.

- **Risk — Slug-rewrite drift between manifest references and cloned
  schema names**. The controller method must rewrite **every** page-
  config `schema` reference in the cloned manifest, not just top-level
  ones. **Mitigation**: a small recursive walker in the controller
  method, exercised by a PHPUnit test.

- **Risk — Repair step is slow if many orgs are present**. On an
  instance with 100 orgs that's 100 × ~5 = 500 OR-writes per repair
  run. **Mitigation**: the idempotency guard short-circuits after the
  first run, so the steady state is one OR-read per (org, template).

- **Risk — Template manifest drift from the canonical schema**. If the
  canonical schema bumps to v1.5.x and changes a page-type shape, the
  seeded templates break on validation. **Mitigation**: the seed step
  runs `validateManifest` and fails loudly (REQ-OBTC-009).

- **Trade-off — Gallery is rendered server-side-blind**. The gallery
  fetches the template list via OR REST on mount, so the empty-state
  on first paint flashes briefly. **Mitigation**: a skeleton-loader
  state in the SFC.

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
   (harmless). To fully rollback, delete the four `isSeeded:true`
   templates via OR's admin UI per org.

## Seed Data

Per ADR-001, every schema-introducing change ships seed data. This
spec seeds **four** Conduction-curated templates plus their companion
schemas. Each template's manifest blob is stored in a human-readable
JSON file under `lib/Settings/templates/{slug}.json` and is loaded by
`SeedApplicationTemplates.php` at repair time; the repair step
validates each manifest against the canonical schema before writing
it to OR.

### Template 1 — `permit-tracker` (US-1)

```json
{
  "@self": {
    "register": "openbuilt",
    "schema": "applicationTemplate",
    "slug": "permit-tracker"
  },
  "uuid": "11111111-1111-1111-1111-111111111111",
  "slug": "permit-tracker",
  "title": "openbuilt.templates.permit-tracker.title",
  "description": "openbuilt.templates.permit-tracker.description",
  "useCase": "openbuilt.templates.permit-tracker.useCase",
  "category": "government-services",
  "screenshotUrl": "img/templates/permit-tracker.png",
  "isSeeded": true,
  "version": "1.0.0",
  "sourceUrl": "https://github.com/ConductionNL/concurrentie-analyse/blob/main/app-builder/README.md#user-stories",
  "manifest": {
    "version": "1.0.0",
    "dependencies": ["openregister"],
    "menu": [
      { "label": "openbuilt.templates.permit-tracker.menu.applications", "route": "applications" },
      { "label": "openbuilt.templates.permit-tracker.menu.submit", "route": "applicationForm" }
    ],
    "pages": [
      { "title": "openbuilt.templates.permit-tracker.pages.index", "type": "index", "config": { "schema": "permit-application" } },
      { "title": "openbuilt.templates.permit-tracker.pages.detail", "type": "detail", "config": { "schema": "permit-application" } },
      { "title": "openbuilt.templates.permit-tracker.pages.form", "type": "form", "config": { "schema": "permit-application" } },
      { "title": "openbuilt.templates.permit-tracker.pages.kanban", "type": "kanban", "config": { "schema": "permit-application", "groupBy": "status" } }
    ]
  },
  "companionSchemas": [
    {
      "slug": "permit-application",
      "title": "Vergunningaanvraag",
      "properties": {
        "applicant": { "type": "string", "title": "Aanvrager" },
        "address": { "type": "string", "title": "Adres" },
        "buildingType": { "type": "string", "title": "Type bouwwerk" },
        "status": { "type": "string", "enum": ["draft", "submitted", "under-review", "approved", "rejected"], "title": "Status" },
        "submittedAt": { "type": "string", "format": "date-time", "title": "Ingediend op" },
        "decision": { "type": "string", "title": "Besluit" }
      },
      "required": ["applicant", "address", "buildingType", "status"]
    }
  ]
}
```

Dutch seed example objects (3 per companion schema):

| slug | applicant | address | buildingType | status |
|---|---|---|---|---|
| `permit-tracker-sample-1` | J.A. de Vries | Keizersgracht 123, 1015 CJ Amsterdam | Woninguitbouw | submitted |
| `permit-tracker-sample-2` | Gemeente Utrecht Vastgoed | Catharijnesingel 55, 3511 GE Utrecht | Bedrijfsgebouw | under-review |
| `permit-tracker-sample-3` | F. El-Amrani | Lijnbaan 12, 3012 EH Rotterdam | Dakkapel | approved |

### Template 2 — `stakeholder-consultation` (US-2)

```json
{
  "@self": {
    "register": "openbuilt",
    "schema": "applicationTemplate",
    "slug": "stakeholder-consultation"
  },
  "uuid": "22222222-2222-2222-2222-222222222222",
  "slug": "stakeholder-consultation",
  "title": "openbuilt.templates.stakeholder-consultation.title",
  "description": "openbuilt.templates.stakeholder-consultation.description",
  "useCase": "openbuilt.templates.stakeholder-consultation.useCase",
  "category": "citizen-engagement",
  "screenshotUrl": "img/templates/stakeholder-consultation.png",
  "isSeeded": true,
  "version": "1.0.0",
  "sourceUrl": "https://github.com/ConductionNL/concurrentie-analyse/blob/main/app-builder/README.md#user-stories",
  "manifest": {
    "version": "1.0.0",
    "dependencies": ["openregister"],
    "menu": [
      { "label": "openbuilt.templates.stakeholder-consultation.menu.consultations", "route": "consultations" },
      { "label": "openbuilt.templates.stakeholder-consultation.menu.submit", "route": "consultationForm" }
    ],
    "pages": [
      { "title": "openbuilt.templates.stakeholder-consultation.pages.index", "type": "index", "config": { "schema": "consultation" } },
      { "title": "openbuilt.templates.stakeholder-consultation.pages.detail", "type": "detail", "config": { "schema": "consultation" } },
      { "title": "openbuilt.templates.stakeholder-consultation.pages.form", "type": "form", "config": { "schema": "consultation" } },
      { "title": "openbuilt.templates.stakeholder-consultation.pages.commentForm", "type": "form", "config": { "schema": "consultation-comment" } }
    ]
  },
  "companionSchemas": [
    {
      "slug": "consultation",
      "title": "Consultatie",
      "properties": {
        "title": { "type": "string", "title": "Titel" },
        "description": { "type": "string", "title": "Omschrijving" },
        "openFrom": { "type": "string", "format": "date", "title": "Open vanaf" },
        "closeAt": { "type": "string", "format": "date", "title": "Sluit op" },
        "status": { "type": "string", "enum": ["draft", "open", "closed"], "title": "Status" }
      },
      "required": ["title", "status"]
    },
    {
      "slug": "consultation-comment",
      "title": "Reactie op consultatie",
      "properties": {
        "consultationUuid": { "type": "string", "format": "uuid", "title": "Consultatie" },
        "authorName": { "type": "string", "title": "Naam indiener" },
        "body": { "type": "string", "title": "Reactie" },
        "createdAt": { "type": "string", "format": "date-time", "title": "Ingediend op" }
      },
      "required": ["consultationUuid", "authorName", "body"]
    }
  ]
}
```

Dutch seed example objects:

| slug | title | status |
|---|---|---|
| `consultation-sample-1` | Ontwerp omgevingsplan Buurt Noord | open |
| `consultation-sample-2` | Mobiliteitsvisie 2030 | draft |
| `consultation-sample-3` | Groenbeleid binnenstad | closed |

### Template 3 — `employee-onboarding` (US-4)

```json
{
  "@self": {
    "register": "openbuilt",
    "schema": "applicationTemplate",
    "slug": "employee-onboarding"
  },
  "uuid": "33333333-3333-3333-3333-333333333333",
  "slug": "employee-onboarding",
  "title": "openbuilt.templates.employee-onboarding.title",
  "description": "openbuilt.templates.employee-onboarding.description",
  "useCase": "openbuilt.templates.employee-onboarding.useCase",
  "category": "internal-operations",
  "screenshotUrl": "img/templates/employee-onboarding.png",
  "isSeeded": true,
  "version": "1.0.0",
  "sourceUrl": "https://github.com/ConductionNL/concurrentie-analyse/blob/main/app-builder/README.md#user-stories",
  "manifest": {
    "version": "1.0.0",
    "dependencies": ["openregister"],
    "menu": [
      { "label": "openbuilt.templates.employee-onboarding.menu.onboardings", "route": "onboardings" },
      { "label": "openbuilt.templates.employee-onboarding.menu.tasks", "route": "tasks" }
    ],
    "pages": [
      { "title": "openbuilt.templates.employee-onboarding.pages.index", "type": "index", "config": { "schema": "onboarding-task" } },
      { "title": "openbuilt.templates.employee-onboarding.pages.detail", "type": "detail", "config": { "schema": "onboarding-task" } },
      { "title": "openbuilt.templates.employee-onboarding.pages.checklist", "type": "checklist", "config": { "schema": "onboarding-task" } }
    ]
  },
  "companionSchemas": [
    {
      "slug": "onboarding-task",
      "title": "Onboardingstaak",
      "properties": {
        "employeeName": { "type": "string", "title": "Medewerker" },
        "startDate": { "type": "string", "format": "date", "title": "Startdatum" },
        "department": { "type": "string", "title": "Afdeling" },
        "status": { "type": "string", "enum": ["pending", "in-progress", "done"], "title": "Status" }
      },
      "required": ["employeeName", "startDate", "status"]
    },
    {
      "slug": "onboarding-document",
      "title": "Onboardingdocument",
      "properties": {
        "taskUuid": { "type": "string", "format": "uuid", "title": "Onboardingstaak" },
        "documentName": { "type": "string", "title": "Documentnaam" },
        "uploadedFile": { "type": "string", "title": "Bestand" },
        "approved": { "type": "boolean", "title": "Goedgekeurd" }
      },
      "required": ["taskUuid", "documentName"]
    }
  ]
}
```

Dutch seed example objects:

| slug | employeeName | department | status |
|---|---|---|---|
| `onboarding-task-sample-1` | Noor Yilmaz | ICT | pending |
| `onboarding-task-sample-2` | Priya Ganpat | Ruimtelijke Ordening | in-progress |
| `onboarding-task-sample-3` | Sem de Jong | Communicatie | done |

### Template 4 — `incident-reporter` (US-3)

```json
{
  "@self": {
    "register": "openbuilt",
    "schema": "applicationTemplate",
    "slug": "incident-reporter"
  },
  "uuid": "44444444-4444-4444-4444-444444444444",
  "slug": "incident-reporter",
  "title": "openbuilt.templates.incident-reporter.title",
  "description": "openbuilt.templates.incident-reporter.description",
  "useCase": "openbuilt.templates.incident-reporter.useCase",
  "category": "field-work",
  "screenshotUrl": "img/templates/incident-reporter.png",
  "isSeeded": true,
  "version": "1.0.0",
  "sourceUrl": "https://github.com/ConductionNL/concurrentie-analyse/blob/main/app-builder/README.md#user-stories",
  "manifest": {
    "version": "1.0.0",
    "dependencies": ["openregister"],
    "menu": [
      { "label": "openbuilt.templates.incident-reporter.menu.incidents", "route": "incidents" },
      { "label": "openbuilt.templates.incident-reporter.menu.report", "route": "incidentForm" }
    ],
    "pages": [
      { "title": "openbuilt.templates.incident-reporter.pages.index", "type": "index", "config": { "schema": "incident" } },
      { "title": "openbuilt.templates.incident-reporter.pages.detail", "type": "detail", "config": { "schema": "incident" } },
      { "title": "openbuilt.templates.incident-reporter.pages.form", "type": "form", "config": { "schema": "incident" } }
    ]
  },
  "companionSchemas": [
    {
      "slug": "incident",
      "title": "Incident",
      "properties": {
        "reportedBy": { "type": "string", "title": "Gemeld door" },
        "location": { "type": "string", "title": "Locatie" },
        "incidentType": { "type": "string", "title": "Type incident" },
        "severity": { "type": "string", "enum": ["low", "medium", "high", "critical"], "title": "Ernst" },
        "description": { "type": "string", "title": "Omschrijving" },
        "reportedAt": { "type": "string", "format": "date-time", "title": "Gemeld op" },
        "status": { "type": "string", "enum": ["new", "triaged", "resolved"], "title": "Status" }
      },
      "required": ["reportedBy", "location", "incidentType", "severity", "status"]
    }
  ]
}
```

Dutch seed example objects:

| slug | reportedBy | location | incidentType | severity | status |
|---|---|---|---|---|---|
| `incident-sample-1` | H. Bakker | Industrieweg 45, Tilburg | Brand | high | triaged |
| `incident-sample-2` | J.W. van der Berg | A2 km 78, richting Den Haag | Verkeersongeval | medium | new |
| `incident-sample-3` | A. de Vries | Marktplein 3, Breda | Wateroverlast | low | resolved |

## Open Questions

- **OQ-1 — Screenshot generation source**. Should the four seeded
  screenshots be (a) hand-drawn mockups, (b) actual screenshots of a
  rendered seeded template, or (c) AI-generated illustrations?
  *Provisional decision*: ship (a) hand-drawn / simple Figma exports
  as PNGs for v1. The apply tasks reference a placeholder image; the
  design-team follow-up replaces them with real screenshots once the
  templates render.

- **OQ-2 — Cross-org cloning**. Can a user in org A clone a template
  visible only in org B? *Provisional decision*: no. The gallery
  reads OR REST scoped by the caller's organisation, so templates
  outside the caller's org are not listed; the clone endpoint asserts
  the source template is in the caller's org and 4xxs otherwise.

- **OQ-3 — Slug-prefix length cap**. The clone slug-prefix pattern
  (`{app-slug}-{schema-slug}`) can produce schema slugs > 64
  characters if both are long. *Provisional decision*: hard-cap the
  new Application's slug at 32 characters in the clone-request
  validation; that bounds the joined slug at ~64 characters.

- **OQ-4 — i18n storage convention for seeded template strings**.
  *Provisional decision*: store i18n keys
  (`openbuilt.templates.permit-tracker.title`) in the seeded record
  and resolve them via Nextcloud's i18n at gallery-render time.

- **OQ-5 — Page-editor fallback shape**. REQ-OBTC-006 says "fall back
  to the textarea editor if the page editor view from chain #5 is not
  present". *Provisional decision*: feature-detect via
  `router.resolve('/applications/:slug/edit').matched.length`
  on the OpenBuilt frontend.
