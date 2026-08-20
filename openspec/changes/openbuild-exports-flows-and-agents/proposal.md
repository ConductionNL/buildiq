---
kind: code
depends_on:
  - openbuild-app-binds-flows-and-agents
---

## Why

Exporting an OpenBuild app leaves behind everything that makes it do anything.

`ExportJobService` accepts exactly one collection from the request — `dataRegisters` — and `ExportService::generateAppZip()` is three steps: copy the template, resolve placeholders, `bundleDataRegisterSchemas()`. There is no other collector. So the ZIP carries a scaffold and some schema definitions, and nothing that runs.

Measured on the dev instance, exporting `hydra-console` today produces the three schemas of the `hydra-cache` register (`change`, `cycle`, `finding`) and **none** of the 86 `Flow` entities or 14 agent definitions the app is actually built from. Import that ZIP and you get an app that looks right and does nothing: the registers are there, the pipeline is not.

The head of this chain (`openbuild-app-binds-flows-and-agents`) gives an application somewhere to declare its flows and agents. This change reads those bindings, puts the definitions in the ZIP, and makes an imported flow actually runnable.

### The half that is easy to get wrong

Bundling is the straightforward part. The part that decides whether this feature works is **what happens on the importing instance**.

⚠️ OpenRegister executes the **`Flow` entity**. A parallel `agentflow` OBJECT store mirrors some definitions, and the two drift — this was observed directly: a definition written to the object left the engine executing the previous graph, reporting no error, while the register UI showed the new one. An export that bundled objects, or an import that seeded objects, would produce an app whose flows look correct in every UI and never run. That is a worse outcome than not exporting them at all, because nothing surfaces it.

So: read the `Flow` entity, write the `Flow` entity, and prove it by executing an imported flow rather than by reading it back.

### One flow system

Per **ADR-065**, OpenRegister is the only home for a flow engine. This change adds no engine, no runner and no second definition format. A flow that calls an agent is an ordinary OpenRegister flow whose nodes are the agentic node types hermiq contributes to the registry (`hermiq.workload-step`, `hermiq.workload-collect`); it exports through the same path as any other flow, and an exported flow that references a node type the importing instance has not registered is a reportable condition rather than a special case.

## What Changes

**Bundling.** A `FlowAndAgentExportBundler`, sibling to `DataRegisterExportBundler`, resolves the application's `flows` binding (by UUID, via `FlowMapper::findByUuid()`) and its agents (by querying `agent` objects whose `applicationSlug` matches — there is deliberately no `agents` binding, because that edge already exists) and writes `lib/Settings/flows/<slug>.json` and `lib/Settings/agents/<slug>.json` into the scaffold. Flows are read from the `Flow` entity via `FlowMapper`. A binding that resolves to nothing is skipped with a log line — the precedent the data-register bundler already sets — and the skip is reported in the job result rather than only in a log.

**Export payload.** `ExportJobService` accepts `flows` alongside `dataRegisters`, sanitised the same way. Agents need no payload entry: they follow from the application.

**UUID preservation.** Seeding writes each flow with the UUID it was exported with. Minting a new one on import would leave every binding in the imported application pointing at nothing — the one failure that breaks the round trip while every file looks correct.

**Install-time seeding.** An exported app that ships flow JSON needs something to put it into the `Flow` table on the importing instance, or the export round-trips into a register with no runnable flows. The scaffold template gains that seeding, wired to the app's existing install/update hook.

⚠️ It must be an **update** hook as well as install, and it must be idempotent — re-running it must not duplicate a flow or clobber a locally-edited one. Seeding must also never be a data migration that runs unconditionally.

**Node-type reporting.** On seeding, a flow whose nodes name a type the instance has not registered is recorded and surfaced, not silently seeded into a graph that cannot fire.

**e2e UI coverage.** A Playwright test drives the real export UI: bind a flow and an agent to an app, export, and assert the ZIP contains the flow and agent JSON. Plus the round trip that actually matters — import the ZIP on a clean register and **run** the seeded flow.

## Capabilities

### New Capabilities
- `app-export-completeness`: what an exported application carries, what it deliberately omits, and the guarantee that what comes out can be imported and run rather than only inspected.

### Modified Capabilities
- `app-composition-bindings`: the head of this chain declares the bindings and requires only that they be storable. This change adds the requirement that they be RESOLVED — against the `Flow` entity — and that a dangling binding be reported to the operator rather than only logged.

## Impact

- **`openbuild/lib/Service/`** — new `FlowAndAgentExportBundler`; `ExportService` gains one bundling step; `ExportJobService` accepts two more collections.
- **`openbuild/lib/Resources/template/`** — the scaffold gains `lib/Settings/flows/`, `lib/Settings/agents/`, and install/update-time seeding.
- **OpenRegister** — read via `FlowMapper` and written on the importing side. No OpenRegister change: this consumes the existing abstraction (ADR-022) and adds no engine (ADR-065).
- **Tests** — unit coverage for the bundler and the seeder; Playwright coverage for export-with-bindings and for import-then-run.
- **Not touched** — the `agentflow` object store. It is read by nothing here and written by nothing here; consolidating it is a separate change with its own migration risk.
- **Upstream:** requires `openbuild-app-binds-flows-and-agents` to have landed; there is nothing to read until it does.
