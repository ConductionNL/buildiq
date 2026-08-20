---
sidebar_position: 2
title: Features
description: What ships in OpenBuilt today — the citizen-developer app builder for Nextcloud.
---

# Features

OpenBuilt is a citizen-developer app builder for Nextcloud. Every feature below is shipped and verified by the spec gate (PHPUnit + Vitest + Newman + Playwright + Hydra mechanical gates — see [Technical](../Technical/) for the matrix).

## App creation wizard

A three-step modal that creates the parent Application, an admin-defined version chain (development → staging → production by default), and one per-version OpenRegister register per tier. The wizard is atomic: a failure at any step rolls back every resource it provisioned so the org-wide unique slug isn't squatted.

Highlights:

- **Identity step** — slug + name + description with live duplicate-slug detection.
- **Versions step** — pick a preset (`single`, `dev-prod`, `dev-staging-prod`) or hand-design the chain. The wizard enforces ADR-002's linear-chain rule (no fan-out, no cycles, exactly one terminal `production` tier).
- **Permissions step** — owners / editors / viewers, with the caller pre-filled into `owners`. Group `group:*` means "all signed-in users" (REQ-OBRBAC-004).

Spec: [`openbuilt-app-creation-wizard`](https://github.com/ConductionNL/openbuilt/tree/main/openspec/changes/archive/2026-05-17-openbuilt-app-creation-wizard).

## Versioned app deployment

Per ADR-002, every Application carries N ApplicationVersion rows on a linear `promotesTo` chain. Each version owns:

- a per-version OpenRegister register (`openbuilt-{appSlug}-{versionSlug}`) so dev data, staging data, and production data are physically isolated;
- its own manifest (the page + menu + widget declaration);
- its own semver — auto-bumped when the manifest changes meaningfully, untouched on metadata-only edits (the no-op-detect rule).

The `Application.productionVersion` pointer decides which version answers at `/apps/openbuilt/{slug}`. Switching `productionVersion` is a single OR write — rollback is instant, no redeploy.

Spec: [`openbuilt-versioning-model`](https://github.com/ConductionNL/openbuilt/tree/main/openspec/changes/archive/2026-05-17-openbuilt-versioning-model).

## Version promotion (dev → staging → prod)

Three strategies for moving one version forward into the next:

- **`migrate-existing-data`** — schema-import the source version's columns onto the target's existing rows (default when target IS the production version; preserves production data).
- **`start-with-source-data`** — wipe target rows, copy source rows over (default for mid-chain targets; reproducible from-source build).
- **`empty-start`** — wipe target rows entirely and start clean. Gated by a type-the-slug confirmation in the UI.

Failure handling: target flips to `archived`, OR lock releases, response carries `code: "promotion_failed"`. Source register is read-only throughout — a failed promotion never mutates the source.

Spec: [`openbuilt-version-promotion`](https://github.com/ConductionNL/openbuilt/tree/main/openspec/changes/archive/2026-05-17-openbuilt-version-promotion).

## `?_version=` URL routing (bookmarkable preview)

Every builder URL accepts an optional `?_version=<slug>` query that pins the rendered manifest to a specific ApplicationVersion. The leading underscore (`_version`, not `version`) is OpenBuilt's system-reserved namespace so citizen developers can ship their own `?version=` query params without collision.

- Without the param: the production manifest answers — accessible to everyone in the org.
- With the param + production slug: also accessible to everyone.
- With the param + non-production slug: only callers in `permissions.editors ∪ permissions.owners` see it. Viewers / non-members get a security-shaped 404 (no existence leak).

Spec: [`openbuilt-version-routing`](https://github.com/ConductionNL/openbuilt/tree/main/openspec/changes/archive/2026-05-17-openbuilt-version-routing).

## Application detail overview (maintainer dashboard)

The detail page for each Application packs the maintainer's daily working surface into one screen:

- **Hero strip** — icon, name, description, status, your role badge, productionVersion semver.
- **Version pill strip** — one pill per version (most-upstream first), with `*` marking production. Click a pill to switch the rest of the page to that version.
- **Window toggle (7d / 30d / 90d)** — drives the KPI + activity calls.
- **KPI grid** — Active users, Object count, Files, Audit events for the selected (version, window).
- **Activity graph** — daily event counts; empty-state when the window has no traffic.
- **Structural widgets** — Register, Schemas, Pages, Menu cards with deep-links into OpenRegister, Schema Designer, Page Designer.

Spec: [`openbuilt-app-detail-overview`](https://github.com/ConductionNL/openbuilt/tree/main/openspec/changes/archive/2026-05-17-openbuilt-app-detail-overview).

## Nextcloud nav integration (one top-bar entry per published app)

Every published Application gets its own top-bar entry alongside Files, Mail, Talk etc. The entry's icon is served by `GET /apps/openbuilt/icons/{slug}.svg` (light) / `{slug}-dark.svg` (dark) and falls back to the app icon when the OR-attached file is missing.

Visibility is per-app: only callers who hold any role on the Application (or are listed via `group:*` wildcard) see the entry. Draft and archived apps stay hidden.

Spec: [`openbuilt-nextcloud-nav`](https://github.com/ConductionNL/openbuilt/tree/main/openspec/changes/archive/2026-05-17-openbuilt-nextcloud-nav).

## Templates marketplace

The "New from template" path clones a marked Application + its companion schemas into the caller's namespace. The same-user-same-slug case returns `slug_collision` 409 so the caller can pick a fresh slug; the cross-user case provisions an owner-namespaced register (`openbuilt-{ownerUid}-{slug}`) so two users can clone the same template without colliding on OR's organisation-wide unique constraint.

## Schema designer

A visual editor for the per-version schema set. Field types, lifecycle states + transitions, RBAC, relations to other schemas, widgets, aggregations, calculations and notifications all live in the schema's declarative annotations (ADR-031) — readable by humans, executable by OpenRegister at runtime.

## Page editor v1.1

JSON-driven page declaration with type-aware sub-editors per page type (`index`, `detail`, `form`, `dashboard`, `chat`, `logs`, `settings`, `files`, `custom`). Inline validator marks paint the offending field on save; an undo/redo stack covers the in-flight manifest; the Raw JSON tab is the integrator fallback for shapes the Design tab can't yet author.

## MCP authoring surface (for AI assistants)

OpenBuilt registers eight tools on the OpenRegister MCP bus so an LLM can author apps directly: `listApps`, `getAppManifest`, `createApp`, `promoteVersion`, `upsertSchema`, `upsertPage`, `addWidget`, `upsertMenuItem`. Each tool carries the per-app RBAC gate; an LLM acting on behalf of a user can only modify apps where that user holds owner or editor role.

## Export to a real Nextcloud app (Phase 2)

A built app is *virtual* by default — it lives as a record in OpenBuilt's register and is rendered inside the OpenBuilt shell at `/apps/openbuilt/{slug}`. Phase 2 export bakes the manifest, register, and schemas into a standalone Nextcloud app you can install on any Nextcloud instance — no OpenBuilt dependency at runtime.
