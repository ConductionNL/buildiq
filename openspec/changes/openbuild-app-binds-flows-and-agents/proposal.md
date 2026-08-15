---
kind: config
---

## Why

An OpenBuild application cannot say which flows it is made of, so an export cannot carry them — and it turns out it can already say which agents it has, which is why only one of the two needs a new binding.

The `application` schema declares fifteen properties — `slug`, `name`, `description`, `appType`, `status`, `baseRef`, `productionVersion`, `icon`, `iconDark`, `allowUserOverrides`, `permissions`, `githubRepo`, `githubDefaultBranch`, `dataRegisters`, `connectors`. Two of those are bindings to other things the app is composed of. Neither is a flow, and neither is an agent.

Measured on the dev instance: the `hydra-console` application binds one data register (`hydra-cache`, three schemas: `change`, `cycle`, `finding`). The same instance holds **86 `Flow` entities** the engine executes and **14 agentflow definitions** in the hermiq register — the sequencer, triage, applier, lock reaper, dispatch, retry-and-escalate. None of them is reachable from the application object, because there is no property that could reach them.

So the export is not merely incomplete; it is complete with respect to a model that has no room for the thing being asked for. Adding bundling code first would have nothing to read.

### There is exactly ONE flow system

Per **ADR-065**, OpenRegister is the only home for a flow engine, and no leaf app grows a second one. This change takes that literally: the binding is called `flows`, it points at OpenRegister `Flow` entities, and there is no such thing as a "hermiq flow". A flow that calls an agent is an ordinary OpenRegister flow whose nodes happen to be the agentic node types hermiq contributes to the engine's registry (`hermiq.workload-step`, `hermiq.workload-collect`).

That naming is load-bearing rather than cosmetic. ADR-065 exists because the word "flow" meant five different things across the fleet and the overlap "already produced one dead feature and one silent data-loss bug". A binding named `agentFlows`, or a second binding for hermiq, would re-create exactly the ambiguity the ADR was written to end.

⚠️ **The engine reads the `Flow` ENTITY, and a parallel object store already exists.** OpenRegister keeps flow definitions in the `Flow` table; the hermiq register also holds `agentflow` OBJECTS that mirror some of them. They drift. A definition seeded into the object left the engine executing the previous version, silently — the run log showed the old graph while the register showed the new one. Any binding must therefore address the entity, and this proposal fixes that at the schema level so no consumer has to make the choice correctly on its own.

## What Changes

A new `register.d` fragment adds ONE array binding to the `application` schema, shaped like the existing `dataRegisters` binding so the UI, the export payload and the reviewer all recognise it:

- **`flows`** — each entry `{ label, flow }`, where `flow` is the OpenRegister flow's **UUID**, resolved against the `Flow` entity via `FlowMapper::findByUuid()`.

It is a declarative addition to `lib/Settings/register.d/`. No PHP changes, no export behaviour, no UI. The bundler, the install-time seeding and the e2e coverage are the next spec in the chain (`openbuild-exports-flows-and-agents`), which cannot start until this lands because it has nothing to read until then.

### Why UUID and not slug — corrected during implementation

This proposal originally specified a slug for both bindings, by analogy with `dataRegisters`. Implementing it showed the analogy does not hold: **the `Flow` entity has no slug.** It carries `uuid`, `name`, `app`, `enabled`, `trigger`, `nodes`, `edges` — and `FlowMapper` offers `findByUuid()`, with no slug lookup to offer.

The portability argument survives intact, because it was always an argument against the numeric `id`: an auto-increment column is per-instance and would resolve to a different flow, or to nothing, after an export. A UUID is globally unique and travels — provided the importing side seeds the flow with the same UUID rather than minting a new one, which the follower spec now requires explicitly.

The cost is readability: a UUID means nothing in a picker. That is what `label` is for, and it matters more here than it would for a slug.

### Why there is NO `agents` binding

Also corrected during implementation. The `agent` schema already carries **`applicationSlug`**, and `AgentsController` already resolves an application's agents through it. Adding an `agents` array to the application would create a SECOND edge for a relationship that already exists, pointing the other way — two links that can disagree, with nothing to say which is right.

So the exporter resolves an app's agents by querying agents whose `applicationSlug` matches, and the application schema gains nothing for agents at all. This is the same instinct ADR-065 applies to flows, in a smaller domain: one representation of a relationship, not two.

## Capabilities

### New Capabilities
- `app-composition-bindings`: what an OpenBuild application declares itself to be composed of — data registers, connectors, and now flows — and the rules those bindings follow (portably addressed, resolvable against the one flow engine, and not duplicating a relationship that already exists elsewhere).

### Modified Capabilities
<!-- None. This change introduces the binding vocabulary; it modifies no existing requirement. -->

## Impact

- **`openbuild/lib/Settings/register.d/`** — one new fragment (numbered alongside `20-data-registers.json` / `21-connectors.json`).
- **The `application` schema** (register `openbuild`, schema 28) gains ONE optional array property. Existing application objects remain valid: the binding is optional and absent means "binds none", which is what all 30 existing applications mean today.
- **Nothing for agents.** The app→agent relationship already exists as `agent.applicationSlug`; this change deliberately does not duplicate it.
- **No runtime behaviour changes.** Nothing reads these bindings until the next spec in the chain.
- **Downstream:** `openbuild-exports-flows-and-agents` (`kind: code`) depends on this.
