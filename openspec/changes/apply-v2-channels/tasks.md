## Tasks

### 1. Report structure

- [ ] Add `lib/Service/ChannelApplyReport.php` — per-channel counts (`declared`, `created`, `skipped`, `failed`, `truncated`), per-item outcomes with reasons, and a `needsCredentials` list
- [ ] Enforce the balance identity `created + skipped + failed == declared` inside the report itself, throwing when it does not hold

Acceptance criteria
- A dropped item cannot be represented — constructing an unbalanced report fails
- Every skipped or failed item carries a reason string

### 2. Applier skeleton and both seams

- [ ] Add `lib/Service/AppChannelApplier.php` with one entry point taking the parsed template plus repo coordinates
- [ ] Call the applier from `GitHubAppSyncService::pull()` after the draft Version is saved, and surface the report in its return array
- [ ] Call the applier from `ApplicationsController::installFromTemplateArray()`, and surface the report through `ShopController::githubInstall()`
- [ ] Detect optional apps via `IAppManager` and skip the dependent channel with `openconnector-unavailable` / `hermiq-unavailable`, preserving the declared count

Acceptance criteria
- A v1 template installs exactly as before, with a zero-declared report
- No install path reads the template without passing it through the applier

### 3. Data registers

- [ ] Apply the `dataRegisters` channel: create absent registers and schemas, skip existing ones as `already-exists`, never mutate
- [ ] Bound at `MAX_REGISTERS = 64` with truncation logged and counted

Acceptance criteria
- An existing register keeps its schemas and shape untouched
- A truncated channel reports a non-zero `truncated` count

### 4. Connectors

- [ ] Apply the four `CONNECTOR_KINDS` via `saveObject(uuid: <published uuid>, failIfExists: true)` so bindings resolve and a collision cannot overwrite
- [ ] Record a collision as skipped with reason `already-exists`, and continue with the remaining connectors
- [ ] Collect every unresolvable `credentialRef` into `needsCredentials`, naming the credential and the connector that needs it
- [ ] Bound at `MAX_CONNECTORS_PER_KIND = 2048` with truncation logged and counted

Acceptance criteria
- The existing object is byte-identical after a colliding apply
- One throwing connector does not abort the others

### 5. Skills and automations

- [ ] Delegate the `skills` channel to hermiq bundle install by owner/repo/ref, carrying hermiq's installed and skipped counts into the report unmodified
- [ ] Apply the `automations` channel with the same create-or-skip rules, bounded at `MAX_AUTOMATIONS = 512`

Acceptance criteria
- OpenBuild parses no skill frontmatter and places no aux files itself
- A hermiq failure is reported as a failed channel, never as success

### 6. Tests

- [ ] Unit-test the balance identity, collision skip, optional-dependency degradation, truncation reporting and credential collection, then mutation-check the collision test by removing `failIfExists` and confirming it fails

Acceptance criteria
- Every new test is proven capable of failing before it is trusted
- The collision test is confirmed red without `failIfExists`, not merely green with it

### 7. Live verification (the acceptance evidence)

- [ ] Install `ConductionNL/buildiq-spectr` onto a clean instance and assert 1 data register and 4 connector kinds land, counts compared against the published repository rather than the report
- [ ] Install `ConductionNL/buildiq-hydra` and assert 94 skills land, again compared against the published repository
- [ ] Verify a second install of the same repo skips every already-present item and reports it, changing nothing

Acceptance criteria
- Counts are read from the published artifact, never from the applier's own output
- Both repositories stay private throughout

### 8. Quality

- [ ] Run phpstan explicitly with `vendor/` installed — the local gate suite silently skips it when `vendor/` is absent, so gate-green does not imply phpstan-green
- [ ] Run `composer check:strict` and the hydra gates, and open the PR

Acceptance criteria
- phpstan is confirmed to have actually run, not merely to have not failed
