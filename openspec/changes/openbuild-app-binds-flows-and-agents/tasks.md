## 1. Declare the bindings

- [x] Add `lib/Settings/register.d/22-flows-and-agents.json` declaring `flows` and `agents` on `components.schemas.Application.properties`, both `type: array`, `default: []`, items `additionalProperties: false` with `label` (string, maxLength 128) plus one required slug field (`flow` / `agent`) patterned `^[a-z0-9][a-z0-9-]*[a-z0-9]$`, minLength 2, maxLength 64

  Acceptance criteria:
  - The `flow` property's description names the OpenRegister **`Flow` entity** as the referent, and states that a flow using agentic nodes is an ordinary flow rather than a separate kind (ADR-065)
  - The fragment carries the `_comment` convention used by `20-data-registers.json`: the ADR references, what `deepMergeConfig` merges, and the name of the follower spec
  - Neither property appears in `Application.required`

- [x] Verify the merge lands on a real instance: reload settings, read the `application` schema back, and confirm it now declares 17 properties with `flows` and `agents` present and `Application.required` unchanged

  Acceptance criteria:
  - Read back through `SchemaMapper`, not from the JSON file — the file is the input, the schema is the result
  - `dataRegisters` and `connectors` are still present and unmodified

## 2. Prove the binding accepts and refuses the right things

- [x] Save an application object carrying both bindings and read it back unchanged

  Acceptance criteria:
  - Uses the `hydra-console` shape from design.md's Seed Data section
  - The round-trip preserves entry order and both fields of every entry

- [x] Prove the constraints can FAIL — a control, not a demonstration

  Acceptance criteria:
  - An entry with an unknown key (e.g. `flowSlug`) is REFUSED by `additionalProperties: false`
  - An entry whose slug breaks the pattern (uppercase, leading hyphen, one character) is REFUSED
  - A numeric id in place of a slug is REFUSED
  - Each refusal is observed as a refusal; a test that only asserts the happy path cannot tell a working constraint from an absent one

- [x] Prove an application that binds nothing, and one whose binding dangles, both stay valid

  Acceptance criteria:
  - An existing application with no `flows`/`agents` validates and re-saves untouched — verified against one of the 30 objects already on the instance, not a fixture
  - An application binding a slug that resolves to no flow still validates and saves (resolvability is the consumer's problem, per design decision 2)

## 3. Record it

- [x] Note in the spec that the `agentflow` object store still mirrors some `Flow` entities and that the binding addresses the entity

  Acceptance criteria:
  - States the observed failure: a definition written to the object left the engine executing the previous graph, with no error anywhere
  - The follower spec can rely on this without rediscovering it

Quality reminders (not checkboxes): no PHP, controller, route or listener may be added by this change — it is `kind: config` and any code surface belongs to the follower. Run the app's JSON lint and `composer check:strict` to confirm the fragment parses and nothing else moved. Fix any pre-existing quality issue encountered in files this change touches.

## Applied — measured results (2026-08-15)

Schema read back through `SchemaMapper`, not from the fragment file:

- `application` now declares **17** properties (was 15); `flows` and `agents` present with `type: array`, `required: ["flow"]` / `["agent"]`, `additionalProperties: false`, item keys `flow|agent` + `label`.
- `Application.required` is still `["slug","name"]` — neither binding was added to it.
- `dataRegisters` and `connectors` unchanged.

Constraint behaviour, each observed as an accept **or** a refusal against a paired control:

| case | result |
| --- | --- |
| valid binding, round-tripped | ACCEPTED, both fields preserved |
| valid entry **plus** an unknown key (`typo`) | REFUSED — `failed validation for rule 'additionalProperties'` |
| same entry without the unknown key (control) | ACCEPTED |
| slug with uppercase (`Hydra-Sequencer`) | REFUSED — pattern |
| numeric id in place of a slug (`5020`) | REFUSED — type |
| no bindings at all | ACCEPTED |
| dangling slug (`flow-that-was-deleted`) | ACCEPTED |

⚠️ The first attempt at the unknown-key test OMITTED `flow`, so it was refused for a MISSING REQUIRED PROPERTY and proved nothing about `additionalProperties`. Re-run with `flow` present plus a stray key, and paired with an accepted control — a refusal with no matching acceptance cannot distinguish a working rule from a rule that refuses everything.

### One acceptance criterion could NOT be verified as written

"An existing application validates and re-saves untouched — verified against one of the 30 objects already on the instance."

Re-saving any of the 30 is refused by OpenBuild business rules unrelated to this change: `An app's type is immutable once created` and `A hybrid app's slug is read-only`. Copying a real payload to a new slug hits the same guards — 8 of 8 rejected, none by schema validation. Calling `ValidateObject::validateObject()` directly throws inside opis/json-schema on the schema argument form.

What IS established: both properties are optional (`required` unchanged, measured), and an object carrying no bindings saves (measured). An absent optional property cannot fail validation, so the 30 existing objects remain valid — but that is an argument from two measurements, not a direct observation of those objects passing, and it is recorded as such rather than ticked as if it ran.

Instance left as found: 30 applications, 0 probe leftovers, 0 apps carrying bindings (back-filling is a Non-Goal).
