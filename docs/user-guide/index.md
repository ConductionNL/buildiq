---
sidebar_position: 5
title: User guide
description: How to use OpenBuilt — from first-launch to publishing your first app.
---

# User guide

This section walks an end user through the moments that matter — first launch, building an app, publishing it, sharing it with colleagues. The tutorials underneath this index carry the screenshots (refreshed automatically via Playwright; see [Technical](../Technical/) for the gate).

## Tutorials

Each tutorial is a short, screenshot-driven walkthrough living under [`docs/tutorials/`](https://github.com/ConductionNL/openbuilt/tree/main/docs/tutorials):

**User track:**

- `01-first-launch` — what you see the first time you open OpenBuilt as an admin (empty Virtual apps index + "New application" CTA, after the legacy hello-world seed was retired).
- `02-create-from-template` — pick a starter from the templates marketplace; the wizard pre-fills identity + permissions.
- `03-design-schema` — author your first schema (fields, types, validation).
- `04-design-page` — add an index + detail page; bind columns; the manifest is your declarative source of truth.
- `05-connect-data` — pull in data from a sibling app via OpenConnector; the integration sidebar makes it visible.
- `06-preview-app` — `?_version=development` URL pin lets you bookmark a staging-only preview link to share with stakeholders before publish.
- `07-version-snapshots` — promote development → staging → production; rollback is a single click on `productionVersion`.
- `08-export-app` — Phase 2 export bakes the manifest + register + schemas into a standalone Nextcloud app (in flight).

**Admin track:**

- `01-rbac` — per-app permissions; the `group:*` wildcard pattern; how the top-bar entry gate works.
- `02-template-catalogue` — promote one of your existing apps to a public template so colleagues can clone it.
- `03-admin-settings` — Nextcloud-level admin settings for OpenBuilt (group restrictions, default presets).

## Reference

- [Integrator guide](../../integrator-guide) — author a virtual app by hand (the JSON-first path for power users).
- [OpenBuilt runtime](../../openbuilt-runtime) — what happens between a manifest record and the rendered SPA.
- [RBAC operator guide](../../openbuilt-rbac) — `occ app:enable openbuilt` + group restrictions + per-Application permissions.
