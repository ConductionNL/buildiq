# Tasks: app-repo-format-v2

## Implementation Tasks

### Task 1: Application.connectors[] binding fragment
- **spec_ref**: `openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-connectors-are-declared-explicitly-never-inferred`
- **files**: `lib/Settings/register.d/21-connectors.json`
- **notes**: ADR-037 fragment mirroring `20-data-registers.json`. `{kind, slug, label?}`, `kind` enum limited to source/mapping/synchronization/job, `slug` slug-patterned. Declarative only — no service, no route.
- **acceptance_criteria**:
  - GIVEN the fragment WHEN the register merges THEN `Application.connectors` exists and `Application.required` is unchanged
  - GIVEN an entry with an out-of-enum kind THEN it fails validation
- [ ] Implement
- [ ] Test

### Task 2: data-registers + automations collectors
- **spec_ref**: `openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-apps-whole-configuration`
- **files**: `lib/Service/AppRepoSerializer.php`, `tests/Unit/Service/AppRepoSerializerTest.php`
- **notes**: Total collectors, mirroring `collectCompanionSchemas()` — a missing source yields no entries and a debug log, never an exception. data-registers from `dataRegisters[].register`; automations by `Automation.applicationSlug`. Schema definitions only, no objects.
- **acceptance_criteria**:
  - GIVEN an app binding `spectr-live` THEN `data-registers/spectr-live.json` carries its schema definitions and no objects
  - GIVEN an app with no bindings THEN no entries and no exception
- [ ] Implement
- [ ] Test

### Task 3: connectors collector with one-level resolution
- **spec_ref**: `openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-connectors-are-declared-explicitly-never-inferred`
- **files**: `lib/Service/AppRepoSerializer.php`, `tests/Unit/Service/AppRepoSerializerTest.php`
- **notes**: Read declared entries from `Application.connectors[]` via ObjectService against register `openconnector`. Resolve ONE level from a declared entry (a synchronization's source/mapping/target) and no further. Report declared vs resolved separately.
- **acceptance_criteria**:
  - GIVEN two apps binding the same register THEN each exports only its own declared entries
  - GIVEN a declared synchronization THEN its source and mapping are exported and reported as resolved
  - GIVEN a resolved object that itself references others THEN resolution stops at one level
- [ ] Implement
- [ ] Test

### Task 4: Secret stripping
- **spec_ref**: `openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-credential-values-never-leave-the-instance`
- **files**: `lib/Service/AppRepoSerializer.php`, `tests/Unit/Service/AppRepoSerializerTest.php`
- **notes**: THE highest-risk part of this change — spectr's sources reach `intelligence-db:5433`. Preserve `credentialRef`-style references; strip values on secret-bearing keys (password/secret/token/apiKey/authorization/connectionString) and inline `://user:pass@`. Record each strip in the descriptor: a silently-emptied config is harder to debug than one that says so.
- **acceptance_criteria**:
  - GIVEN a source with an inline password THEN the emitted file does not contain it and the strip is recorded
  - GIVEN a source using `credentialRef` THEN the reference survives and no value is resolved
  - GIVEN a connection string with embedded credentials THEN the credentials are stripped
- [ ] Implement
- [ ] Test

### Task 5: Path/slug validation on emit, and descriptor channel counts
- **spec_ref**: `openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-every-channel-path-is-validated-before-use`
- **files**: `lib/Service/AppRepoSerializer.php`, `tests/Unit/Service/AppRepoSerializerTest.php`
- **notes**: Validate slug + kind BEFORE path concatenation; reject, never rewrite. Bump `FORMAT_VERSION` to 2.0 and record per-channel counts (connectors as declared/resolved) so an empty export is visible in the artefact rather than discovered on install.
- **acceptance_criteria**:
  - GIVEN a binding slug of `../../etc` THEN it is rejected and nothing is written outside its channel
  - GIVEN an app with every channel empty THEN the descriptor records zeros
- [ ] Implement
- [ ] Test

### Task 6: Parser v2 read path, v1 preserved
- **spec_ref**: `openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-apps-whole-configuration`
- **files**: `lib/Service/AppRepoParser.php`, `tests/Unit/Service/AppRepoParserTest.php`
- **notes**: Switch on `formatVersion`. 1.0 keeps today's strict path byte-for-byte. 2.0 additionally collects the new directories, re-validating every path — the parser must not trust that the repo was written by a well-behaved serializer.
- **acceptance_criteria**:
  - GIVEN a v1 repo THEN the parse result is identical to today's
  - GIVEN a v2 repo THEN the new channels are returned
  - GIVEN `connectors/source/../../evil.json` THEN it is ignored and the rest still parses
- [ ] Implement
- [ ] Test

### Task 7: Round-trip + gates
- **spec_ref**: `openspec/changes/app-repo-format-v2/proposal.md`
- **files**: `tests/Integration/ExporterEndToEndTest.php`
- **notes**: serialize → parse must reproduce every channel. Then `run-hydra-gates.sh --scope-to-diff` (must emit its summary line — an aborted run reads as green) plus `composer check:strict`. Fix pre-existing issues encountered.
- **acceptance_criteria**:
  - GIVEN a v2 app WHEN serialised and re-parsed THEN every channel round-trips
  - GIVEN the gate script WHEN run THEN ALL GATES GREEN with exit 0
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- All tests pass (`composer test`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) strings for any new user-facing text (ADR-007)
- `openspec validate` passes
