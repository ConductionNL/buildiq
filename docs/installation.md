---
sidebar_position: 6
title: Installation
description: Install OpenBuilt from the Nextcloud app store (or from source) and stand up your first app.
---

# Installation

OpenBuilt is a Nextcloud app. Install it like any other Nextcloud app — from the app store, or from source if you're tracking development.

## Prerequisites

### System

- Nextcloud 28 or higher (32 recommended).
- PHP 8.1 or higher (8.2+ recommended).
- A database: PostgreSQL 12+, MariaDB 10.5+, or MySQL 8.0+.
- 2 GB RAM minimum; 4 GB+ for production.
- 500 MB free disk space for OpenBuilt itself; expect more for the OR-attached files (icons, document attachments).

### Required PHP extensions

```
php-curl
php-gd
php-json
php-mbstring
php-xml
php-zip
```

### Required Nextcloud apps

OpenBuilt sits on top of [OpenRegister](https://github.com/ConductionNL/openregister) — the foundation app every Conduction product builds on. Install OpenRegister first; OpenBuilt will refuse to enable without it.

Optional but recommended siblings (each unlocks specific integrations):

- [openconnector](https://github.com/ConductionNL/openconnector) — external system integrations (G2, Hilma, your ERP, ...).
- [procest](https://github.com/ConductionNL/procest) — business workflows / state machines across apps.
- [docudesk](https://github.com/ConductionNL/docudesk) — document generation (PDF / DOCX from records).
- [nldesign](https://github.com/ConductionNL/nldesign) — NL Design System theming (WCAG AA + Dutch-government look).
- [mydash](https://github.com/ConductionNL/mydash) — cross-app dashboards.

## From the Nextcloud app store

The fastest path:

1. Sign in to Nextcloud as an admin.
2. Open `Apps` → search for `OpenRegister` — install + enable.
3. Search for `OpenBuilt` — install + enable.
4. Navigate to `https://your-nextcloud/index.php/apps/openbuilt` to land on the Virtual apps index.
5. Click `New application` to launch the wizard. See the [`02-create-from-template` tutorial](./user-guide/) for screenshots.

## From source (developer install)

If you're tracking development or contributing patches:

```bash
# 1. Clone OpenRegister + OpenBuilt into Nextcloud's custom_apps
cd /path/to/nextcloud/custom_apps
git clone https://github.com/ConductionNL/openregister.git
git clone https://github.com/ConductionNL/openbuilt.git

# 2. Install PHP + JS dependencies
cd openregister && composer install && npm ci && npm run build
cd ../openbuilt   && composer install && npm ci && npm run build

# 3. Enable
occ app:enable openregister
occ app:enable openbuilt

# 4. Run the install + post-migration repair chain
occ maintenance:repair
```

The repair chain runs `InitializeSettings` (imports the openbuilt register from `lib/Settings/openbuilt_register.json` into OR), `MigrateToVersionedModel` (idempotent — short-circuits on a fresh install), `PopulateApplicationPermissions` (gives the deploying admin owner role on any pre-existing Applications), and `SeedApplicationTemplates` (loads the four starter templates into the templates marketplace).

## Restrict OpenBuilt to specific groups (optional)

OpenBuilt enables to all signed-in users by default. To gate it (REQ-OBRBAC-006):

```bash
occ app:enable --groups=team-alpha,team-beta openbuilt
```

Per-Application RBAC stays load-bearing — even with the top-bar gate open, individual Applications respect their `permissions.{owners,editors,viewers}` blocks.

## Post-install checks

1. **Top-bar entry** — `OpenBuilt` should appear in your Nextcloud top bar.
2. **API surface** — `curl -u admin:admin -H "OCS-APIRequest: true" https://your-nextcloud/index.php/apps/openbuilt/api/applications` returns `[]` (no apps yet) or the seeded set.
3. **Wizard** — clicking `New application` opens the four-step modal. Pick the `dev-prod` preset, give your app a slug, click through to create. The dev container should provision two ApplicationVersions (development → production) + two per-version registers.
4. **Built-app route** — your new app is reachable at `/apps/openbuilt/{slug}` once you publish the development version.

If any of these fail: `occ log:tail` + check the OR repair-step output. Common gotchas:

- OpenRegister not enabled → OpenBuilt refuses to enable. Enable OR first.
- Database migration not run → run `occ upgrade` after a code update.
- Cached APCu → `php -r "apcu_clear_cache();"` clears the APCu side; `apache2ctl graceful` reloads OPcache.

## Next steps

- Read the [User guide](./user-guide/) for the citizen-developer walkthrough.
- Read the [Integrator guide](./integrator-guide) if you want to author manifests by hand.
- Read [Technical](./Technical/) for the ADR list, the test gate matrix, and the MCP tool catalogue.
