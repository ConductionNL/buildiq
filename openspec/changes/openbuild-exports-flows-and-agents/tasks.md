## 1. Bundle flows and agents into the export

- [x] Add `lib/Service/FlowAndAgentExportBundler.php`, resolving `flows` by UUID via `FlowMapper::findByUuid()` and agents by querying `agent.applicationSlug`, writing `lib/Settings/flows/<uuid>.json` and `lib/Settings/agents/<uuid>.json` into the scaffold

  Acceptance criteria:
  - Flows are read from the **`Flow` entity**, never from the `agentflow` object store
  - No branch on node type: a flow containing `hermiq.workload-step` takes the same path and emits the same shape as one containing none
  - An unresolvable binding is skipped and RETURNED as a skip record, not only logged

- [x] Wire it into `ExportService::generateAppZip()` and `buildScaffoldMap()`, and accept `flows` in `ExportJobService`, sanitised as `dataRegisters` already is (no `agents` payload: agents follow from the application)

  Acceptance criteria:
  - Skips reach the export job RESULT, where an operator reads them
  - An export binding nothing behaves exactly as it does today

- [x] Prove the entity is the source with a DIVERGENT pair

  Acceptance criteria:
  - Fixture where the `Flow` entity and the `agentflow` object differ in node count; the export MUST carry the entity's
  - This is the control: a fixture where both stores agree cannot tell the right implementation from the wrong one

## 2. Make an imported flow runnable

- [x] Add flow/agent seeding to the scaffold's `lib/Repair/InitializeSettings.php`, reading `lib/Settings/flows/` and writing the `Flow` entity

  Acceptance criteria:
  - Registered under the existing `<post-migration>` repair step, so it runs on install AND upgrade
  - Writes the `Flow` entity — the same store the exporter reads
  - Seeds the app's own definitions only; it must not become a migration over user objects

- [x] Make seeding idempotent by UUID, and preserve a locally edited flow

  Acceptance criteria:
  - Seeding twice yields exactly one `Flow` per UUID
  - A flow modified on the importing instance is NOT silently overwritten on upgrade; the divergence is recorded where an operator can see it
  - Store one fingerprint per seeded flow to tell "unmodified" from "edited" (design decision 4)

- [x] Surface a flow whose node type is not in the engine's registry

  Acceptance criteria:
  - Detected at SEEDING, not at first run
  - Reported rather than refused — an app may ship a flow for a capability installed later
  - Exercised with a flow containing `nonexistent.node`

## 3. Test it, including the half that fails silently

- [x] Unit-test the bundler and the seeder, controlled by mutation

  Acceptance criteria:
  - Each test is shown to FAIL against the defect it guards: resolving the object store instead of the entity, dropping a skip instead of returning it, seeding install-only, clobbering a local edit
  - A test that only asserts the happy path is not accepted as coverage for any of these

- [x] Playwright: a flow is bound and exported, and the ZIP contains it plus the app's agents

  Acceptance criteria:
  - Exercises the real export endpoint and the produced ZIP, not the service in isolation
  - Asserts on ZIP contents, and on the skip appearing in the job result for a dangling binding

- [x] Playwright: import the exported ZIP on an instance without the flow, then RUN it

  Acceptance criteria:
  - Acceptance is a flow RUN that executes its nodes — not a file listing and not a read-back
  - This is the only test that can distinguish a working import from one that produces flows the engine never sees

## 4. Record what this does not fix

- [x] Note in the spec that the `agentflow` object store remains, is read by nothing here, and is a separate consolidation

  Acceptance criteria:
  - States the observed drift and why the entity is authoritative, so the next reader does not re-derive it

Quality reminders (not checkboxes): `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the Vue lint/format must pass; fix any pre-existing issue in files this change touches rather than leaving it. i18n any operator-visible string, including the skip and unregistered-node-type reports. No second flow engine, no second definition format, no special case for hermiq (ADR-065).

## Applied (2026-08-15)

841 openbuild tests green, run where `ZipArchive` exists — the ad-hoc php:8.3-cli image lacks the zip extension and reported 8 spurious errors that had nothing to do with this change.

**Mutation-controlled, each killing exactly the test that guards it:**

| mutation | test that died |
| --- | --- |
| drop the skip instead of returning it | `testADanglingBindingIsReturnedAsASkip` |
| stop writing the UUID into the definition | `testABoundFlowIsWrittenWithItsUuid` |
| look agents up by something other than `applicationSlug` | `testAgentsAreResolvedByApplicationSlugRatherThanABinding` |
| mint a new UUID on seed | `testTheShippedUuidIsPreservedRatherThanMinted` |
| last-writer-wins over a local edit | `testALocallyEditedFlowIsNotOverwritten` |
| seed enabled | `testASeededFlowArrivesDisabled` |

### Three things implementation corrected

1. **`FlowMapper` has no `createFromArray()`.** It extends `QBMapper`, so the write surface is `insert()`/`update()` over an ENTITY. The first seeder called a method that does not exist.
2. **My test wrote fixtures into the source tree.** It passed as the file owner and failed as `www-data`, because a test that writes into `lib/Resources/template/` needs write access to the app under test — and leaves artefacts in the repository when it succeeds. The flow directory is now a constructor parameter and the test uses a temp dir.
3. **Adding a required constructor argument broke 11 existing tests.** Fixed by giving the two construction sites the collaborator rather than making it optional — an optional bundler would let production run without bundling and report success.

### Known gaps, stated rather than hidden

- **No builder UI picker.** An operator cannot yet choose flows in the export dialog; the e2e binds via the API. The dialog work is a follow-up.
- **The e2e round trip re-imports onto the SAME instance.** It exercises definition → entity → execution and UUID preservation, which is the chain that fails silently. It does not exercise cross-instance collision, which needs a second Nextcloud.
- **One eslint rule fails on the new spec** (`import-extensions/extensions`, wanting `./support/baseUrl.ts`). All 22 e2e specs violate it and `allowImportingTsExtensions` is unset, so adding the extension would break compilation. Pre-existing repo-wide config mismatch; not fixed here.
