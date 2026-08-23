<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Automations

The Automations page is the unified "when X happens, do Y" surface: one
citizen-developer composes a **trigger**, an optional **condition**, and one
or more **actions** without needing to know which of Buildiq's four
declarative dialects actually executes it. Nothing new is invented — every
automation compiles to an existing primitive (the notifications dialect,
lifecycle actions, `manifest.schedules[]`, or the business-rules engine).

An automation is one stored declarative object (schema `automation` on the
shared `buildiq` register): trigger + condition + actions + a `provenance`
block listing exactly which compiled artifacts it produced. List, edit,
enable/disable, dry-run and delete it as one unit from the Automations page
(`/automations`, reached from within an app's builder — it has no top-level
menu entry, mirroring the Business rules page).

## What compiles to what

| Trigger ↓ / Action → | Send notification | Run synchronization | Object-op | Webhook | Require approval | Generate document |
| --- | --- | --- | --- | --- | --- | --- |
| Object created / updated / deleted | ✅ notifications dialect entry | ⛔ v1.1 | ⛔ v1.1 | ⛔ v1.1 | ✅ OR `ApprovalChain` | ✅ owner-impersonated Docudesk call |
| Lifecycle transition | ✅ notifications dialect entry | ⛔ v1.1 | ✅ lifecycle `related-object-upsert` action | ✅ lifecycle `webhook-dispatch` action | ✅ OR `ApprovalChain` | ✅ owner-impersonated Docudesk call |
| Schedule | ⛔ v1.1 | ✅ `manifest.schedules[]` entry | ⛔ v1.1 | ⛔ v1.1 | ⛔ no bound object | ⛔ no bound object |
| Manual | ✅ rules backend | ⛔ v1.1 (no verified "run now" API — see below) | ✅ rules backend | ✅ rules backend | ⛔ no bound object | ⛔ no bound object |

**Require approval** compiles to an OpenRegister `ApprovalChain` (one step,
group-only assignee) instantiated against the fired object's uuid at
trigger-fire time; on-approve/on-reject follow-up actions dispatch through
the same typed-action vocabulary as the top-level actions above.

**Generate document** picks a Docudesk template and one or more output modes
(`attach` — writes the rendered document to Nextcloud Files and sets a
`{ "ref": "<fileId>" }` reference on the object; `download-link` — a
short-lived, ~24h signed download URL; `notify` — a notification, must be
paired with `attach` and/or `download-link`). Compiles to no persisted
artifact (Docudesk's generate route is stateless) — a listener calls
Docudesk's existing `correspondence/generate` route at trigger-fire time,
impersonating the Application owner's session, never importing a Docudesk
PHP class. Disabled with a missing-app hint when Docudesk is not installed.
Both **Require approval** and **Generate document** need a concrete fired
object to act on, so neither is expressible on `schedule`/`manual` triggers.

A blocked (⛔) combination is refused **in the editor**, with a message
naming the unsupported combination — nothing is ever silently dropped or
partially compiled.

**Conditions** (a FEEL expression, or a reference to an existing rule set)
are v1-supported only on the **manual** trigger — the rules engine is the
only existing primitive that evaluates FEEL. A condition on any other
trigger is blocked the same way.

> **Deviation from the original design table:** `manual` +
> `run-synchronization` is blocked in this release. No primitive to invoke an
> OpenConnector synchronization on demand exists anywhere in Buildiq today
> (the only existing trigger for a sync run is the scheduled-tasks
> reconciler) — see `lib/Service/AutomationCompilerService.php`'s class
> docblock for the full rationale. This is a documented v1.1 follow-up, not a
> silent gap.

## Provenance and drift

Every compiled artifact's id/key carries an `aut-<slug>` prefix (rule sets
use `aut-<uuid8>`, since rule-set slugs are shared platform-wide). The
automation's `provenance` block records exactly which artifacts the last
compile produced plus a content hash. This makes compilation:

- **Deterministic** — the same automation definition always compiles to the
  same artifacts.
- **Idempotent** — recompiling an unchanged automation is a no-op.
- **Reversible** — deleting the automation removes exactly the
  provenance-listed artifacts and nothing else; a hand-authored entry on the
  same schema (a key without the `aut-` prefix) is never touched.

Opening the Automations page recomputes each row's drift status by comparing
the live artifacts against the stamped hash. A hand-edit to a compiled
artifact (e.g. tweaking a schedules entry directly in the page designer)
shows a **drift** badge; **Recompile (overwrite)** restores it — the
automation definition always wins.

## Enable / disable

Disabling an automation recompiles with every artifact's own enabled switch
turned off (a notification entry's `enabled: false`, a schedules entry's
`enabled: false`, a rule's `actief: false`); a lifecycle-transition action has
no per-action enabled flag, so it is removed from the transition's
`actions[]` while `provenance` retains it for a cheap re-enable. Artifacts
never disappear from storage while disabled — re-enabling is just another
recompile.

## Dry-run

The test panel (mirrors the business-rules test sandbox) compiles the
automation **in-memory** to its rules-backend representation — regardless of
its actual trigger — and evaluates it through the same rules engine with
`dryRun: true`. This gives a single, uniform preview surface for every
matrix cell without a persisted rule set (an event- or schedule-triggered
automation never has one) and without ever dispatching a real side effect.

## RBAC

Authoring, dry-running and enabling on a non-production version requires the
caller to be an **owner or editor** on the parent Application. Enabling an
automation on the version currently set as the Application's **production**
version requires an **owner** — mirroring the version-promotion posture,
with no Nextcloud-admin bypass. Every check runs before any compile side
effect; a rejected call never touches a compiled artifact. Automation object
CRUD (create/edit/delete) itself goes through OpenRegister's REST surface, not
this controller — the compile/enable/disable boundary is the security
boundary, not the raw object write.

## Runtime API

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/api/automations/{uuid}/compile` | Recompile in place (upsert artifacts). |
| POST | `/api/automations/{uuid}/enable` | Turn every compiled artifact on. |
| POST | `/api/automations/{uuid}/disable` | Turn every compiled artifact off (stays in place). |
| POST | `/api/automations/{uuid}/dry-run` | Evaluate via the rules engine, no side effects. |
| GET | `/api/automations/{uuid}/status` | Recompute drift against the live artifacts. |

CRUD on the automation object itself is OpenRegister's generic REST surface:
`/apps/openregister/api/objects/openbuild/automation`.

## Relationship to the specialist editors

The Automations page is not a replacement for the Business rules page, the
Schedules section of the page designer, or the schema designer's lifecycle
editor — those remain the power-user surfaces for their own dialects and can
still be hand-edited directly (drift on a compiled artifact is expected and
surfaced, not an error). See [Business rules engine](./business-rules-engine.md)
for the FEEL subset, hit policies and audit trail the manual-trigger backend
inherits unchanged.
