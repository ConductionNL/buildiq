---
kind: code
depends_on: [adopt-connection-registry]
screens: [BqAppWerking, BqOntwerperKoppelingen]
---

# Proposal: built-app-connections-page

## Why

`adopt-connection-registry` gives buildiq itself an Integrations page: the four connections buildiq uses (store, GitHub, documents, rule webhooks). An app built in buildiq has its own connections too. Its manifest binds integriq sources as data sources (`openconnector-api-sources`), and integriq registers its integrations as `app_connection` rows. Nothing lists them per app, so a maker cannot see which outside systems an app depends on or whether they answer. Ruben decided on 13 November 2026 that every app built in buildiq gets its own connections page (decision 412).

## What Changes

- A Connections tab on the application detail page for every app built in buildiq.
- It lists the app's sources (the integriq sources its manifest binds as data sources) and its integrations (integriq `app_connection` rows whose `app` is the app's slug), one row each.
- Each row shows a health status with the time it was last checked, using integriq's connection statuses, and "Not checked" when integriq has no result.
- Without integriq the tab still lists the manifest's sources and says that health needs integriq.
- Only users who may edit the app, and admins, see the tab.
