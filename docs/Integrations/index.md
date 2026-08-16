---
sidebar_position: 3
title: Integrations
description: The Conduction ecosystem OpenBuilt composes from — registers, connectors, workflows, documents, dashboards.
---

# Integrations

OpenBuilt is the composition layer of the Conduction ecosystem. Each built app reuses the same supporting services every other Conduction app uses — there's no second data layer, no second connector framework, no second document engine.

## OpenRegister — the data layer

Every OpenBuilt app's data lives in [OpenRegister](https://openregister.conduction.nl). Specifically:

- the parent **Application** record and its **ApplicationVersion** rows live in the shared `openbuilt` register;
- each ApplicationVersion gets its **own per-version register** (`openbuilt-{appSlug}-{versionSlug}`) seeded with the version's schemas and rows;
- the **BuiltAppRoute** index (slug → applicationUuid) gives `/apps/openbuilt/{slug}` its O(1) lookup.

OpenRegister contributes the audit trail (every mutation logged), object time travel (rewind any record to its state at time T), declarative state machines (ADR-031), per-record RBAC, organisation-wide multi-tenancy, and the schema validator.

Schemas you author in OpenBuilt are first-class OpenRegister schemas — they show up in the OpenRegister UI, validate against the same OAS-shape contract, and benefit from the same MCP tools.

## OpenConnector — system integration

When an OpenBuilt app needs to talk to a system outside Nextcloud — pull supplier data from G2, post a closed record into your ERP, fetch a TenderNed listing — it does so via [OpenConnector](https://openconnector.conduction.nl). OpenConnector handles HTTP, OAuth, REST, SOAP, SFTP, and the mapping between external payloads and OpenRegister schemas.

A built app declares its integrations in the manifest; the connector source rows live in OpenConnector. When the manifest points at `integrations.xwiki`, OpenConnector exposes the matching live XWiki space through OpenBuilt's integration sidebar.

## Procest — business workflows

Decisions, intakes, multi-step approvals — the state machine *between* the data lives in [Procest](https://procest.conduction.nl). OpenBuilt + Procest is the pattern for permit-tracking style apps: OpenBuilt owns the application form and the manifest; Procest owns the *how* of moving an application from intake through decision.

The two are loosely coupled — Procest reacts to OpenRegister `ObjectTransitionedEvent`s, so any OpenBuilt-authored state transition can fire a Procest workflow without OpenBuilt knowing.

## Docudesk — document generation

Turn an OpenBuilt record into a PDF, DOCX or signed document via [Docudesk](https://docudesk.conduction.nl). Common patterns:

- intake confirmation PDF emailed on form submission;
- agenda + meeting minutes printable export from a Decidesk-style virtual app;
- signed decision letter for permit approvals.

Docudesk reads the schema (templates bind to schema property paths) and the record (data) and emits the file. OpenBuilt apps reference Docudesk templates by slug in their manifest's `actions[]` declarations.

## NL Design System — government theming

OpenBuilt apps inherit the [NL Design System](https://nldesignsystem.nl) tokens via the [nldesign](https://github.com/ConductionNL/nldesign) Nextcloud theme. When `nldesign` is enabled, OpenBuilt's UI — buttons, inputs, modals, headings, colours — automatically conforms to the Dutch government's design standards, ensuring WCAG AA compliance and visual consistency with the rest of your municipal estate.

No app-side opt-in: it's a Nextcloud theme. Switch it on, every OpenBuilt app + every sibling Conduction app re-themes.

## MyDash — dashboards across apps

When stakeholders need a cross-app view — "today's open intakes across every municipality department" — [MyDash](https://mydash.conduction.nl) reads the OpenRegister GraphQL surface and renders widgets. OpenBuilt apps expose their data as registers, so MyDash widgets work against an OpenBuilt app the same way they work against any sibling app.

## Larping App — gamification + onboarding

[Larping App](https://larpingapp.conduction.nl) provides onboarding flows, skill-tree progression, and gamified citizen-developer training inside Nextcloud. The "build your first OpenBuilt app" tutorial lives there; OpenBuilt opens its hello-world preset directly from the Larping tour.

## Pluggable integration registry

OpenBuilt's pluggable integration registry (per nc-vue #202..#218 + openregister #1490 / #1493) means any future ecosystem app can publish a sidebar provider for OpenBuilt's detail page. When DeskDesk, Decidesk, Pipelinq etc. add a provider, the OpenBuilt app shell exposes their UI inline — no per-app patch.
