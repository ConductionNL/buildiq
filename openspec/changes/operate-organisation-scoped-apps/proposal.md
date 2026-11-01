---
kind: code
---

# Proposal: operate-organisation-scoped-apps

## Why

buildiq matrix, row `ops-multi-tenant`, "Run apps for several organisations on
one installation with their data kept apart.", rated `no`. No tender,
featureRequest or roadmap row carries it. Two competitors rated `yes`:

- NocoBase: "packages/plugins/@nocobase/plugin-multi-app-manager/src/server/server.ts:185
  each sub-application gets its own database created on first start, with an
  independent JWT secret for session isolation ... Reached on: Multi-app manager,
  Add new per tenant".
- Budibase: "packages/backend-core/src/tenancy/tenancy.ts:12-44 isMultiTenant
  switches every database to a per-tenant prefix, set by MULTI_TENANCY ... users
  pick their organisation on packages/builder/src/pages/builder/auth/org.svelte:19-28".

What buildiq does today, per the matrix `built.evidence`:
"lib/Service/ManifestResolverService.php:233-236 explicit comment: applications
'operate in the global (non-tenant-isolated) scope. Buildiq ships... are not
partitioned by tenant/organisation. Cross-tenant slug [collisions possible]';
lib/Controller/ApplicationsController.php:2193 comment warns a tenant-partitioning
change 'breaks every existing single-tenant install'". The matrix note calls it
"a deliberate, documented architectural limitation". The code bears that out:
buildiq passes `_multitenancy: false` in 127 places under `lib/`, so every service
sees every Application whatever the caller's organisation.

The data layer already keeps organisations apart. OpenRegister filters registers
and objects by the caller's active organisation (`applyOrganisationFilter()`,
`openregister/lib/Db/MultiTenancyTrait.php:380`), resolves membership and the
active organisation (its `tenant-lifecycle` spec, "OrganisationService MUST
resolve per-user organisation membership and active context"), and audits
cross-tenant access (`tenant-isolation-audit`). What buildiq lacks is the rule
for its own objects: which organisation an app belongs to, and who may see it.

The lane's decision record says the business-rules-engine change lists per-tenant
rules as its own non-goal. That change's design does not: its non-goals
(`openspec/changes/archive/2026-06-14-business-rules-engine/design.md:24-29`) name
BPMN, machine learning, nested conditions and streaming, and its goals include
"Enforce per-tenant scoping". The decision to build stands on the two competitor
cells either way.

## What changes

- An app has an organisation scope: "All organisations" or "This organisation
  only". Every existing app keeps "All organisations", which is today's
  behaviour. A new app starts as "This organisation only" when OpenRegister's
  multitenancy is on, held by the creator's active organisation.
- A scoped app is visible, openable and in the navigation only for members of its
  organisation. To anyone else it answers as an app that does not exist, on every
  path: the app list, the manifest endpoint, the runtime, the navigation and the
  MCP tools.
- A scoped app's registers and records are held by its organisation, so
  OpenRegister keeps its data apart too.
- Slugs stay unique on the instance, so no existing install or link breaks. A slug
  taken in another organisation is refused without naming that organisation.
- Installing from the store or a GitHub repository, and importing an archive,
  create the app in the installer's active organisation.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | ops-multi-tenant | Run apps for several organisations on one installation with their data kept apart. | no | apps that belong to an organisation and are hidden, with their data, from every other organisation |

## Existing work it builds on

- `openspec/specs/openbuild-rbac`: owners, editors and viewers per app. The
  organisation scope is checked before the role, and never replaces it.
- `openspec/specs/version-routing` and `openspec/specs/openbuild-runtime`: the
  manifest endpoint and the runtime, which gain the scope check.
- `openspec/specs/app-nav-entries`: the navigation entry per published app.
- `openspec/specs/openbuild-template-catalogue`, `github-shop-catalogue` (open) and
  `lifecycle-import-app-and-cli` (this pass): the install and import paths, which
  set the holder.
- openregister `tenant-lifecycle`, `tenant-isolation-audit` (specs, done):
  organisations, membership and the audit of cross-tenant access.

## Sibling halves

- openregister: one behaviour to confirm, not a new feature. The app list page
  reads Application objects through OpenRegister's objects API with its default
  organisation filter, while buildiq's services read them with the filter off. An
  "All organisations" app held by one organisation must stay visible on that page
  to members of the others. If OpenRegister hides it, the rule that lets a holder
  share rows with other organisations (REQ-SLE-001 in its open change
  `several-legal-entities-in-one-instance`) is the mechanism, applied to
  Application objects whose scope is "All organisations". Task T02 checks this on
  a live instance before any code is written.

## Out of scope

- Moving an app from one organisation to another.
- One app definition serving several organisations with separate records each.
  Several organisations each install their own app, from the same template if
  they like.
- Sharing data registers between organisations (OpenRegister's shared master
  data).
- Per-organisation rule sets. `RuleEngineService` reads rule sets with the
  organisation filter off on purpose (`lib/Service/RuleEngineService.php:275-279`).
