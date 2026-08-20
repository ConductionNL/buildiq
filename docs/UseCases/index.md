---
sidebar_position: 1
title: Use cases
description: Why pick OpenBuilt — citizen-developer scenarios where ten clicks ship an app instead of three sprints.
---

# Use cases

OpenBuilt is for **citizen developers**: domain experts who know exactly what app they need but don't write PHP, don't want to scaffold a Nextcloud app from scratch, and don't have a sprint to spare. You compose the app from registers (your data), connectors (other systems), workflows (decisions), documents (output), and dashboards (insight) — and the moment you click *Publish*, it lives at `/apps/openbuilt/your-slug` for every authorised colleague.

Below are the situations where teams pick OpenBuilt over the alternatives.

## Permit & licence tracking (municipal back office)

A municipality needs to track building permits, environmental licences, or event applications through a review chain — but the IT roadmap is full and procurement won't approve a new bespoke app this year.

OpenBuilt gives the policy team a path forward in one afternoon:

- **Schemas** define the application form, the reviewer's verdict, and any uploaded evidence — written declaratively in the schema designer, no SQL or migrations.
- **Workflows** (via Procest) drive each application through *intake → review → decision → archive*, with each transition writing an audit trail row.
- **NL Design** styling means the result looks like the rest of your municipal estate from day one — no separate front-end project.
- **Per-version registers** (development → staging → production) let the policy team experiment with new fields without touching the live caseload.

Outcome: the same workflow that previously needed a six-month bespoke build ships in a working day. The IT team stays involved (RBAC, hosting, integrations) but the citizen developer owns the application logic.

## Internal tooling for shared services

Every organisation has a long tail of "would be useful" tools: vehicle reservations, asset registers, supplier onboarding, training intake. None of these are big enough for IT to prioritise, all of them are too important to live in spreadsheets.

OpenBuilt collapses that backlog:

- **Templates marketplace** ships starter apps so the policy owner picks one ("Asset register") and customises rather than starts blank.
- **Permissions** are per-app — finance owns the supplier register, HR owns training intake, everyone reads the asset register; nobody has to argue with a global Nextcloud admin.
- **Documents** (via Docudesk) turn an OpenBuilt record into a printable PDF on demand — agendas, contracts, decisions — without leaving the app shell.
- **Connectors** (via OpenConnector) pull supplier ratings from G2, populate G‑Cloud catalogue data, or push a closed supplier-onboarding record into your ERP.

Outcome: the long tail moves from "shadow spreadsheets" to "first-class, audit-friendly, accessible-from-Nextcloud apps" without IT needing to build each one.

## Government compliance — declarative, audit-friendly

Public-sector teams need apps that are observably compliant: who saw what, who changed what, what state was published, and whether the data model meets the standards register.

OpenBuilt makes this declarative:

- **ADR-031 schema-declarative business logic** — state machines, aggregations, calculations and notifications live in the schema, not in service code. The compliance officer reads the declaration directly; no decompiling required.
- **Schema-aligned with the Forum Standaardisatie register** — Conduction's data layer (OpenRegister) was built to import the 115 mandated Dutch government standards. New OpenBuilt apps reuse those schemas instead of inventing variants.
- **Per-version time travel** — each ApplicationVersion is its own register; OpenRegister's object time-travel logs every mutation so the audit "what did it look like at 09:00 on the 5th" question always has an answer.
- **EUPL-1.2 licence** — copy-friendly across the Dutch public sector; no vendor lock-in.

Outcome: the conversation with your CISO / functionaris gegevensbescherming is a short one — the architecture itself is the answer.

## Citizen-led innovation (procurement-free)

Sometimes you don't know whether the app idea is worth building until somebody tries it. OpenBuilt removes the procurement friction:

- **No bespoke development contract** — the citizen developer ships in OpenBuilt without engaging external builders.
- **Hello-world wizard** stands a fresh app up in three clicks (slug, version chain, permissions). The first manifest is rendered before lunch.
- **Three tiers per app by default** — development for iteration, staging for stakeholder review, production for go-live. You promote between them; you never deploy.
- **Roll back via OpenRegister time travel** — if production drifts off-spec, you point `productionVersion` at an earlier ApplicationVersion and the live app rewinds.

Outcome: the question shifts from "is this worth funding" to "is this worth keeping" — and you can answer the latter with real users on a real app.

## When OpenBuilt is **not** the right answer

A few honest carve-outs:

- **High-throughput transactional workloads** (millions of writes per day) — OpenBuilt apps live in OpenRegister; OpenRegister is optimised for governed authoring, not transaction processing.
- **Complex client-side UX** with rich custom Vue components — you can ship `custom` page types that delegate to bespoke components, but at that point you're writing Vue, not citizen-developing.
- **Hard integrations that need new transport code** — the connector layer handles HTTP/SOAP/SFTP / OAuth. Anything more exotic (gRPC, MQTT, bespoke binary protocols) needs a connector extension, which is developer work.

Where any of these constraints bite, OpenBuilt apps still serve as the *control plane* — the place permissions, audit, and versioning live — while a sibling Nextcloud app handles the heavy lifting.
