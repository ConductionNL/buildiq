---
id: TS-001
title: "AI agent drives every OpenBuild action-menu capability through the UI"
priority: high
category: functional
personas:
- priya-ganpat
- mark-visser
test-commands:
- /test-functional
tags:
- functional
- ai-agent
- mcp
- regression
status: active
created: 2026-07-15
spec-refs:
- hermiq/openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md
---

# TS-001: AI agent drives every OpenBuild action-menu capability through the UI

**Goal**: Verify that a user can accomplish everything available through OpenBuild's
action surfaces **by asking the AI companion** (the hex icon, backed by hermiq) rather than
clicking manually — and, critically, determine *how the agent perceives the page*: whether the
MCP tool surface alone is enough, or whether it also needs the raw OpenRegister API and/or RAG
context to understand and mutate app state.

The agent must exercise the app the way a person would — through the UI where possible, using
the API endpoint that sits under each UI action where a tool call is the honest way to do it.
The two things under test are **coverage** (can the agent do each capability at all) and
**page understanding** (does it know what it is looking at, and via which channel).

## Preconditions

- Logged in as a Nextcloud user who owns at least one OpenBuild app (e.g. the `CowBoy` demo app,
  `owner` role, one published version, one register with ≥1 schema).
- Hermiq engine enabled (`hermiq.engine.enabled = true`) with a chat provider configured that is
  capable of tool use — either the `anthropic` provider (Claude, per
  `anthropic-agent-provider`) or a tool-capable Ollama model. **A non-tool-capable model will
  fail this scenario at the "understands the page" gate — that failure is itself a result.**
- OR `chat.proxyTo = hermiq` (or nc-vue default already `hermiq`) so the AI icon reaches hermiq.
- The hermiq MCP surface (ADR-063) is registered so OpenBuild/OpenRegister capabilities are
  exposed as tools.

## Capability coverage matrix

For each row, drive the capability by instructing the AI companion in natural language, then
verify the effect in the UI and in OpenRegister. Record which channel the agent used
(**MCP tool** / **OR API** / **RAG** / **could not**).

| # | Capability (OpenBuild action surface) | Example instruction to the agent | Verify |
|---|---|---|---|
| 1 | Add a schema to the app's register | "Add a `Cow` schema with fields name, tagNumber, birthDate" | New schema appears in Register panel + OpenRegister |
| 2 | Alter an existing schema (add/rename/retype a property) | "Add a `weight` number field to the Cow schema" | Property present; existing objects unaffected |
| 3 | Create a page | "Create a detail page for a single Cow" | Page appears in Manifest layers / renders at its route |
| 4 | Design/lay out a page (add widgets, arrange) | "On the Cow detail page add a title, a photo, and a related-objects list" | Widgets render in the designer + live page |
| 5 | Create a personal override (Your delta) | "Create my personal override and hide the tagNumber column" | `Your delta` shows an override; base untouched |
| 6 | Import data into a register | "Import these 3 cows" (paste/attach) | Objects created, object count rises |
| 7 | Publish / unpublish the app | "Publish the app" | App appears in the app menu; badge flips to published |
| 8 | Open / switch a version | "Open version 0.1.0" | Correct version loads (`?_version=`) |
| 9 | Export the app manifest | "Export this app" | Export dialog / artifact produced |
| 10 | Manage permissions | "Give the editors group edit access" | Permission recorded (owner-only action) |
| 11 | Save as template | "Save this app as a template" | Template capture prepared |
| 12 | Settings (register bindings, GitHub) | "Bind this app to the openbuild-cowboy register" | Setting persisted |

## Scenario

- GIVEN the user opens the OpenBuild app detail page and clicks the AI companion (hex) icon
- WHEN the user asks the agent, in plain language, to perform each capability in the matrix above
- THEN for each capability the agent either completes it (state changes correctly and is visible
  in the UI and in OpenRegister) or reports honestly that it cannot
- AND for each completed capability the transcript / tool-invocation table shows **which channel**
  the agent used (MCP tool, OR API call, or RAG-retrieved context)
- AND the agent demonstrably **understands the current page**: before mutating, it can answer
  "what app, version, register, schemas, and pages am I looking at?" correctly

## Page-understanding assessment (the primary research question)

This is the point of the scenario, not a side note. Capture, per capability:

- **MCP-only sufficiency** — Did an MCP tool exist that both *described* the current
  app/manifest/register state and *mutated* it? If yes, note the tool id. If the agent had to
  guess the app/register/schema identity, MCP context was insufficient.
- **API dependency** — Did the agent fall back to a raw OpenRegister API call
  (`/apps/openregister/api/...`) to read or write because no MCP tool covered it? List the
  endpoints. These are candidate gaps to promote to MCP tools.
- **RAG dependency** — Did the agent need retrieved page/manifest content (RAG over the app's
  manifest/objects) to understand the layout before it could design a page? Note where MCP
  metadata was too thin and RAG carried the understanding.
- **Blind spots** — Capabilities the agent could neither perceive nor perform. Each is a
  concrete backlog item (missing MCP tool, missing field projection, or missing RAG source).

Expected shape of the finding (hypothesis to confirm/refute): **structural** actions with a
clear verb+target (add schema, add field, import data, publish, switch version) are doable
**MCP-only**; **design** actions (lay out / arrange a page) most likely need **RAG** over the
manifest because the current page structure isn't fully expressible as MCP tool metadata; some
reads still fall through to the **OR API**. Confirm or refute with the actual transcript.

## Test Data

- Use the `CowBoy` demo app (Virtual, published, register `openbuild-cowboy-production`, one
  `Hello Message` schema) as the starting fixture, or any owner-role app with ≥1 register.

## Acceptance Criteria

- [ ] Every capability in the matrix is attempted through the AI companion
- [ ] Each attempt is labelled with the channel used (MCP tool / OR API / RAG / could not)
- [ ] The agent correctly answers "what am I looking at" for app, version, register, schemas, pages
- [ ] Schema add + alter reflected in OpenRegister without corrupting existing objects
- [ ] Page create + design reflected in the manifest and on the live route
- [ ] A written MCP-vs-API-vs-RAG gap list is produced (each gap = a backlog item)
- [ ] No JavaScript errors in the companion panel during the run

## Notes

- **Depends on a tool-capable chat provider.** Running this end-to-end "through the agent"
  requires either the `anthropic` provider (Claude / Claude Max — spec
  `hermiq/openspec/changes/anthropic-agent-provider`) or a tool-capable Ollama model. Until one
  is configured, the scenario is authored but not executable; note that as the run status.
- Assign one browser from the pool (browser-2..5,7) if run by a sub-agent; browser-6 if the user
  wants to watch.
- The MCP-vs-API-vs-RAG findings feed the fleet MCP-adoption backlog (ADR-063); a capability the
  agent could only reach via raw OR API is a candidate to promote to an MCP tool.
