---
kind: config
---

## Why

An OpenBuild application cannot say which flows or agents it is made of, so an export cannot carry them.

The `application` schema declares fifteen properties — `slug`, `name`, `description`, `appType`, `status`, `baseRef`, `productionVersion`, `icon`, `iconDark`, `allowUserOverrides`, `permissions`, `githubRepo`, `githubDefaultBranch`, `dataRegisters`, `connectors`. Two of those are bindings to other things the app is composed of. Neither is a flow, and neither is an agent.

Measured on the dev instance: the `hydra-console` application binds one data register (`hydra-cache`, three schemas: `change`, `cycle`, `finding`). The same instance holds **86 `Flow` entities** the engine executes and **14 agentflow definitions** in the hermiq register — the sequencer, triage, applier, lock reaper, dispatch, retry-and-escalate. None of them is reachable from the application object, because there is no property that could reach them.

So the export is not merely incomplete; it is complete with respect to a model that has no room for the thing being asked for. Adding bundling code first would have nothing to read.

### There is exactly ONE flow system

Per **ADR-065**, OpenRegister is the only home for a flow engine, and no leaf app grows a second one. This change takes that literally: the binding is called `flows`, it points at OpenRegister `Flow` entities, and there is no such thing as a "hermiq flow". A flow that calls an agent is an ordinary OpenRegister flow whose nodes happen to be the agentic node types hermiq contributes to the engine's registry (`hermiq.workload-step`, `hermiq.workload-collect`).

That naming is load-bearing rather than cosmetic. ADR-065 exists because the word "flow" meant five different things across the fleet and the overlap "already produced one dead feature and one silent data-loss bug". A binding named `agentFlows`, or a second binding for hermiq, would re-create exactly the ambiguity the ADR was written to end.

⚠️ **The engine reads the `Flow` ENTITY, and a parallel object store already exists.** OpenRegister keeps flow definitions in the `Flow` table; the hermiq register also holds `agentflow` OBJECTS that mirror some of them. They drift. A definition seeded into the object left the engine executing the previous version, silently — the run log showed the old graph while the register showed the new one. Any binding must therefore address the entity, and this proposal fixes that at the schema level so no consumer has to make the choice correctly on its own.

## What Changes

A new `register.d` fragment adds two array bindings to the `application` schema, shaped exactly like the existing `dataRegisters` binding so the UI, the export payload, and the reviewer all recognise it:

- **`flows`** — each entry `{ label, flow }`, where `flow` is the OpenRegister flow's **slug**, resolved against the `Flow` entity.
- **`agents`** — each entry `{ label, agent }`, where `agent` is the agent's slug.

Both are declarative additions to `lib/Settings/register.d/`. No PHP changes, no export behaviour, no UI. The bundler, the install-time seeding, and the e2e coverage are the next spec in the chain (`openbuild-exports-flows-and-agents`), which cannot start until this lands because it has nothing to read until then.

### Why slug and not id

`dataRegisters` binds by slug (`{"label":"Hydra pipeline cache","register":"hydra-cache"}`) and an export that carried numeric ids would be unusable on any other instance — ids are per-instance, slugs travel. A dangling slug is also a recoverable, reportable state, which the existing bundler already models: an unresolvable data register is skipped with a log line rather than failing the export.

## Capabilities

### New Capabilities
- `app-composition-bindings`: what an OpenBuild application declares itself to be composed of — data registers, connectors, and now flows and agents — and the rules those bindings follow (slug-addressed, resolvable, and pointing at the one flow engine).

### Modified Capabilities
<!-- None. This change introduces the binding vocabulary; it modifies no existing requirement. -->

## Impact

- **`openbuild/lib/Settings/register.d/`** — one new fragment (numbered alongside `20-data-registers.json` / `21-connectors.json`).
- **The `application` schema** (register `openbuild`, schema 28) gains two optional array properties. Existing application objects remain valid: both bindings are optional and absent means "binds none", which is what all 30 existing applications mean today.
- **No runtime behaviour changes.** Nothing reads these bindings until the next spec in the chain.
- **Downstream:** `openbuild-exports-flows-and-agents` (`kind: code`) depends on this.
