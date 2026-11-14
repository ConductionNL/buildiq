---
kind: code
depends_on: []
---

# Proposal: platform-properties

## Summary

Twelve capability rows describe what buildiq and the apps it builds are, not a screen in the builder. They run on your own Nextcloud, sit behind the Nextcloud login, adapt to a phone, cost no seat fee and use the language model the instance provides. All twelve are built, mostly by Nextcloud server or the shared nextcloud-vue renderer. No requirement says them, so nothing can cite them and nothing stops a later change from breaking one.

This change writes them down as a `platform-properties` spec. Each requirement names who provides the property and what buildiq must do, or must not do, to keep it. The tasks add the `@spec` tags and the checks that keep each property true.

## The rows this covers

Source: buildiq `openspec/parity/capabilities.json`.

| row | provided by | requirement |
|---|---|---|
| `pg-responsive` | nextcloud-vue renderer | REQ-PLAT-001 |
| `ai-self-hosted-model` | Nextcloud Task Processing | REQ-PLAT-002 |
| `acc-sso` | Nextcloud server login | REQ-PLAT-003 |
| `acc-csrf-xss` | Nextcloud CSRF middleware, Vue escaping | REQ-PLAT-004 |
| `ux-wcag` | nextcloud-vue renderer | REQ-PLAT-005 |
| `ux-keyboard` | nextcloud-vue and Nextcloud Vue components | REQ-PLAT-006 |
| `ops-self-host` | Nextcloud server | REQ-PLAT-007 |
| `ops-one-platform` | Nextcloud server | REQ-PLAT-008 |
| `ops-license-cost` | buildiq (EUPL-1.2) | REQ-PLAT-009 |
| `ops-scale-out` | Nextcloud server | REQ-PLAT-010 |
| `acc-directory-sync` | Nextcloud LDAP backend (`user_ldap`) | REQ-PLAT-011 |
| `acc-two-factor` | Nextcloud two-factor providers | REQ-PLAT-012 |

## What changes

1. A new capability `platform-properties` with one requirement per row.
2. `@spec` tags on the files that carry each property.
3. Checks that keep the properties true: a unit test over the controllers for login and CSRF, a unit test over `appinfo/info.xml` for the licence and the absence of seat checks, and a guard that the copilot reaches a model only through Task Processing.
4. Two gaps the requirements expose, each with a task: export staging lives in the local temp directory, so a download can miss on a second web server (REQ-PLAT-010); and an action or widget with an empty label passes validation (REQ-PLAT-005).

## Screens

One row has a builder screen: `ai-self-hosted-model` shows on BqCopilot and BqNieuweApp, where the copilot runs on the model the instance provides. The other eleven are properties, see `screens.md`.

## Out of scope

- Configuring login, LDAP, two-factor or Task Processing providers: Nextcloud server owns those.
- The responsive and accessible behaviour of each nextcloud-vue component: nextcloud-vue owns that.
- Pricing and support contracts, which are not software behaviour.
