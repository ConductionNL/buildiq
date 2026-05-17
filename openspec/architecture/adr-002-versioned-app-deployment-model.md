# ADR-002: Versioned app deployment model (admin-defined version chain)

**Status**: accepted

**Date**: 2026-05-15

## Context

OpenBuilt's current versioning model (chain spec #6 `openbuilt-versioning`) treats every publish as a new `ApplicationVersion` snapshot row and points `Application.currentVersion` at the latest one. Two problems with this:

1. **No safe playground for admins.** An admin who wants to change a live app has to edit the manifest in place. The change is visible to end users immediately, and there is no way to "try the change first". Citizen-developer apps need a place to experiment without breaking production.
2. **Production data and test data share one register.** A virtual app's data lives in `openbuilt-{slug}`. If the admin adds a new schema for an experiment, that schema (and any seeded test data) immediately surfaces in production. There is no isolation between "ship-quality" data and "I'm tinkering" data.

We also accumulate a `currentVersion` UUID on the Application record that gets updated by a writeback listener after every publish — a denormalised cache that exists because we don't have a clean way to ask "which version is live right now?". The user's instinct was that the field is redundant; the deeper cause is that the model itself conflates "the version" with "the app".

A constraint we set early: **admins should define their own versions and CI/CD flow**. We do not pick the tier names. Some teams want a single "production"; others want `dev → staging → production`; a forms-app admin might just want `draft → live`. The model has to accommodate all of these without a schema change.

## Decision

Split the runtime model into two related OR objects, with the relation between them being a real first-class OR relation (not a UUID string):

- **`Application`** — the *logical* app. Owns slug, name, description, RBAC permissions, app-level assets (icons per ADR-001), and a relation `productionVersion → ApplicationVersion` that names which version end users see at the canonical URL.
- **`ApplicationVersion`** — a *deployable runtime*. Owns the manifest, a per-version register name, an admin-defined display `name` + URL-safe `slug` (e.g. `"Staging" / "staging"`), a per-version semver, a relation `application → Application` back to its parent, and a single optional relation `promotesTo → ApplicationVersion` declaring its one downstream target.

Each ApplicationVersion's *history* (manifest edits, schema edits) is captured by OR's audit-trail / object time-travel. We never spawn extra version rows just to record changes — rollback is OR time-travel on the version row.

**Admin-defined linear chain — not a fixed enum:**

The set of versions per app is whatever the admin defines. v1 ships **linear chains only**: each ApplicationVersion has at most one `promotesTo`. The chain reads naturally — `development → staging → production` — and the promotion UX is trivially "promote to: <next>". Branching DAGs (fan-out, fan-in) and CI/CD-style triggers (cron, event-driven auto-promotion) are explicitly out of scope for v1; they are roadmap items.

The terminal version of the chain (no `promotesTo`) is typically the production one, but "production-ness" is set explicitly on the Application via `productionVersion`. This survives chain reshaping — if an admin inserts a new "hotfix" version between staging and production, the production pointer stays intact.

**Creation wizard with presets:**

At app-creation time, the wizard offers four presets after the basic name/slug/description step:

- **Single** — one version (default name `production`). Smallest footprint; admin can add more later.
- **Dev + Prod** — two versions, `development → production`. The common "I want a playground" shape.
- **Dev + Staging + Prod** — three versions, `development → staging → production`. The classic shape.
- **Custom** — admin types in N version names in order; backend stitches the chain.

The wizard is the only place a chain is created in one shot; once an app exists, versions are added one at a time via the detail page.

**Per-version register for data isolation:**

Each ApplicationVersion owns its own OR register, named `openbuilt-{slug}-{versionSlug}` (e.g. `openbuilt-helloworld-production`, `openbuilt-helloworld-staging`). Schemas and objects live in that register. Beta tinkering — including schema-shape changes and seeded test rows — can never contaminate production data because the registers are physically separate. The shared `openbuilt` register continues to hold *system* schemas (Application, ApplicationVersion, ApplicationTemplate, …) per the hybrid-register memory.

**Routing — URL-param version switching, admin-only beyond production:**

- `/apps/openbuilt/{slug}` always serves the version pointed at by `Application.productionVersion`.
- `/apps/openbuilt/{slug}?version=<versionSlug>` serves the named version's manifest. Access to anything other than the production version requires the caller to have an editor/owner role on the Application (RBAC enforced server-side, not just hidden in the UI).
- The app detail page exposes a **version switcher** that flips the URL parameter; admins can bookmark a version-specific URL, end users at the canonical URL never see anything but production.

**Promotion — single downstream target, admin-chosen data semantics:**

A version's "Promote" action targets exactly its `promotesTo` neighbour (or is disabled when there is none). The promotion dialog asks the admin to choose one of:

- **Start target with source data** — copy rows from the source register into the target register (applying the source's schema set). Useful when "the test data IS the new shape of prod data".
- **Migrate target's existing data** — keep the target register's existing rows, apply the source's schema (running any schema-migration mappings the admin has declared). Useful for genuine app upgrades where production data must survive.
- **Empty start** — install the source's schema set into the target register without copying any rows. Useful for redesigns where production data is intentionally being reset.

The promotion itself is an atomic update of the target ApplicationVersion's manifest + schema set; the audit trail records the change. No historical rows are spawned — rollback is OR time-travel on the target version row.

**Promotion is manual for v1.** No cron, no event triggers. Auto-promotion is a roadmap item.

**Semver per version:**

Each ApplicationVersion carries its own semver. Drafts inside a version patch-bump on each save (`1.1.0-beta.1 → 1.1.0-beta.2`). Promoting *to* production drops the prerelease (`1.1.0-beta.5 → 1.1.0`). The next cycle on the upstream version starts from `<bumped-minor>.0-beta.1`. Major bumps remain manual. The `version` field on Application is **derived** on read — always reports the production version's semver.

**Application.currentVersion goes away:**

Replaced by the explicit `productionVersion` relation on Application. The snapshot writeback listener is retired. Queries that today ask "what's the latest snapshot?" become "what is `productionVersion.manifest`?", which is a relation hop, not a denormalised cache.

## Consequences

**Positive:**
- Admins get a real playground and can name it whatever fits their team's vocabulary.
- The model accommodates teams that want one version, two, three, or seven — no schema migration ever required to add a tier.
- Production data is structurally isolated from test data — no per-row tagging, no filter-or-else bugs.
- "Which version is live?" stops being a denormalised cache and becomes a relation hop.
- OR object time-travel covers the audit/history use case for free.
- Promotion semantics become explicit (admin picks data treatment) instead of implicit.

**Negative / trade-offs:**
- N registers per app (one per version) means more OR records to provision. Mitigated: registers are cheap; the creation wizard provisions all of them up front.
- Promotion is no longer "click publish" — it's a dialog with three data choices. Mitigated by sensible defaults per source/target pair (e.g. dev → staging defaults to "Start target with source data"; staging → production defaults to "Migrate target's existing data").
- URL routing now has a query-parameter dimension that must be respected by every link, deep-link emitter, and integration. The default (no param) is `productionVersion`, so external links stay correct.
- A migration is required: existing virtual apps need their `openbuilt-{slug}` register renamed to `openbuilt-{slug}-production`, and an Application + ApplicationVersion(name="production") pair must be backfilled from the existing flat Application row.
- Linear-only chains rule out branching workflows in v1. Documented limitation; a future ADR can extend the model to a DAG when there is concrete demand.

## Alternatives considered

- **Fixed `stable | beta | development` tier enum** — rejected on user direction. The admin's vocabulary varies; the model should not.
- **Single register with `version` tag on every object** — rejected: schema differences across versions cannot coexist on one OR schema; tagging also means every read query has to filter, multiplying the risk of accidental data leakage.
- **Keep one historical row per publish (current model)** — rejected: solves "I want to see what was published when" but does not solve "I want a place to test changes safely". The user identified the playground gap, not the audit gap.
- **DAG with fan-in/fan-out from day one** — rejected for v1. The UX becomes a graph editor and the promotion dialog has to disambiguate multiple downstreams; the visible demand is linear chains and we can extend later without breaking the linear case.
- **CI/CD-style auto-promotion (cron, event triggers)** — explicitly deferred. Adds scheduler integration + approval flows; not blocked by the data model, so it can land later without re-architecting.

## Related

- Supersedes the version-snapshot semantics from chain spec [`openbuilt-version-snapshots`](../specs/openbuilt-version-snapshots/spec.md) — that spec stays archived but its writeback model is retired by this ADR.
- Replaces user feedback on the `currentVersion` field (the field disappears; "which version is live" becomes the `productionVersion` relation).
- Cascades to:
  - Detail-page overview spec (`openbuilt-app-detail-overview`) — hero shows the version switcher; KPIs/activity graph scope to the selected version; structural widgets scope to the selected version's register.
  - Exporter spec (`openbuilt-exporter`) — export picks which version to bundle.
  - Schema designer routing — `/builder/{slug}/schemas` becomes version-aware (`?version=<slug>` or path segment).
- Builds on [[adr-001-app-assets-via-openregister-files]] — app-level assets live on the Application record, not on the version, so icons survive promotions and apply across all versions.
- Roadmap dependencies (out of scope for v1, but the model accommodates):
  - Promotion automation (cron / event triggers) — adds rules on the `promotesTo` edge without changing the data model.
  - Branching DAG promotion — extends `promotesTo` from a single relation to an array; the linear case continues to work.
