## Context

OpenBuilt's spec #1 (`bootstrap-openbuilt`) committed to a **hybrid**
architecture: virtual apps now, exportable to real Nextcloud apps later.
Specs #2-#8 fleshed out the runtime, schema editor, page editor,
versioning, RBAC, and templates marketplace. This is the final spec in
the chain — it ships the "exportable later" half and closes the loop on
the hybrid commitment.

The Conduction stack already has the reference points the exporter
targets:

- `apps-extra/nextcloud-app-template/` — the canonical scaffold used by
  `/app-create` to bootstrap any new Nextcloud app. Carries the
  Conduction-standard PHPCS / PHPMD / Psalm / PHPStan / PHPUnit
  toolchain, the `.github/workflows/*` pipelines, the EUPL-1.2
  license, and the Tier-4 `<CnAppRoot>` consumer pattern.
- `decidesk/src/manifest.json` — the canonical Tier-4 manifest
  consumer reference (ADR-024).
- `@conduction/nextcloud-vue`'s `useAppManifest(appId, bundled)` —
  the bundled-manifest hook the exported app calls at boot.
- `nextcloud-vue/src/schemas/app-manifest.schema.json` (v1.4.0+) —
  the canonical validation surface.
- OpenRegister's `lib/Service/ConfigurationService::importFromApp()` —
  the repair-step hook the exported app's `InitializeSettings.php`
  invokes to register its companion register.

The export pipeline must produce a tree shape **identical** to what
`/app-create` would produce by hand, plus a populated manifest +
register derived from the source virtual app. Anything else creates a
forked dialect and breaks the "graduate to a real app" promise.

## Goals / Non-Goals

**Goals:**

- Generate a complete, installable Nextcloud app tree from a
  published `Application` record + companion schemas + (optionally)
  sample data.
- Match the `nextcloud-app-template` baseline byte-for-byte (modulo
  placeholder replacement) so reviewers can apply standard
  Conduction code-quality checks to the exported app without
  modifications.
- Ship two delivery targets: ZIP download and GitHub-repo push +
  placeholder PR.
- Run async via Nextcloud's `IJob` so the UI stays responsive
  during the (potentially slow) GitHub round-trip.
- Honour ADR-024 Tier-4 strictly in the exported app — bundled
  manifest, top-level `<CnAppRoot>` mount, no nested arrangement,
  no per-slug endpoint.
- Honour ADR-022 strictly — the exported app's companion schemas
  live in OR under the new app's own namespace, not OpenBuilt's.
- Re-exports of the same version are byte-equivalent (no clock
  drift, no random tokens, no embedded instance identity).

**Non-Goals:**

- **Re-import** of an exported app back into OpenBuilt as a virtual
  app. Tracked as Open Question OQ-1 below; defer to a follow-on
  spec.
- **Sync** between an exported app and its source virtual app. A
  graduated app is independent — diverging changes are the
  graduated app's business. Re-exports overwrite, they do not
  merge.
- **Visual diff / preview** of the export before download. The
  frontend's "ExportDialog.vue" shows form inputs only; the user
  inspects the result by unzipping it or visiting the GitHub PR.
- **Cross-repo dependency rewriting** — if the source manifest
  references an OpenConnector source by URL, the exporter copies
  the URL verbatim. The graduated app inherits the same external
  dependencies as the virtual one.
- **Org-level OAuth for GitHub** — Decision 3 below picks
  user-supplied PAT as the v1 auth path; app-level OAuth is
  deferred.
- **Live re-render of the exported app inside OpenBuilt** — once
  exported, the user works in the new repo via standard developer
  tooling.

## Decisions

### Decision 1 — Template source: embedded snapshot, not live reference

The exporter SHALL ship a **check-in copy** of
`nextcloud-app-template/` under `lib/Resources/template/`, snapshotted
at OpenBuilt's build time. The exporter SHALL NOT clone or fetch
`nextcloud-app-template` at export time.

**Rationale**: Reproducibility. If the exporter pulled the template
live, an upstream template churn between two exports of the same
Application version would silently produce diverging archives,
breaking the byte-equivalence requirement. Embedding the snapshot
also means the exporter has no network dependency for the ZIP path
(the GitHub path obviously still does for the push).

**Refresh procedure**: when `nextcloud-app-template` ships a
meaningful update, OpenBuilt cuts a new minor release that
re-snapshots the template into `lib/Resources/template/`. This is a
standard Conduction release cadence step; document it in
`docs/releasing.md` (task 7.3 below).

**Alternatives considered**:

- *Live `git clone` at export time.* Rejected for the reproducibility
  reason above; also adds a hard network + tooling dependency to the
  ZIP path.
- *Git submodule on `nextcloud-app-template`.* Rejected: same drift
  risk as a live clone, plus submodule UX is an ops nightmare for
  the install / update flow.
- *Reference the template from `apps-extra/` at runtime on the
  same Nextcloud instance.* Rejected: assumes a Conduction dev-env
  layout that no production install will have.

### Decision 2 — Sync for small exports, async for everything

The controller endpoint `POST /api/applications/{slug}/exports`
SHALL always create an ExportJob in `queued` state and schedule the
background job; it SHALL NOT branch to a synchronous path even for
small ZIPs. The frontend SHALL poll until terminal state.

**Rationale**: a single code path is easier to reason about, easier
to test, and easier to retry. The "sync-fast-path-for-small-ZIPs"
optimisation buys ~3 seconds in the best case and complicates every
error path (what's "small"? what if estimation is wrong? what
status code does sync use? what happens on PAT failure mid-flight
for a GitHub sync export?). Skip the optimisation; collect the 3
seconds back via aggressive `IJob` scheduling instead.

**Alternatives considered**:

- *Sync sub-1MB ZIPs, async otherwise.* Rejected as above.
- *WebSocket / SSE push for completion.* Plausible upgrade, but
  Nextcloud's notification surface already offers a polling
  pattern via OR REST. Stay consistent; revisit if the polling
  load surfaces as a real problem.

### Decision 3 — GitHub auth: user-supplied PAT via ICredentialsManager

The frontend's Export dialog SHALL collect the GitHub PAT in a
single-use password input, transmit it over the standard authed
Nextcloud REST channel, and the backend SHALL store it via
`OCP\Security\ICredentialsManager` keyed by ExportJob UUID. The
background job SHALL fetch the PAT once at the start of the GitHub
phase and delete the credential record at terminal state
(succeeded or failed).

**Rationale**: PATs are the lowest-friction auth path for v1.
ICredentialsManager is built into Nextcloud, encrypts at rest, and
is the documented surface for storing user secrets. Deletion on
terminal state means no PAT survives past one export run.

**Security review checklist** (carried into task 6.4 below):

- PAT never echoed in API responses.
- PAT never logged (stdout, error logs, ExportJob `log` array).
- PAT never persisted on the ExportJob object.
- PAT cleared on success **and** on failure.
- Audit-trail entry on PAT use names only the GitHub org / repo,
  never the token.
- Token scope guidance surfaced in the dialog ("requires `repo`
  scope; private repos additionally require `write:packages` if
  you intend to publish releases").

**Alternatives considered**:

- *App-level OAuth (a Conduction-owned GitHub App).* Better UX, but
  requires a Conduction-side OAuth proxy, a registered GitHub App,
  a per-instance install flow, and a credential rotation story.
  Heavier; defer to a follow-on spec.
- *Per-user OAuth via Nextcloud's external OAuth flow.* Same
  heaviness; same defer.

### Decision 4 — Companion schema namespacing in the exported app

The exported app's companion schemas SHALL live in
`lib/Settings/<newapp>_register.json` declaring a fresh OR register
namespace named identically to the exported `appId`. The exporter
SHALL **rewrite** every `config.register: "openbuilt"` reference
inside `src/manifest.json` to `config.register: "<newapp>"`. Schema
names themselves are preserved verbatim — only the register
namespace changes.

**Rationale**: ADR-022 — apps own their own register namespace.
Letting the exported app continue to reach into `openbuilt`'s
namespace would create a runtime dependency on OpenBuilt being
installed, defeating the whole point of graduation. The marketplace
spec (chain #8) already uses the same slug-prefix discipline when
cloning a template into a fresh Application; the exporter reuses
that pattern.

**Alternatives considered**:

- *Keep schemas in the `openbuilt` namespace.* Rejected — creates
  a runtime dependency on OpenBuilt; violates the standalone-boot
  requirement.
- *Always slug-prefix schema names (e.g.,
  `hello-world.hello-message`).* Rejected — over-engineers for a
  collision case (two different exported apps using the same
  schema name across the same Nextcloud instance) that's already
  prevented by the namespace separation.

### Decision 5 — Manifest `version` field tracks export time

The exported `src/manifest.json`'s top-level `version` field SHALL
be set to the ExportJob's `applicationVersion` input (the published
version being exported). The `appinfo/info.xml` `<version>` element
SHALL be set to the same value.

**Rationale**: the exported app inherits the source's published
semver. After graduation, the new repo's release pipeline takes
over version bumps — the exporter doesn't pre-bump or zero out the
version. This keeps the bootstrap PR's diff focused on bootstrap
content, not on a synthetic version reset the maintainer has to
re-do.

**Alternatives considered**:

- *Reset to `0.1.0` on export.* Rejected — discards the source
  Application's release history at the point of graduation.
- *Append a `-exported` pre-release identifier.* Rejected — pollutes
  the semver and confuses downstream release pipelines that match
  on `^(\d+)\.(\d+)\.(\d+)$`.

### Decision 6 — License default: EUPL-1.2, user-overridable

The exported `LICENSE` file SHALL default to EUPL-1.2 (Conduction
standard, matches OpenBuilt itself, matches the embedded template
snapshot). The Export dialog SHALL surface a license picker with the
Conduction-approved set (EUPL-1.2 [default], MIT, Apache-2.0). The
chosen license SHALL be written into both `LICENSE` and the
top-level docblock SPDX-License-Identifier of every emitted PHP
file (per the SPDX-in-docblock memory rule).

**Rationale**: EUPL-1.2 is the Conduction default. Letting the
user override it at export time prevents post-graduation license
swaps (which are painful — every file's SPDX tag has to change).

**Alternatives considered**:

- *Hard-code EUPL-1.2; no override.* Rejected — graduated apps are
  the graduated owner's property; they may have org-policy reasons
  to use MIT or Apache-2.0.
- *Allow arbitrary SPDX identifiers.* Rejected for v1 — limits
  blast radius; expand the picker in a follow-on spec if real
  demand surfaces.

### Decision 7 — Declarative-vs-imperative (ADR-031)

ExportJob lifecycle (`queued → running → succeeded|failed`) SHALL
be declared as `x-openregister-lifecycle` metadata on the ExportJob
schema. The exporter pipeline itself (file generation, git ops,
GitHub API calls) is unavoidably code and falls under ADR-031
§Exceptions (3) — "operations whose only declarative shape would
be a wrapper around an imperative primitive". Document the
exception in this design and on the ExportJob schema with an
`x-openregister-lifecycle-exception` annotation pointing at this
section.

The split is therefore:

| Behaviour | Path |
|---|---|
| ExportJob lifecycle | **Declarative** — `x-openregister-lifecycle` on the ExportJob schema. Transitions emit audit events + CloudEvents per OR's standard. |
| File-tree generation | **Code** — `lib/Service/ExportService.php`. Documented exception. |
| Git push + GitHub API calls | **Code** — `lib/Service/GitHubPushService.php` (or a method on `ExportService`). Documented exception. |
| ZIP packaging | **Code** — uses PHP's `ZipArchive`. Documented exception. |
| Background job orchestration | **Code** — `lib/BackgroundJob/RunExportJob.php` driven by the schema's lifecycle states. The job's role is to advance the declarative state machine; the state machine itself is declarative. |

**Anti-pattern explicitly avoided**: no `ExportJobStateMachine.php`,
no `ExportJobLifecycleService.php`. State transitions go through
OR's lifecycle engine.

### Decision 8 — Background-job retry on transient failure

`RunExportJob` SHALL NOT retry automatically on failure. A failed
ExportJob enters `status: failed` terminally; the user re-submits a
new ExportJob (which gets a new UUID + a fresh PAT prompt). This
matches the memory rule "no-loop architecture: crashes →
needs-input" — auto-retry hides root causes and (for the GitHub
path) risks double-creating repos / pushing partial trees / leaking
PATs.

**Alternatives considered**:

- *Auto-retry up to N times with exponential backoff.* Rejected —
  see memory rule above.
- *Retry only the ZIP path; user-retry the GitHub path.* Rejected
  — inconsistent UX; the ZIP path failure modes are sufficiently
  rare that a manual retry is acceptable.

## Risks / Trade-offs

- **Risk** — *Embedded template snapshot drifts from the upstream
  `nextcloud-app-template`.* → Mitigation: document the
  resnapshot procedure in `docs/releasing.md`; add a CI check that
  diffs `lib/Resources/template/` against
  `apps-extra/nextcloud-app-template/` and warns (not fails) on
  drift older than 90 days. Avoids silent staleness.
- **Risk** — *GitHub API rate limiting on bulk exports.* → Mitigation:
  for v1, accept the constraint — a single export does at most ~5
  GitHub API calls (create-repo, push-via-libgit2-or-equivalent,
  create-branch, open-PR, set-default-branch-protections-skip). At
  org-level PAT scope, that's well under both 5000/hour and the
  abuse-detection thresholds. If the marketplace spec (chain #8)
  later adds "export many at once", revisit with a per-org rate
  limiter.
- **Risk** — *User PAT mishandled.* → Mitigation: the security
  checklist in Decision 3 is a hard gate on the security-review
  pass for this spec's apply PR. Add a Newman test that asserts
  the ExportJob object never contains the PAT after job completion
  (task 7.5 below). Calibrate severity per the
  token-severity-calibration memory rule.
- **Risk** — *Generating valid PHP / Vue from a manifest is
  non-trivial — early exports will produce trees that don't pass
  the exported app's own `composer check:strict`.* → Mitigation:
  scope v1 to "thin shell" emission only — the exported app
  ships routes + `<CnAppRoot>` + the bundled manifest, NOT
  manifest-driven generated PHP controllers. Anything more
  generative (e.g., per-schema CRUD controllers) is deferred to a
  follow-on spec. Run `composer check:strict` against a freshly
  exported `hello-world` app in CI to catch regressions (task 7.4).
- **Risk** — *Re-exports drift because of timestamps embedded by
  `composer` / `npm` lockfile generation.* → Mitigation: the
  exporter does NOT run `composer install` or `npm install`
  during emission — it copies the snapshot's
  `composer.json` / `package.json` + lockfiles verbatim, with only
  placeholder replacement applied. The graduated maintainer runs
  `composer install` once on first checkout.
- **Risk** — *`knplabs/github-api` PHP client lags behind upstream
  REST endpoints.* → Mitigation: the GitHub surface this spec
  uses (create-repo, ref creation, file commits via the Contents
  API, PR creation) is stable; the lib has covered it for years.
  If a future feature needs a newer endpoint, swap to direct cURL
  in `GitHubPushService` — no architectural change.
- **Trade-off** — *Embedded template snapshot bloats the OpenBuilt
  app's install size by ~200 files.* → Acceptable. The template
  is small (single-digit MB); the reproducibility benefit
  outweighs the disk cost.
- **Trade-off** — *Newman / PHPUnit can't easily exercise the
  GitHub-push path end-to-end against real GitHub.* → Mitigation:
  the GitHub path is covered by a mocked
  `GitHubClient` interface in PHPUnit (task 7.2), plus a
  one-off manual integration test against a Conduction-owned
  scratch org documented in `docs/integration-tests.md`. Don't
  hit real GitHub in CI.

## Migration Plan

This is a purely additive spec. Deployment steps:

1. Land the change on a feature branch from `development` (already
   set up: `feature/spec-openbuilt-export-to-real-app`).
2. CI runs PHPUnit + Newman + Playwright. The Newman suite covers
   the controller's 202-on-submit + the ExportJob CRUD via OR REST.
   Playwright covers the dialog flow against a mocked exporter
   service that returns success without doing real work
   (a separate "live" Playwright job exercises the real ZIP path
   end-to-end against a seeded `hello-world` Application).
3. Merge into `development`. The migration runs on next deploy via
   the repair step; the `ExportJob` schema appears on every
   install. Existing `Application` + `BuiltAppRoute` records are
   unaffected.
4. **Rollback** — disable the new endpoints by removing the
   `<background-jobs>` registration and the two routes; or
   `occ app:disable openbuilt` rolls back the whole shell. ExportJob
   records remain in the database (harmless; no other Conduction
   app reads them). To fully rollback, additionally remove the
   `ExportJob` schema from the `openbuilt` register namespace via
   OR's admin UI.

## Open Questions

- **OQ-1 — Re-import path for exported apps.** Should an exported
  app be re-importable as a virtual Application (the inverse of
  graduation)? Use cases: a graduated team wants to share their
  manifest back to the OpenBuilt marketplace; or a graduated team
  wants to revert to virtual hosting to drop their ops burden.
  *Provisional decision*: defer to a follow-on spec
  (`openbuilt-import-from-app`). Track here as out of scope; the
  reverse direction has subtleties (what about hand-coded PHP
  added to the graduated app? merge strategy?) that deserve their
  own spec.
- **OQ-2 — GitHub default branch detection.** The placeholder PR
  needs to target the receiving repo's default branch. For a
  brand-new repo created by the exporter, the default is whatever
  GitHub initialises it as (currently `main` for new repos, but
  org-level rulesets may override). *Provisional decision*: create
  the repo with `auto_init: false`, push the exported tree to
  `bootstrap`, set the repo's default to `development` if the
  user's org has the Conduction-standard ruleset (detectable via
  the GitHub API), else leave it `main` and open the PR against
  `main`. Document the heuristic in `docs/export-pipeline.md`.
- **OQ-3 — Storage of the in-flight exported tree.** During the
  background job's run, the partially-emitted tree needs to live
  somewhere on disk. *Provisional decision*: use Nextcloud's
  `IAppDataFactory` under `appdata_<instance>/openbuilt/work/<jobUuid>/`.
  Clean up on terminal state. Confirm during apply that the
  scratch area survives a Nextcloud worker restart mid-export
  (it should; `IAppDataFactory` is durable storage).
- **OQ-4 — Multi-export concurrency.** If two users export from
  the same Application version at the same time, do their jobs
  block each other? *Provisional decision*: no — each job has its
  own scratch directory keyed by ExportJob UUID, so they're
  isolated by construction. The only shared resource is the
  GitHub API quota, which is per-PAT (per-user) and not a
  cross-user concern.
- **OQ-5 — Composer/npm dependency-version drift.** The embedded
  template's `composer.json` / `package.json` pin versions at
  OpenBuilt's snapshot time. A graduated app installed months
  later may want updated deps. *Provisional decision*: out of
  scope for the exporter — the graduated maintainer runs
  `composer update` / `npm update` after checkout. Document in
  the placeholder PR's body so the maintainer sees the action item
  on first review.
