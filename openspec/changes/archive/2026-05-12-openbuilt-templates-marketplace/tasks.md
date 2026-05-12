## 1. Implementation Tasks — openbuilt-template-catalogue

- [ ] 1.1 **Declare `ApplicationTemplate` schema in `lib/Settings/openbuilt_register.json`**
  - spec_ref: REQ-OBTC-001
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Schema declares `uuid`, `slug` (kebab-case pattern, unique per organisation), `title` (required), `description` (required), `useCase` (required), `category` (enum government-services|internal-operations|citizen-engagement|field-work, required), `screenshotUrl` (URI, optional), `manifest` (object, required, references the canonical app-manifest schema), `companionSchemas` (array of objects, optional), `isSeeded` (boolean, required, default false), `sourceUrl` (URI, optional), `version` (semver pattern, required). Validates against OpenAPI 3.0.0. Multi-tenant scoping inherited from OR's `organisation` field — no app-local RBAC introduced (ADR-022).
  - Implement: declarative — append a new entry to the existing `openbuilt_register.json`. No PHP service class.
  - Test: integration test creates an `ApplicationTemplate` via OR REST; assert schema-validation rejects a record missing `manifest`; assert slug-uniqueness rejects a duplicate slug in the same organisation.

- [ ] 1.2 **Add the new route to `appinfo/routes.php`** (ADR-016)
  - spec_ref: REQ-OBTC-004
  - files: `appinfo/routes.php`
  - acceptance_criteria: Route `POST /api/applications/from-template/{templateSlug}` maps to `applications#createFromTemplate` with `#[NoAdminRequired]`. Sole registration path is `routes.php` (no attribute-only registration).
  - Implement: ~3 LOC route declaration appended to the existing routes array.
  - Test: Newman GET / OPTIONS / POST captures verify the route resolves with the correct verb.

- [ ] 1.3 **Add `ApplicationsController::createFromTemplate`** (ADR-032 thin-glue exception per design.md Decision 6)
  - spec_ref: REQ-OBTC-004, REQ-OBTC-005
  - files: `lib/Controller/ApplicationsController.php`
  - acceptance_criteria: `createFromTemplate(string $templateSlug, array $body): JSONResponse` looks up the template via OR ObjectService scoped to the caller's organisation, deep-copies its `manifest` + each entry of `companionSchemas` into new OR records (companion-schema slugs prefixed with the new Application's slug per REQ-OBTC-005, manifest page-config `schema` references rewritten by a small recursive walker), records `templateOrigin.slug` + `templateOrigin.version` on the new Application, returns 201 with `{ uuid, slug }`. Slug-collision on the new Application returns 4xx without writing. Cross-org clone returns 4xx (OQ-2). ≤30 LOC; carries SPDX + EUPL-1.2 docblock in the file-level docblock (memory rule). `#[NoAdminRequired]` attribute set.
  - Implement: single method on the existing controller; no new service class (ADR-031).
  - Test: PHPUnit covers the success path (201 + Application + companion schemas exist with prefixed slugs), the slug-collision path (4xx, no writes), the cross-org path (4xx), and the schema-reference rewrite path (every cloned manifest page-config `schema` matches a cloned schema slug).

- [ ] 1.4 **Build `TemplateGallery.vue`**
  - spec_ref: REQ-OBTC-003, REQ-OBTC-006, REQ-OBTC-008
  - files: `src/views/TemplateGallery.vue`, `src/router/index.js` (add the `/templates` route)
  - acceptance_criteria: Renders a grid/list of `ApplicationTemplate` records fetched via OR REST scoped to the caller's organisation, with filter controls for `category` and a free-text search over `title` + `useCase` + `description`. Each card shows `title`, `useCase`, `description`, `category`, `screenshotUrl` if present, and a "Use this template" action. Seeded records (`isSeeded:true`) show only the "Use this template" action (no edit/delete UI per REQ-OBTC-008). Uses Options API + `createObjectStore` (memory rule — no custom Pinia store). Uses Nextcloud CSS variables only; no hardcoded colours (ADR-010). On "Use this template", prompts for `name` + `slug`, POSTs to `/api/applications/from-template/{slug}`, and on success redirects to the page editor (chain #5) via the feature-detect path in OQ-5; falls back to the textarea editor (REQ-OBR-005 from chain #1) if the page-editor route is not registered.
  - Implement: SFC ~120 LOC, mostly template + simple computed filtering.
  - Test: Playwright opens the gallery, asserts the four seeded templates render, applies the `government-services` filter and asserts only `permit-tracker` is visible, clicks "Use this template" on `permit-tracker`, fills in `name: "My permits" / slug: "my-permits"`, asserts the post-clone redirect lands on the editor surface, and asserts the seeded record renders no Edit/Delete controls.

- [ ] 1.5 **Add the "Templates" entry to the OpenBuilt left-nav** and the empty-state CTA on the Application list
  - spec_ref: REQ-OBTC-003
  - files: `src/views/BuilderShell.vue` (left-nav extension), `src/views/ApplicationList.vue` (empty-state CTA — file may already exist from chain #1)
  - acceptance_criteria: A new left-nav entry `openbuilt.templates.menu.label` (i18n) routes to `/templates`. The empty-state of the Application list renders a "Create from template" CTA that routes to `/templates`. Nextcloud CSS variables only.
  - Implement: small template-only edits to the existing shell files; ~10 LOC each.
  - Test: Playwright asserts the left-nav entry is visible and clickable, and the empty-state CTA appears when no Applications exist in the caller's org.

## 2. Seed Data (ADR-001)

- [ ] 2.1 **Author the four template manifest fixtures** under `lib/Settings/templates/`
  - spec_ref: REQ-OBTC-002, REQ-OBTC-009
  - files: `lib/Settings/templates/permit-tracker.json`, `lib/Settings/templates/stakeholder-consultation.json`, `lib/Settings/templates/employee-onboarding.json`, `lib/Settings/templates/incident-reporter.json`
  - acceptance_criteria: Each fixture is a full `ApplicationTemplate` JSON record (matching the schema declared in 1.1) including its `manifest` blob and its `companionSchemas` array as described in design.md "Seed Data". Each `manifest` validates against the canonical app-manifest schema pinned in `package.json` (ADR-024). All user-visible strings (`title`, `description`, `useCase`, manifest `menu[].label`, manifest page `title`) use i18n keys under `openbuilt.templates.{slug}.*` (OQ-4).
  - Implement: hand-authored JSON; no scripting / sed / awk / python to generate.
  - Test: `npm run check:manifest` over each fixture; integration test asserts every fixture validates without warnings.

- [ ] 2.2 **Ship the seed repair step `lib/Repair/SeedApplicationTemplates.php`**
  - spec_ref: REQ-OBTC-002, REQ-OBTC-009
  - files: `lib/Repair/SeedApplicationTemplates.php`, `appinfo/info.xml` (add `SeedApplicationTemplates` as a `<post-migration>` repair step after the existing `SeedHelloWorld`)
  - acceptance_criteria: For each organisation in the system and each of the four fixtures, the step (a) loads the fixture JSON, (b) calls `validateManifest` on the embedded `manifest`, (c) checks for an existing `ApplicationTemplate` with that `slug` in that organisation, (d) if absent, calls `ConfigurationService::importFromApp()` (memory rule) to persist the record into OR with `isSeeded:true`. Idempotent: re-running the step on a seeded install is a no-op. Validation failure on any fixture fails the repair step loudly (exit non-zero) per REQ-OBTC-009. Modelled on `SeedHelloWorld.php`'s structure.
  - Implement: PHP repair step; no scripting to generate or modify code.
  - Test: PHPUnit runs the repair step twice, asserts exactly four `ApplicationTemplate` records exist per organisation after each run; corrupt one fixture's manifest and assert the repair step exits non-zero with the offending page-type cited.

- [ ] 2.3 **Add the four template screenshots** to `img/templates/`
  - spec_ref: REQ-OBTC-001, OQ-1
  - files: `img/templates/permit-tracker.png`, `img/templates/stakeholder-consultation.png`, `img/templates/employee-onboarding.png`, `img/templates/incident-reporter.png`
  - acceptance_criteria: Each PNG ≤ 200 KB, reasonable aspect ratio (~16:10) suitable for a gallery card. Placeholder simple mockups acceptable for v1 per OQ-1; design-team follow-up replaces them.
  - Implement: binary asset commit. No scripting.
  - Test: Playwright asserts each gallery card renders its screenshot (image element loads without 404).

## 3. Verification

- [ ] 3.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — all green; fix any pre-existing issues in touched files (memory rule).
- [ ] 3.2 Run `npm run lint` / ESLint flat config — clean on the new SFC.
- [ ] 3.3 Run `npm run check:manifest` (ADR-024) on the four seeded fixtures — all four validate against the canonical schema pinned in `package.json`.
- [ ] 3.4 Visually verify on a fresh `docker compose up` that `/index.php/apps/openbuilt/templates` renders the four seeded templates, that "Use this template" on `permit-tracker` lands on a draft Application in the editor surface, and that the cloned companion schema is namespaced as `{new-app-slug}-permit-application`.
- [ ] 3.5 Confirm no `TemplateCatalogueService.php` / `TemplateService.php` / `TemplateCloneService.php` exists under `lib/Service/` — ADR-031 review gate.
- [ ] 3.6 Confirm the `createFromTemplate` method is ≤30 LOC and the seed step ≤80 LOC — ADR-032 thin-glue threshold (design.md Decision 6). If exceeded, this spec needs to be split per the design rule; raise it loudly during apply.

## 4. Tests (ADR-008)

- [ ] 4.1 **PHPUnit** — `tests/Unit/Controller/ApplicationsControllerTest.php` extended to cover `createFromTemplate` (success → 201 + cloned objects exist with prefixed schemas; slug-collision → 4xx, no writes; cross-org → 4xx, no writes; manifest schema-reference rewrite walker covers nested page-config `schema` fields).
- [ ] 4.2 **PHPUnit** — `tests/Integration/TemplateSeedTest.php` runs `SeedApplicationTemplates` twice, asserts four templates per organisation each time; corrupts a fixture and asserts loud failure (REQ-OBTC-009).
- [ ] 4.3 **Newman** — `tests/api/openbuilt-templates.postman_collection.json` covers `POST /api/applications/from-template/{slug}` (201 + 4xx slug-collision + 4xx cross-org) plus standard OR-REST CRUD on `ApplicationTemplate` (200 list, 404 unknown).
- [ ] 4.4 **Playwright** — `tests/e2e/template-gallery.spec.ts` opens the OpenBuilt shell, navigates to `/templates`, asserts four template cards render, filters to `government-services`, asserts only `permit-tracker` is visible, clicks "Use this template", completes the slug prompt, asserts the post-clone redirect, and asserts the cloned Application's first page renders with the cloned companion schema's data.

## 5. Documentation (ADR-009, ADR-010)

- [ ] 5.1 Add `docs/openbuilt-templates.md` describing the gallery, the clone semantics, the slug-prefix convention for cloned companion schemas (Decision 3), and the one-shot snapshot behaviour (Decision 5 / REQ-OBTC-007).
- [ ] 5.2 Update `docs/integrator-guide.md` with a "Cloning from a template" walkthrough that links to the gallery and explains where the cloned schemas live.
- [ ] 5.3 NL Design (ADR-010) — confirm the gallery uses Nextcloud CSS variables only; document any new variables added.
- [ ] 5.4 Update `openspec/app-config.json` to list `openbuilt-template-catalogue` under capabilities.

## 6. i18n (ADR-005, ADR-007)

- [ ] 6.1 Add English translations in `l10n/en.json` for `openbuilt.templates.menu.label`, `openbuilt.templates.gallery.title`, `openbuilt.templates.filter.category`, `openbuilt.templates.filter.search`, `openbuilt.templates.action.use`, `openbuilt.templates.action.useThis`, `openbuilt.templates.empty.cta`, the four template `title` / `description` / `useCase` keys (`openbuilt.templates.{slug}.title|description|useCase`), every manifest `menu[].label`, and every manifest page `title` in the four seeded manifests.
- [ ] 6.2 Add Dutch translations for the same keys in `l10n/nl.json` so the gallery and the seeded templates are bilingual on install (per the project-wide nl/en minimum).
- [ ] 6.3 Confirm the four seeded manifests use translation keys for every user-visible string (per ADR-024 §6).
