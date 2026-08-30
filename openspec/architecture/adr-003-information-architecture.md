# ADR-003: Information architecture — 5 top-level menus, designers as sub-tools

**Status**: accepted

**Date**: 2026-05-23

## Context

buildiq is the low-code application builder where citizen developers and ambtenaren assemble register-based apps from schemas, pages, workflows, and business rules. The chain ships roughly a dozen specs (`buildiq-application-register`, `buildiq-page-designer`, `buildiq-schema-designer`, `workflow-designer`, `business-rules-engine`, `buildiq-template-catalogue`, `gemma-starter-pack`, `buildiq-runtime`, `buildiq-rbac`, `buildiq-exporter`, `buildiq-version-snapshots`, `environments-deployment-pipeline`) plus assorted infra concerns.

The naive sidebar would put each spec on its own top-level menu — twelve-plus items in a sidebar, no clear daily home for the maker, and four "Designer" menus competing with the surfaces they edit. That violates the fleet-wide 5–7 top-level menu ceiling and forces the maker to context-switch between "where my app lives" and "where I edit it".

Two persona groups must be served without splitting the app:

- **Maker** (citizen-dev / ambtenaar) — spends 90% of their time editing one app: its schemas, pages, workflows, rules, RBAC. Needs Apps as a home, designers launched in app context, Catalog for templates.
- **Operator / release manager** — promotes between environments, runs pipelines, manages snapshots. Needs Deploy and Beheer.

A cross-cutting IA design pass (`/tmp/ia-small5.md`, 2026-05-22) covered buildiq alongside four sibling apps (financeq, purchaseq, planix, scholiq) and applied the same compression discipline: collapse tier-suffixed and adapter specs into sub-pages/tabs/widgets, demote infrastructure to per-resource tabs, keep top-level menus bounded between 4 and 6.

## Decision

Adopt a **5-menu top-level navigation** for buildiq:

1. **Apps** — Mijn apps, Alle apps, App-detail, App-runtime preview
2. **Designers** — Page Designer, Schema Designer, Workflow Designer, Business Rules Designer (as sub-tools)
3. **Catalog** — Templates, GEMMA-starterpack, Component-bibliotheek, Imports
4. **Deploy** — Omgevingen, Pipelines, Releases, Snapshots
5. **Beheer** — RBAC-rollen (global), runtime-config, snapshot-policy, catalog-feeds, pipeline-templates, connectors, logs, audit

The twelve+ specs collapse into this shape per the mapping in §IA-mapping below.

### Numbered design rules

**Rule 1 — Designers are tools, not destinations.**

The four visual editors (Page, Schema, Workflow, Business Rules) are always launched in the context of an app. The Designers menu is a discovery surface that lists the four sub-tools; clicking a designer without an app first opens a "kies een app" picker, never a blank canvas.

*Rationale:* a designer without a target schema/page/workflow has nothing to edit. Promoting each designer to its own top-level menu forces the maker to remember which app they were editing and re-select it; keeping designers tool-shaped keeps the App detail as the maker's home.

*How to apply:* register the four designers as routes under `/designers/{page|schema|workflow|rules}` that require an `app` query param; when the param is missing, render the app picker rather than the editor. The App detail page exposes "Pagina bewerken / Workflow bewerken / Regel bewerken" actions that open the designer with the context pre-filled.

**Rule 2 — Runtime, RBAC, Exporter, Snapshots are infrastructure: surface as per-app tabs, configure globally in Beheer.**

Each of these concerns has both a per-app dimension (this app's runtime health, this app's roles, this app's export bundle, this app's snapshot history) and a global dimension (resource limits, reusable role templates, export-format defaults, retention policy). The per-app dimension lives as a tab on the App detail (`Overzicht / Schemas / Pagina's / Workflows / Rules / RBAC / Versies / Runtime/Preview / Export / Deploy-status / Audit`). The global dimension lives under Beheer.

*Rationale:* a maker tweaking roles for one app should stay inside that app; a separate top-level "RBAC" menu would force them to leave their work, find the app in a list, and lose context. Operators configuring shared policy work in Beheer where all global config lives together.

*How to apply:* never add a top-level menu for an infrastructure concern that already has a per-app expression. When in doubt: if the artefact belongs to one app, it is a tab on that app; if it applies across apps, it is a Beheer sub-page.

**Rule 3 — Catalog and Deploy stay separate menus despite both being "lifecycle".**

Catalog is template-shopping for citizen-devs (browse, install, fork, publish); Deploy is release management for operators (promote snapshot, rollback, run pipeline). Their audiences and workflows are distinct.

*Rationale:* merging them under one "Lifecycle" menu would mix decision-making (what to install) with execution (what to promote). The personas barely overlap — a citizen-dev rarely promotes to prod, an operator rarely picks a template — so a unified menu forces both to scan past content they don't use.

*How to apply:* keep Catalog and Deploy as siblings. Cross-link only where the user flow demands it (e.g. "this template, after install, deploys to dev by default" is a Deploy hint inside Catalog, not a shared menu).

**Rule 4 — GEMMA-starterpack is a curated sub-page inside Catalog, not a top-level menu.**

The GEMMA-pack for overheid use cases is highly visible and politically important, but it is one (well-curated) template feed among many.

*Rationale:* promoting GEMMA to its own top-level menu would imply buildiq is a GEMMA tool first and an app builder second, and would force every non-overheid maker to scan past it. Inside Catalog, GEMMA gets prominence via ordering and badging without distorting the IA.

*How to apply:* keep `gemma-starter-pack` mapped as `Catalog > GEMMA-starterpack`. Give it a visual treatment (top of the list, conformity-score badge) inside Catalog rather than a sidebar slot.

### IA mapping (chain specs → IA placement)

| spec_slug | placement | parent |
|---|---|---|
| `buildiq-application-register` | menu | Apps |
| `buildiq-page-designer` | sub-tool | Designers > Page Designer |
| `buildiq-schema-designer` | sub-tool | Designers > Schema Designer |
| `workflow-designer` | sub-tool | Designers > Workflow Designer |
| `business-rules-engine` | sub-tool | Designers > Business Rules Designer |
| `buildiq-template-catalogue` | menu | Catalog |
| `gemma-starter-pack` | sub-page | Catalog > GEMMA-starterpack |
| `buildiq-runtime` | tab | Apps > app > Runtime/Preview (+ global in Beheer) |
| `buildiq-rbac` | tab | Apps > app > RBAC (+ global in Beheer) |
| `buildiq-exporter` | tab | Apps > app > Export (+ global in Beheer) |
| `buildiq-version-snapshots` | tab | Apps > app > Versies (+ snapshot-policy in Beheer) |
| `environments-deployment-pipeline` | menu | Deploy |

### Implementation phases (informational, mirrored from IA doc)

- **Phase 1 (MVP):** Apps (register + detail) + Schema Designer + Page Designer + Beheer (RBAC global, runtime-config).
- **Phase 2 (workflow + rules):** Workflow Designer, Business Rules Designer, runtime expansion (events + scheduled jobs), per-app RBAC.
- **Phase 3 (catalog):** Template catalogue, GEMMA-starterpack, exporter, version-snapshots, forken.
- **Phase 4 (deploy):** Environments + deployment pipeline, releases, snapshot-policy, drift-detection, rollback.

## Consequences

**Positive:**
- Sidebar stays at 5 items — well under the fleet 5–7 ceiling — so the maker's daily surface is scannable.
- App detail is the unambiguous home for the maker; designers launch from where the work lives.
- Maker and operator personas have distinct primary menus (Apps/Designers/Catalog vs Deploy/Beheer) without splitting the app.
- Infrastructure concerns (Runtime/RBAC/Exporter/Snapshots) have one global config home (Beheer) and one per-app surface (App detail tabs), so there is never a question of where a setting lives.
- New chain specs land in known IA slots: a new designer is a sub-tool, a new template feed is a Catalog sub-page, a new per-app concern is an App detail tab.

**Negative / trade-offs:**
- Designers cannot be deep-linked without an app context — a marketing page that says "try the Page Designer" has to either pick a demo app or accept the picker step.
- Infrastructure surfacing in two places (per-app tab + Beheer global) requires careful UX to avoid duplicating fields; the rule is "global = defaults + policy, per-app = overrides + status".
- GEMMA stakeholders may push for top-level prominence; Rule 4 has to be defended.
- Operators using only Deploy may find Apps/Designers/Catalog irrelevant — that's acceptable for v1, RBAC will eventually hide irrelevant menus per role.

## Alternatives considered

| Option | Reason not chosen |
|---|---|
| One top-level menu per chain spec (~12 items) | Violates 5–7 ceiling; no daily home; designers compete with the surfaces they edit |
| Single "Lifecycle" menu merging Catalog + Deploy | Mixes citizen-dev decision-making with operator execution; distinct personas forced to scan past irrelevant content |
| Designers as top-level (4 sibling menus) | Forces app-context switching; designer with no app context is meaningless; bloats sidebar |
| GEMMA-starterpack as top-level menu | Implies buildiq is a GEMMA tool first; non-overheid makers scan past it; one template feed shouldn't shape the IA |
| RBAC / Snapshots / Runtime as top-level menus | Drags the maker out of the app to manage one app's settings; per-app tab is where the work happens |

## Related

- Builds on the fleet IA design pass `ia-small5.md` (2026-05-22) that covered financeq, purchaseq, planix, scholiq, buildiq with the same compression discipline.
- Aligns with `adr-002-versioned-app-deployment-model.md` — the per-app Versies tab is the surface for the linear-chain promotion model from ADR-002.
- Aligns with `adr-001-app-assets-via-openregister-files.md` — app-level assets surface inside App detail tabs, never in a separate "Assets" menu.
- Downstream cascades: any new chain spec must be placed via the IA mapping table; new top-level menus require an ADR superseding this one.
