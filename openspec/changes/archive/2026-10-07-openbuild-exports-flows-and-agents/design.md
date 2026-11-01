## Context

The export pipeline is short and its shape is the whole constraint: `ExportJobService` sanitises a payload, `ExportService::generateAppZip()` copies `lib/Resources/template/`, resolves placeholders, and calls `bundleDataRegisterSchemas()`. `DataRegisterExportBundler` writes `lib/Settings/data-registers/<slug>.{schema,seed-data}.json` and skips an unresolvable register with a log line. Adding a second bundler is a small, well-precedented change.

The importing side is where the difficulty is. The scaffold already ships `lib/Repair/InitializeSettings.php`, registered in `info.xml` under `<repair-steps><post-migration>`, which runs on install **and** on every upgrade — the hook seeding needs, and one the template already has.

Two measured facts drive the decisions below:

- OpenRegister executes the **`Flow` entity** (`FlowMapper`). The `agentflow` object store in the hermiq register mirrors some definitions and drifts from it. Observed: a definition written to the object left the engine running the previous graph, with the run log showing the old node set and no error anywhere.
- 86 `Flow` entities exist on the dev instance against 14 agentflow objects, so the mirror is partial as well as stale.

## Goals / Non-Goals

**Goals:**
- An exported app carries its flows and agents.
- An imported app's flows RUN, and the test proves it by running one.
- One code path for every flow, agentic or not (ADR-065).
- A binding that resolves to nothing is reported to the operator, not buried.

**Non-Goals:**
- Consolidating or removing the `agentflow` object store. This change reads and writes only the entity and says so; the mirror's removal is a data migration and its own change.
- Exporting automations, business rules or component blocks. They have the same gap and it is the same shape, but each is its own resolution logic and its own seeding question — bundling them here would make one spec that touches five subsystems.
- Cross-instance identity or conflict resolution beyond "do not silently clobber a local edit". Merge semantics for divergent flow definitions is a design problem in its own right.
- Any change to OpenRegister. This consumes `FlowMapper` (ADR-022) and adds no engine (ADR-065).

## Decisions

### 1. A sibling bundler, not a widened one

`FlowAndAgentExportBundler` sits next to `DataRegisterExportBundler` rather than extending it. The two resolve different stores through different mappers and fail in different ways; the only thing they share is "write JSON into the scaffold and skip what you cannot resolve", which is four lines.

**Alternative rejected:** a generic `ComponentExportBundler` parameterised by binding type. It collapses three resolution strategies into a discriminator and makes the failure messages generic at exactly the point where a specific one is the whole value ("flow `x` not found" vs "binding could not be resolved").

### 2. Read the `Flow` ENTITY — and prove it with a differing pair

The exporter resolves `flows` bindings through `FlowMapper`. This is the single highest-risk decision in the change, because reading the wrong store produces an export that passes every inspection and yields flows that do not run.

The spec therefore requires a scenario where the entity and the object store **disagree**, asserting the entity wins. A test that exports a flow present identically in both stores cannot tell the two implementations apart, which is the same class of vacuous check as a suspend test that never suspends.

### 3. Seed through the scaffold's existing `<post-migration>` repair step

The template already registers `InitializeSettings` there. Seeding flows joins it rather than adding a new hook.

`<post-migration>` runs on install and on every upgrade — required, because an app is installed once and upgraded many times, and a changed flow ships in an upgrade. An `<install>`-only step would seed the first version and silently ignore every subsequent one.

⚠️ The seeder writes the app's OWN definitions — configuration, not user data. It must never grow into a data migration over user objects; that class of work does not belong in a repair step regardless of hook.

### 4. Idempotent by slug; a local edit is preserved and reported

Seeding upserts by flow slug, so running twice yields one flow.

Where a seeded flow has been modified locally and the app ships a new version of it, the seeder does **not** silently overwrite. The rule is: write when the local definition is unmodified from what was last seeded; otherwise keep the local one and record the divergence where an operator can see it. That requires remembering what was last seeded, which is one stored fingerprint per seeded flow — cheaper than the alternative, which is either destroying an operator's work or never updating a flow again.

**Alternative rejected:** last-writer-wins on upgrade. Simple, and it silently deletes the customisation that made the app useful to that organisation.

### 5. An unregistered node type is surfaced at seeding, not at first run

A flow containing `hermiq.workload-step` seeded onto an instance without hermiq is a flow that exists, validates, and cannot fire. Discovering that when someone triggers it — possibly months later, possibly in production — is the expensive path.

The seeder compares each node's `type` against the engine's node registry and surfaces anything unknown. This is a report, not a refusal: an app may legitimately ship a flow for a capability the operator installs later.

### 6. Declarative-vs-imperative decision (ADR-031)

This change adds imperative code, and none of ADR-031's declarative categories applies to it. It introduces no lifecycle or state machine, no aggregation or count, no derived or virtual field, no notification, no declarative relation between OR objects, and no dashboard widget.

What it adds is export/import machinery: resolving bindings, serialising definitions into a ZIP, and seeding them on the far side. There is no `x-openregister-*` declaration that expresses "put these definitions in a ZIP", and inventing one would be a worse fit than a service class. The one declarative surface this feature needs — the bindings themselves — is the chain head, and it is declared exactly that way.

## Seed Data

This change introduces no OpenRegister schema, so there is nothing new to seed. What it needs is fixtures that exercise resolution, and the important ones are the failure shapes rather than the happy path:

- **Resolvable pair** — `hydra-console` binding `hydra-sequencer` (a real 76-node flow with agentic nodes) and `hydra-triage`. Proves the ordinary path and, because the sequencer contains `hermiq.workload-step`, proves decision 5's happy branch on an instance that does have hermiq.
- **Divergent pair** — one flow whose `Flow` entity and `agentflow` object differ in node count. The only fixture that can distinguish decision 2's implementation from the wrong one.
- **Dangling binding** — `{"label": "Old", "flow": "flow-that-was-deleted"}`. Proves the export still succeeds and the skip reaches the job result.
- **Unregistered node type** — a minimal flow with one node of type `nonexistent.node`, seeded on an instance whose registry lacks it.

General-organisation fixtures, so the feature is not only ever exercised against hydra:

- **Municipality** — app `vergunningen`, flow `aanvraag-intake` (no agentic nodes), agent `dossier-samenvatter`.
- **Consultancy** — app `urenregistratie`, flow `weekstaat-herinnering`, agent `factuur-controleur`.
- **Travel agency** — app `boekingen`, flow `annulering-terugbetaling`, agent `reisdocument-checker`.

## Risks / Trade-offs

- **The failure mode is silent by construction.** Every wrong version of this change — reading the object store, seeding without a hook, seeding install-only — produces files that look right and flows that never run. This is why the spec's acceptance is a RUN and not a read-back, and why the fixtures include a pair the two stores disagree on.
- **Fingerprinting local edits adds state.** Decision 4 stores what was last seeded per flow. That is a small amount of new state that can itself drift. Accepted because both alternatives are worse: clobbering operator work, or freezing flows at their first shipped version.
- **A partial export still succeeds.** An app with three bound flows, one dangling, exports two and reports one. An operator who ignores the result gets an app missing a flow. Mitigated by putting the skip in the job result rather than the log, and bounded by the alternative — failing the whole export because one binding rotted — being worse.
- **Scope pressure toward automations and rules.** They have the identical gap. Deliberately excluded; if the bundler ends up shaped so a fourth binding is a small addition, that is a good outcome, but it is not a goal of this change.
