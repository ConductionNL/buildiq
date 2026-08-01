## Context

App-repo format v2 grew four channels. Six steps carry an app from one instance to
another: **serialize → bind → push → fetch → parse → apply**. Five are built. The sixth
was never written, and because everything upstream of it goes green, nothing in the system
noticed.

Existing machinery this design builds on, rather than reinvents:

| existing | reused for |
|---|---|
| `GitHubAppSyncService::findOrCreateRegister()` | the create-if-absent register pattern |
| `reconcileCompanionSchemas()` | precedent that a pull provisions into a register without clobbering live data |
| `ObjectService::saveObject(uuid:, failIfExists:)` | UUID-preserving upsert with a built-in do-not-clobber flag |
| hermiq `POST /api/skills/bundle/install` | the entire skills channel — it takes owner/repo/ref and fetches itself |
| `AppRepoSerializer::CONNECTOR_KINDS` | `['source', 'mapping', 'synchronization', 'job']` |

## Goals / Non-Goals

**Goals:**
- Installing a published v2 app yields an app that runs, or states precisely why it cannot.
- One applier, called from both install seams, so the two cannot drift apart again.
- Every declared item is accounted for: `created + skipped + failed == declared`.
- Destructive outcomes are structurally impossible, not merely avoided by convention.

**Non-Goals:**
- **Atomicity.** OpenRegister has no cross-object transaction. Faking a rollback would be
  worse than a truthful partial report.
- **Updating existing objects.** This change only ever creates. Update/merge semantics for
  a re-install need an ownership model that does not exist yet — see Deferred below.
- **Reimplementing skill installation.** hermiq owns it.
- **Making `openconnector` or `hermiq` hard dependencies.**

## Decisions

### Connector collisions skip, never overwrite

A connector is shared infrastructure — one source can serve several applications. If
installing an app overwrote a colliding UUID, installing app B could silently rewrite a
source app A depends on, at a moment when the user believes they are only adding something.
That failure is invisible, arrives later, and is attributed to the wrong cause.

A stale binding, by contrast, is inert and reported. **Skip-and-report is chosen because it
makes the destructive outcome unreachable**, not merely unlikely.

Enforced with `saveObject(uuid: $uuid, failIfExists: true)` — the guarantee lives in the
call, not in a preceding existence check that a future edit could drift away from. A
check-then-write would also race; `failIfExists` will not.

### Apply is best effort, and the report must balance

`created + skipped + failed == declared` is asserted **in the applier itself**, not only in
tests. This workstream has already produced one silent cap (94 skills sent, 64 bundled, all
94 reported as published) and three false "X does not exist" claims. An arithmetic identity
that the code enforces makes a dropped item impossible to hide, whatever the cause.

### Skills delegate by coordinates

hermiq's `bundleInstall` takes owner/repo/ref and fetches for itself, so the applier passes
coordinates rather than 746 blobs. This keeps skill semantics — frontmatter byte-fidelity,
aux-file placement, the `learning-candidates.md` exclusion of ADR-068 §3 — in the one place
that implements them.

The call crosses an app boundary, so it is made through the app framework rather than HTTP
where possible; if the route is used, its response is parsed for the counts hermiq reports
and those counts go into our report unmodified.

### Optional dependencies degrade with a machine-readable reason

Checked via `IAppManager::isEnabledForUser()`. A skipped channel keeps its **declared**
count, so a user can see that 94 skills were declared and 0 applied. Reporting `declared: 0`
because the handler is missing would be the same lie as the silent cap.

### Bounds

`MAX_REGISTERS = 64`, `MAX_CONNECTORS_PER_KIND = 2048`, `MAX_AUTOMATIONS = 512`. Truncation
is logged **and** carried in the report as a `truncated` count. hydra publishes 746 files,
so these are real limits, not theoretical ones.

### Declarative-vs-imperative decision (ADR-031)

| behaviour | path | rationale |
|---|---|---|
| Applying channels at install time | **imperative** | ADR-031 explicitly exempts external integration. This is orchestration across an app boundary (GitHub, `openconnector`, hermiq) at a discrete moment, not derived state over OpenRegister objects. There is no field to calculate and no lifecycle to declare — a declarative expression cannot create a register or call another app. |

No lifecycle, aggregation, calculation, notification, relation or widget behaviour is
introduced, so no `x-openregister-*` schema-register patch applies to this change.

### Seed Data (ADR-001)

**Not applicable — this change defines no OpenRegister schema.** It applies schemas and
objects that a *published app repository* carries; the seed material is the published
artifact itself. Two real artifacts serve as the fixtures for verification:

| artifact | contents |
|---|---|
| `ConductionNL/buildiq-spectr` (private) | 46 blobs — 1 data register, 4 connector kinds, 42 declared / 0 missing |
| `ConductionNL/buildiq-hydra` (private) | 748 blobs — 1 data register, 94 skills |

Unit fixtures use the nil UUID `00000000-0000-0000-0000-000000000000` and placeholder
credential names, never realistic-looking values.

## Risks / Trade-offs

- **Partial application leaves a half-installed app.** Accepted and made visible rather
  than hidden. The alternative — a compensating delete pass — would mean this change
  deletes objects, and a bug in that pass destroys user data. Not worth it.
- **A skipped connector leaves the app bound to something stale or absent.** Reported, not
  silently tolerated; `needsCredentials` and the skip reasons tell the user what to fix.
- **Re-install does not update.** A second install of a newer version applies nothing new
  for already-present UUIDs. This is the honest consequence of never overwriting, and it is
  deferred rather than guessed at.
- **Unit tests will pass while the feature is broken.** They have at every prior stage of
  this workstream. The acceptance evidence is therefore a **live install of both published
  artifacts**, with counts compared against the published repositories rather than against
  the applier's own report.

## Deferred

- **Update/merge on re-install**, which needs an ownership model (`which app owns this
  connector`) that does not exist today. Until it does, skip-and-report is the only
  non-destructive answer.
