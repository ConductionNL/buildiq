## 1. Bundle flows and agents into the export

- [ ] Add `lib/Service/FlowAndAgentExportBundler.php`, resolving `flows` via OpenRegister's `FlowMapper` and `agents` by slug, writing `lib/Settings/flows/<slug>.json` and `lib/Settings/agents/<slug>.json` into the scaffold

  Acceptance criteria:
  - Flows are read from the **`Flow` entity**, never from the `agentflow` object store
  - No branch on node type: a flow containing `hermiq.workload-step` takes the same path and emits the same shape as one containing none
  - An unresolvable binding is skipped and RETURNED as a skip record, not only logged

- [ ] Wire it into `ExportService::generateAppZip()` and `buildScaffoldMap()`, and accept `flows`/`agents` in `ExportJobService`, sanitised as `dataRegisters` already is

  Acceptance criteria:
  - Skips reach the export job RESULT, where an operator reads them
  - An export binding nothing behaves exactly as it does today

- [ ] Prove the entity is the source with a DIVERGENT pair

  Acceptance criteria:
  - Fixture where the `Flow` entity and the `agentflow` object differ in node count; the export MUST carry the entity's
  - This is the control: a fixture where both stores agree cannot tell the right implementation from the wrong one

## 2. Make an imported flow runnable

- [ ] Add flow/agent seeding to the scaffold's `lib/Repair/InitializeSettings.php`, reading `lib/Settings/flows/` and writing the `Flow` entity

  Acceptance criteria:
  - Registered under the existing `<post-migration>` repair step, so it runs on install AND upgrade
  - Writes the `Flow` entity — the same store the exporter reads
  - Seeds the app's own definitions only; it must not become a migration over user objects

- [ ] Make seeding idempotent by slug, and preserve a locally edited flow

  Acceptance criteria:
  - Seeding twice yields exactly one `Flow` per slug
  - A flow modified on the importing instance is NOT silently overwritten on upgrade; the divergence is recorded where an operator can see it
  - Store one fingerprint per seeded flow to tell "unmodified" from "edited" (design decision 4)

- [ ] Surface a flow whose node type is not in the engine's registry

  Acceptance criteria:
  - Detected at SEEDING, not at first run
  - Reported rather than refused — an app may ship a flow for a capability installed later
  - Exercised with a flow containing `nonexistent.node`

## 3. Test it, including the half that fails silently

- [ ] Unit-test the bundler and the seeder, controlled by mutation

  Acceptance criteria:
  - Each test is shown to FAIL against the defect it guards: resolving the object store instead of the entity, dropping a skip instead of returning it, seeding install-only, clobbering a local edit
  - A test that only asserts the happy path is not accepted as coverage for any of these

- [ ] Playwright: an operator binds a flow and an agent, exports, and the ZIP contains both

  Acceptance criteria:
  - Drives the real export UI, not the service
  - Asserts on ZIP contents, and on the skip appearing in the job result for a dangling binding

- [ ] Playwright: import the exported ZIP on an instance without the flow, then RUN it

  Acceptance criteria:
  - Acceptance is a flow RUN that executes its nodes — not a file listing and not a read-back
  - This is the only test that can distinguish a working import from one that produces flows the engine never sees

## 4. Record what this does not fix

- [ ] Note in the spec that the `agentflow` object store remains, is read by nothing here, and is a separate consolidation

  Acceptance criteria:
  - States the observed drift and why the entity is authoritative, so the next reader does not re-derive it

Quality reminders (not checkboxes): `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the Vue lint/format must pass; fix any pre-existing issue in files this change touches rather than leaving it. i18n any operator-visible string, including the skip and unregistered-node-type reports. No second flow engine, no second definition format, no special case for hermiq (ADR-065).
