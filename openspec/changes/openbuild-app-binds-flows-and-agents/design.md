## Context

`lib/Settings/register.d/20-data-registers.json` already added exactly this kind of binding to the `Application` schema, and its chain (`data-registers-schema-declaration` → `data-registers-runtime`) is the precedent this change follows in both shape and sequencing. `SettingsService::deepMergeConfig` recurses into `components.schemas.Application.properties` and adds only the new keys, leaving `Application.required` and every other property untouched.

Two facts about the target of the new binding, both measured rather than assumed:

- OpenRegister stores flow definitions in the **`Flow` entity** (`FlowMapper`), and that is what the engine executes. The hermiq register additionally holds `agentflow` **objects** that mirror some definitions. They drift — a definition written to the object left the engine running the previous graph with no error surfaced anywhere. Whatever consumes this binding must resolve against the entity.
- The instance holds 86 `Flow` entities and 14 agentflow objects. The `hydra-console` application binds one data register and therefore reaches none of them.

## Goals / Non-Goals

**Goals:**
- Give an application somewhere to declare the flows and agents it is composed of.
- Make the binding shape identical to `dataRegisters`, so the builder pickers, export payload and reviewer treat it as a known thing.
- Name the referent unambiguously as the OpenRegister `Flow`, so ADR-065's single-engine rule is expressed in the data model rather than only in prose.

**Non-Goals:**
- Reading the bindings. No exporter, importer, UI picker or validation consumes them in this change — that is `openbuild-exports-flows-and-agents` (`kind: code`).
- Migrating or removing the `agentflow` object store. That drift is real and is called out so this binding does not inherit it, but consolidating the two stores is its own change with its own data-migration risk.
- Back-filling bindings on the 30 existing applications, including `hydra-console`. Absent means "binds none" and that is correct for all of them until someone declares otherwise.
- Versioning or promotion behaviour. Like `dataRegisters`, these bindings sit on `Application`, not `ApplicationVersion`, and `VersionPromotionService` neither reads nor writes them.

## Decisions

### 1. One `flows` binding, addressing the OpenRegister `Flow` — never a second flow vocabulary

ADR-065 exists because "flow" meant five things across the fleet, and that ambiguity "already produced one dead feature and one silent data-loss bug". A binding named `agentFlows`, or a hermiq-specific sibling, would rebuild the ambiguity inside a single schema.

A flow that calls an agent is an ordinary OpenRegister flow whose nodes are the agentic node types hermiq contributes to the engine's registry. There is nothing structurally different about it to bind differently. The property description names the `Flow` entity explicitly so a future consumer does not have to rediscover which of the two stores is authoritative.

**Alternative rejected:** a single polymorphic `components` binding covering registers, connectors, flows and agents. It reads tidier and it loses the type at exactly the moment the exporter needs it, forcing a discriminator field that the existing two bindings do not have.

### 2. Slug, not id — and a dangling slug is reportable, not fatal

`dataRegisters` binds by slug, with a `^[a-z0-9][a-z0-9-]*[a-z0-9]$` pattern. Ids are per-instance; an exported app carrying `flow: 5020` would resolve to a different flow, or to nothing, wherever it landed. That is the failure mode this whole change exists to prevent, so it must not be reintroduced in the binding.

A slug that resolves to nothing must not make the application unsaveable. Renaming a flow is ordinary. The existing bundler already models this: an unresolvable data register is skipped with a log line rather than failing the export. Validation therefore constrains the STRING SHAPE only; resolvability is the consumer's problem and the consumer reports it.

### 3. `additionalProperties: false`, matching the precedent

The `dataRegisters` item sets it. A binding that silently accepts extra keys is a binding where a typo — `flowSlug` instead of `flow` — persists, validates, and exports as an entry that references nothing.

### 4. Declarative-vs-imperative decision (ADR-031)

**Every behaviour in this change is declarative.** It is a `register.d` schema fragment and nothing else: no service class, no controller, no route, no listener. The change adds vocabulary to a schema; it adds no runtime behaviour of any kind. The imperative work — resolving slugs, reading the `Flow` entity, writing bundle files, seeding on install — all belongs to the follower spec, and none of it qualifies for an ADR-031 exception here because none of it happens here.

## Seed Data

The change adds properties to an existing schema rather than a new schema, so there are no new objects to seed. What the follower spec and any manual test need is a realistic binding to exercise, and the instance already provides one:

**`hydra-console` (existing application object)** — a plausible fully-populated binding, for use as an example in the follower spec's fixtures rather than as a migration to apply now:

```json
{
  "slug": "hydra-console",
  "dataRegisters": [{ "label": "Hydra pipeline cache", "register": "hydra-cache" }],
  "flows": [
    { "label": "Hydra sequencer", "flow": "hydra-sequencer" },
    { "label": "Hydra triage", "flow": "hydra-triage" },
    { "label": "Hydra lock reaper", "flow": "hydra-lock-reaper" }
  ],
  "agents": [
    { "label": "Code reviewer", "agent": "juan-claude-van-damme" },
    { "label": "Security reviewer", "agent": "clyde-barcode" },
    { "label": "Applier", "agent": "axel-plier" }
  ]
}
```

General-organisation equivalents for the follower spec's fixtures, so the feature is not only ever exercised against hydra:

- **Municipality** — app `vergunningen`, flows `aanvraag-intake` and `bezwaar-termijnbewaking`, agent `dossier-samenvatter`.
- **Consultancy** — app `urenregistratie`, flow `weekstaat-herinnering`, agent `factuur-controleur`.
- **Travel agency** — app `boekingen`, flow `annulering-terugbetaling`, agent `reisdocument-checker`.

## Risks / Trade-offs

- **A binding nothing reads yet.** Between this change and its follower, an application can declare flows that no export carries — a property that looks functional and is not. Mitigated by the chain: hydra's supervisor blocks the follower until this issue closes, and the two land in sequence rather than the binding sitting unused indefinitely. The alternative — one `mixed` spec — is rejected by ADR-032, which records two `mixed` specs burning a full builder budget without producing a PR.
- **The `agentflow` object store is left in place.** This change points the binding at the entity and says so, but does not remove the mirror. Anyone reading the register UI may still see an agentflow object and reasonably believe it is the flow. Accepted here because consolidation is a data migration; documented in the spec so the follower does not resolve against the wrong store.
- **Slug stability becomes load-bearing.** Renaming a flow silently breaks a binding. Accepted for parity with `dataRegisters`, and bounded by decision 2: the consumer must report a dangling reference rather than drop it.
