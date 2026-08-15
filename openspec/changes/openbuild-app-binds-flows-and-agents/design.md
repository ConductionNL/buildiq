## Context

`lib/Settings/register.d/20-data-registers.json` already added exactly this kind of binding to the `Application` schema, and its chain (`data-registers-schema-declaration` → `data-registers-runtime`) is the precedent this change follows in both shape and sequencing. `SettingsService::deepMergeConfig` recurses into `components.schemas.Application.properties` and adds only the new keys, leaving `Application.required` and every other property untouched.

Two facts about the target of the new binding, both measured rather than assumed:

- OpenRegister stores flow definitions in the **`Flow` entity** (`FlowMapper`), and that is what the engine executes. The hermiq register additionally holds `agentflow` **objects** that mirror some definitions. They drift — a definition written to the object left the engine running the previous graph with no error surfaced anywhere. Whatever consumes this binding must resolve against the entity.
- The instance holds 86 `Flow` entities and 14 agentflow objects. The `hydra-console` application binds one data register and therefore reaches none of them.

## Goals / Non-Goals

**Goals:**
- Give an application somewhere to declare the flows it is composed of.
- Establish that agents need no such binding, because `agent.applicationSlug` already expresses it.
- Make the binding shape identical to `dataRegisters`, so the builder pickers, export payload and reviewer treat it as a known thing.
- Name the referent unambiguously as the OpenRegister `Flow`, so ADR-065's single-engine rule is expressed in the data model rather than only in prose.

**Non-Goals:**
- Reading the bindings. No exporter, importer, UI picker or validation consumes them in this change — that is `openbuild-exports-flows-and-agents` (`kind: code`).
- Migrating or removing the `agentflow` object store. That drift is real and is called out so this binding does not inherit it, but consolidating the two stores is its own change with its own data-migration risk.
- Back-filling bindings on the 30 existing applications, including `hydra-console`. Absent means "binds none" and that is correct for all of them until someone declares otherwise.
- Versioning or promotion behaviour. Like `dataRegisters`, these bindings sit on `Application`, not `ApplicationVersion`, and `VersionPromotionService` neither reads nor writes them.

## Decisions

### 0. No `agents` binding at all — the edge already exists

`agent` objects carry **`applicationSlug`**, and `AgentsController` already resolves an application's agents through it. An `agents` array on the application would be a second edge for the same relationship, pointing the other way.

Two edges can disagree. Bind agent X to app A while X's own `applicationSlug` says B, and nothing in the model says which is true — the exporter, the UI and the reviewer would each have to pick, and they would not all pick the same one.

So the exporter queries agents by `applicationSlug` and the schema gains nothing. This is ADR-065's instinct applied in a smaller domain: one representation of a relationship, not two.

**Alternative rejected:** binding agents anyway "for symmetry" with `flows`. Symmetry between a relationship that exists and one that does not is a false symmetry, and it costs a drift class that cannot be validated away.

### 1. One `flows` binding, addressing the OpenRegister `Flow` — never a second flow vocabulary

ADR-065 exists because "flow" meant five things across the fleet, and that ambiguity "already produced one dead feature and one silent data-loss bug". A binding named `agentFlows`, or a hermiq-specific sibling, would rebuild the ambiguity inside a single schema.

A flow that calls an agent is an ordinary OpenRegister flow whose nodes are the agentic node types hermiq contributes to the engine's registry. There is nothing structurally different about it to bind differently. The property description names the `Flow` entity explicitly so a future consumer does not have to rediscover which of the two stores is authoritative.

**Alternative rejected:** a single polymorphic `components` binding covering registers, connectors, flows and agents. It reads tidier and it loses the type at exactly the moment the exporter needs it, forcing a discriminator field that the existing two bindings do not have.

### 2. UUID, not slug and not id — corrected against the entity

The first version of this design said slug, by analogy with `dataRegisters`. The analogy fails: **the `Flow` entity has no slug.** It carries `uuid`, `name`, `app`, `enabled`, `trigger`, `nodes`, `edges`, and `FlowMapper` exposes `findByUuid()` with no slug lookup beside it. A slug binding would have been unresolvable — the follower would have discovered this while writing the bundler, one spec too late.

The reasoning behind "not an id" is untouched and is the reason UUID works: an id is an auto-increment column, per-instance, and an exported `flow: 5020` resolves to a different flow or to nothing wherever it lands. A UUID is globally unique, so it survives the trip — **conditional on the importing side seeding the flow with the same UUID rather than minting a new one.** That condition is now an explicit requirement on the follower; without it the binding breaks on exactly the round trip it exists to support.

The cost is legibility. `hydra-sequencer` tells a reader what it is; `6b14a1fd-…` does not. That is what `label` is for, and it is why `label` matters more on this binding than on `dataRegisters`.

A UUID that resolves to nothing must not make the application unsaveable — deleting a flow is ordinary. Validation constrains the STRING SHAPE only; resolvability is the consumer's problem and the consumer reports it.

**Alternative rejected:** adding a `slug` column to the `Flow` entity. It would read better, and it is an OpenRegister change — outside this app's remit (ADR-022), and a schema migration on a table with 86 rows on one dev instance alone. If OR grows a flow slug later, this binding can accept one alongside the UUID.

### 3. `additionalProperties: false`, matching the precedent

The `dataRegisters` item sets it. A binding that silently accepts extra keys is a binding where a typo — `flowSlug` instead of `flow` — persists, validates, and exports as an entry that references nothing.

### 4. Declarative-vs-imperative decision (ADR-031)

**Every behaviour in this change is declarative.** It is a `register.d` schema fragment and nothing else: no service class, no controller, no route, no listener. The change adds vocabulary to a schema; it adds no runtime behaviour of any kind. The imperative work — resolving slugs, reading the `Flow` entity, writing bundle files, seeding on install — all belongs to the follower spec, and none of it qualifies for an ADR-031 exception here because none of it happens here.

## Seed Data

The change adds one property to an existing schema rather than a new schema, so there are no new objects to seed. What the follower spec and any manual test need is a realistic binding to exercise. Real UUIDs read off the dev instance, so the fixtures resolve rather than merely parse:

**`hydra-console`** — a plausible fully-populated binding, for the follower's fixtures rather than as a migration to apply now:

```json
{
  "slug": "hydra-console",
  "dataRegisters": [{ "label": "Hydra pipeline cache", "register": "hydra-cache" }],
  "flows": [
    { "label": "Hydra sequencer", "flow": "6b14a1fd-0cab-40c0-a3e7-7fea3be29bdc" },
    { "label": "Hydra Triage", "flow": "2973f673-1886-4d7e-ab3a-3232fb8de20e" },
    { "label": "Hydra lock reaper", "flow": "782158f5-0852-477e-a80f-e9ac01793b1e" }
  ]
}
```

No `agents` key: the app's agents are the `agent` objects whose `applicationSlug` is `hydra-console`.

The sequencer is the useful fixture precisely because it is not a toy — 76 nodes, and its graph includes `hermiq.workload-step`, so it exercises decision 1 (an agentic flow binds and exports like any other) rather than only the happy path.

Fixtures for the follower that must FAIL, which matter more than the ones that pass:

- **Dangling** — a well-formed UUID (`00000000-0000-0000-0000-000000000000`) that resolves to no flow. Proves the export still succeeds and the skip reaches the operator.
- **Divergent** — a flow whose `Flow` entity and `agentflow` object differ in node count. The only fixture that can tell a bundler reading the entity from one reading the mirror.
- **Malformed** — `"flow": "hydra-sequencer"`, i.e. the slug this design originally specified. Refused by the UUID pattern, and worth keeping as a regression guard precisely because it was the first design.

General-organisation fixtures, so the feature is not only ever exercised against hydra:

- **Municipality** — app `vergunningen`, flow "Aanvraag intake" (no agentic nodes).
- **Consultancy** — app `urenregistratie`, flow "Weekstaat herinnering".
- **Travel agency** — app `boekingen`, flow "Annulering terugbetaling".

## Risks / Trade-offs

- **A binding nothing reads yet.** Between this change and its follower, an application can declare flows that no export carries — a property that looks functional and is not. Mitigated by the chain: hydra's supervisor blocks the follower until this issue closes, and the two land in sequence rather than the binding sitting unused indefinitely. The alternative — one `mixed` spec — is rejected by ADR-032, which records two `mixed` specs burning a full builder budget without producing a PR.
- **The `agentflow` object store is left in place.** This change points the binding at the entity and says so, but does not remove the mirror. Anyone reading the register UI may still see an agentflow object and reasonably believe it is the flow. Accepted here because consolidation is a data migration; documented in the spec so the follower does not resolve against the wrong store.
- **Slug stability becomes load-bearing.** Renaming a flow silently breaks a binding. Accepted for parity with `dataRegisters`, and bounded by decision 2: the consumer must report a dangling reference rather than drop it.
