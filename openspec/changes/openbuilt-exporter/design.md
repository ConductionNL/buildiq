## Context

OpenBuilt's spec #1 (`bootstrap-openbuilt`) committed to a **hybrid** architecture:
virtual apps now, exportable to real Nextcloud apps later. The chain specs built out
the runtime, versioning model, RBAC, schema and page editors, and a templates
marketplace. This is the graduation spec — it ships the "exportable later" half.

The Conduction stack already supplies the reference points this exporter targets:

- `apps-extra/nextcloud-app-template/` — the canonical scaffold used by `/app-create`
  to bootstrap any new Nextcloud app. Carries the Conduction-standard PHPCS / PHPMD /
  Psalm / PHPStan / PHPUnit toolchain, the `.github/workflows/*` pipelines, the
  EUPL-1.2 license, and the Tier-4 `<CnAppRoot>` consumer pattern.
- `@conduction/nextcloud-vue`'s `useAppManifest(appId, bundled)` — the bundled-manifest
  hook the exported app calls at boot (ADR-024).
- OpenRegister's `ConfigurationService::importFromApp()` — the repair-step hook the
  exported app's `InitializeSettings.php` invokes to register its companion schemas.
- Nextcloud's `ICredentialsManager` — the built-in surface for encrypting user secrets
  (GitHub PATs) at rest.

The export pipeline must produce a tree shape **identical** to what `/app-create` would
produce by hand, plus a populated manifest and register derived from the source virtual
app. Anything else creates a forked dialect and breaks the "graduate to a real app"
promise.

## Goals / Non-Goals

**Goals:**

- Generate a complete, installable Nextcloud app tree from a published `Application`
  record + companion schemas + (optionally) sample data.
- Match the `nextcloud-app-template` baseline byte-for-byte (modulo placeholder
  replacement) so reviewers can apply standard Conduction code-quality checks without
  modifications.
- Ship two delivery targets: ZIP download and GitHub-repo push + placeholder PR.
- Run async via Nextcloud's `IJob` so the UI stays responsive during the potentially
  slow GitHub round-trip.
- Honour ADR-024 Tier-4 strictly in the exported app — bundled manifest, top-level
  `<CnAppRoot>` mount, no nested arrangement, no per-slug endpoint.
- Honour ADR-022 strictly — the exported app's companion schemas live in OR under the
  new app's own namespace, not OpenBuilt's.
- Re-exports of the same version are byte-equivalent (no clock drift, no random tokens,
  no embedded instance identity).

**Non-Goals:**

- **Re-import** of an exported app back into OpenBuilt as a virtual app. Tracked as
  Open Question OQ-1; defer to `openbuilt-import-from-app`.
- **Sync** between an exported app and its source virtual app. A graduated app is
  independent — diverging changes are the graduated app's business. Re-exports
  overwrite; they do not merge.
- **Visual diff / preview** before download. `ExportDialog.vue` shows form inputs only;
  the user inspects the result by unzipping or visiting the GitHub PR.
- **Cross-repo dependency rewriting** — OpenConnector source URLs are copied verbatim.
- **Org-level OAuth for GitHub** — user-supplied PAT is the v1 auth path; app-level
  OAuth is deferred.
- **Live re-render of the exported app inside OpenBuilt** — once exported, the user
  works in the new repo via standard developer tooling.

## Decisions

### Decision 1 — Template source: embedded snapshot, not live reference

The exporter SHALL ship a **checked-in copy** of `nextcloud-app-template/` under
`lib/Resources/template/`, snapshotted at OpenBuilt's build time. The exporter SHALL
NOT clone or fetch `nextcloud-app-template` at export time.

**Rationale**: Reproducibility. If the exporter pulled the template live, an upstream
churn between two exports of the same Application version would silently produce
diverging archives, breaking the byte-equivalence requirement. Embedding the snapshot
also means the exporter has no network dependency for the ZIP path.

**Refresh procedure**: when `nextcloud-app-template` ships a meaningful update,
OpenBuilt cuts a new minor release that re-snapshots the template into
`lib/Resources/template/`. Document in `docs/releasing.md`.

**Alternatives considered**:

- *Live `git clone` at export time* — rejected: reproducibility and network dependency.
- *Git submodule on `nextcloud-app-template`* — rejected: same drift risk; submodule
  UX is an ops burden for install / update flows.
- *Reference the template from `apps-extra/` at runtime on the same Nextcloud instance*
  — rejected: assumes a dev-env layout that no production install will have.

### Decision 2 — Always async; single code path

The controller endpoint SHALL always create an ExportJob in `queued` state and schedule
the background job. It SHALL NOT branch to a synchronous path even for small ZIPs. The
frontend SHALL poll until terminal state.

**Rationale**: a single code path is easier to reason about, test, and retry. The
sync-fast-path optimisation buys ~3 seconds in the best case and complicates every
error path. Skip the optimisation; collect the time back via aggressive `IJob`
scheduling.

**Alternatives considered**: *Sync sub-1 MB ZIPs, async otherwise* — rejected. *SSE /
WebSocket push for completion* — deferred; OR REST polling is the established pattern.

### Decision 3 — GitHub auth: user-supplied PAT via ICredentialsManager

The frontend dialog SHALL collect the GitHub PAT in a password input and transmit it
over the standard authenticated Nextcloud REST channel. The backend SHALL store it via
`OCP\Security\ICredentialsManager` keyed by `openbuilt.export.<jobUuid>.pat`. The
background job SHALL fetch the PAT once at the start of the GitHub phase and delete the
credential record on terminal state (succeeded or failed).

**Security review checklist** (hard gate on the apply PR):

- PAT never echoed in API responses.
- PAT never logged (stdout, error logs, ExportJob `log` array).
- PAT never persisted on the ExportJob object.
- PAT cleared on success **and** on failure.
- Audit-trail entry names only the GitHub org / repo, never the token.
- Token-scope guidance surfaced in the dialog when `target=github` is selected.

**Alternatives considered**: *App-level OAuth (Conduction-owned GitHub App)* — better
UX but requires a proxy, a registered GitHub App, per-instance install flow, and a
rotation story; defer. *Per-user OAuth via Nextcloud's external OAuth flow* — same
weight; same defer.

### Decision 4 — Companion schema namespacing in the exported app

The exported app's companion schemas SHALL live in
`lib/Settings/<newapp>_register.json` declaring a fresh OR register namespace named
identically to the exported `appId`. The exporter SHALL **rewrite** every
`config.register: "openbuilt"` reference inside `src/manifest.json` to
`config.register: "<newapp>"`. Schema names themselves are preserved verbatim — only
the register namespace changes.

**Rationale**: ADR-022 — apps own their own register namespace. Letting the exported app
continue to reach into `openbuilt`'s namespace would create a runtime dependency on
OpenBuilt, defeating graduation.

**Alternatives considered**: *Keep schemas in the `openbuilt` namespace* — rejected;
violates standalone-boot requirement. *Always slug-prefix schema names* — rejected;
over-engineers a collision case that namespace separation already prevents.

### Decision 5 — Manifest `version` field tracks export time

The exported `src/manifest.json`'s top-level `version` field SHALL be set to the
ExportJob's `applicationVersion` input. `appinfo/info.xml` `<version>` SHALL be set
to the same value.

**Rationale**: the exported app inherits the source's published semver. After
graduation the new repo's release pipeline takes over version bumps.

**Alternatives considered**: *Reset to `0.1.0` on export* — discards the source
Application's release history. *Append `-exported` pre-release identifier* — pollutes
the semver.

### Decision 6 — License default: EUPL-1.2, user-overridable

The exported `LICENSE` file SHALL default to EUPL-1.2. The Export dialog SHALL surface
a license picker with EUPL-1.2 (default), MIT, and Apache-2.0. The chosen license SHALL
be written into `LICENSE` and into the SPDX-License-Identifier of every emitted PHP
file's docblock (per the SPDX-in-docblock rule).

**Rationale**: EUPL-1.2 is the Conduction default. Letting the user override at export
time prevents post-graduation license swaps (which require updating every file's SPDX
tag).

**Alternatives considered**: *Hard-code EUPL-1.2; no override* — rejected: graduated
apps are the owner's property; they may have org-policy reasons. *Allow arbitrary SPDX
identifiers* — deferred for v1 to limit blast radius.

### Decision 7 — Declarative-vs-imperative (ADR-031)

ExportJob lifecycle (`queued → running → succeeded|failed`) SHALL be declared as
`x-openregister-lifecycle` on the ExportJob schema. The exporter pipeline itself (file
generation, git ops, GitHub API calls) is unavoidably code per ADR-031 §Exceptions (3).

| Behaviour | Path |
|---|---|
| ExportJob lifecycle | **Declarative** — `x-openregister-lifecycle` on the ExportJob schema. Transitions emit audit events + CloudEvents per OR's standard. |
| File-tree generation | **Code** — `lib/Service/ExportService.php`. Documented exception. |
| Placeholder token resolution | **Code** — `lib/Service/PlaceholderResolver.php`. Documented exception. |
| GitHub API calls | **Code** — `lib/Service/GitHubPushService.php`. Documented exception. |
| ZIP packaging | **Code** — uses PHP's `ZipArchive`. Documented exception. |
| Background job orchestration | **Code** — `lib/BackgroundJob/RunExportJob.php` advancing the declarative state machine. The state machine itself is declarative; the job is the runner. |

**Anti-pattern explicitly avoided**: no `ExportJobStateMachine.php`,
no `ExportJobLifecycleService.php`. State transitions go through OR's lifecycle engine.

### Decision 8 — Background-job retry on transient failure

`RunExportJob` SHALL NOT retry automatically on failure. A failed ExportJob enters
`status: failed` terminally; the user re-submits a new ExportJob (which gets a new UUID
and a fresh PAT prompt). This matches the "no-loop architecture: crashes → needs-input"
rule — auto-retry hides root causes and (for the GitHub path) risks double-creating
repos, pushing partial trees, or leaking PATs.

### Seed Data

Every schema-shipping change documents its seed data per ADR-031 conventions.
This change adds the `ExportJob` schema to `lib/Settings/openbuilt_register.json`.
No `ExportJob` seed records are seeded at install time — export jobs are created at
runtime by user action, not by a repair step.

The following examples document the expected shape of ExportJob objects in a Dutch
municipality context (for reference during testing and QA):

```json
[
  {
    "uuid": "a1b2c3d4-0001-0000-0000-000000000001",
    "applicationUuid": "f0f0f0f0-aaaa-bbbb-cccc-000000000001",
    "applicationVersion": "1.0.0",
    "target": "zip",
    "status": "succeeded",
    "includeSeedData": false,
    "downloadUrl": "/index.php/apps/openbuilt/api/exports/a1b2c3d4-0001-0000-0000-000000000001/download",
    "downloadExpiresAt": "2026-05-21T10:00:00Z",
    "log": ["template-copy", "placeholder-replacement", "manifest-bundling", "schema-emission", "archive-or-push", "complete"]
  },
  {
    "uuid": "a1b2c3d4-0002-0000-0000-000000000002",
    "applicationUuid": "f0f0f0f0-aaaa-bbbb-cccc-000000000002",
    "applicationVersion": "2.3.1",
    "target": "github",
    "status": "succeeded",
    "githubOrg": "gemeente-amsterdam",
    "githubRepo": "melding-systeem",
    "githubVisibility": "private",
    "includeSeedData": true,
    "downloadUrl": "https://github.com/gemeente-amsterdam/melding-systeem/pull/1",
    "log": ["template-copy", "placeholder-replacement", "manifest-bundling", "schema-emission", "archive-or-push", "complete"]
  },
  {
    "uuid": "a1b2c3d4-0003-0000-0000-000000000003",
    "applicationUuid": "f0f0f0f0-aaaa-bbbb-cccc-000000000003",
    "applicationVersion": "0.5.0",
    "target": "zip",
    "status": "failed",
    "includeSeedData": false,
    "errorMessage": "Placeholder resolver: template file 'appinfo/info.xml' could not be read from snapshot",
    "log": ["template-copy", "placeholder-replacement"]
  },
  {
    "uuid": "a1b2c3d4-0004-0000-0000-000000000004",
    "applicationUuid": "f0f0f0f0-aaaa-bbbb-cccc-000000000001",
    "applicationVersion": "1.0.0",
    "target": "github",
    "status": "failed",
    "githubOrg": "gemeente-rotterdam",
    "githubRepo": "klachten-beheer",
    "githubVisibility": "public",
    "includeSeedData": false,
    "errorMessage": "GitHub auth failure: 401 Unauthorized (PAT may be expired or lack 'repo' scope)",
    "log": ["template-copy", "placeholder-replacement", "manifest-bundling", "schema-emission"]
  },
  {
    "uuid": "a1b2c3d4-0005-0000-0000-000000000005",
    "applicationUuid": "f0f0f0f0-aaaa-bbbb-cccc-000000000004",
    "applicationVersion": "3.0.0",
    "target": "zip",
    "status": "running",
    "includeSeedData": true,
    "log": ["template-copy", "placeholder-replacement"]
  }
]
```

No `lib/Repair/InitializeSettings.php` is added for seeding ExportJob objects — these
are runtime-created artifacts, not install-time data.

## Risks / Trade-offs

- **Risk** — *Embedded template snapshot drifts from upstream `nextcloud-app-template`.*
  → Mitigation: document the resnapshot procedure in `docs/releasing.md`; add a CI
  check that diffs `lib/Resources/template/` against
  `apps-extra/nextcloud-app-template/` and warns (not fails) on drift older than 90 days.
- **Risk** — *GitHub API rate limiting on bulk exports.* → Mitigation: a single export
  does at most ~5 GitHub API calls — well under the 5000/hour PAT limit. If the
  marketplace spec later adds "export many at once", revisit with a per-org rate limiter.
- **Risk** — *User PAT mishandled.* → Mitigation: the Decision 3 security checklist is
  a hard gate on the security-review pass for the apply PR. Add a Newman test asserting
  the ExportJob object never contains the PAT after completion.
- **Risk** — *Generating valid PHP / Vue from a manifest is non-trivial — early exports
  may not pass the exported app's own `composer check:strict`.* → Mitigation: scope v1
  to "thin shell" emission only — the exported app ships routes + `<CnAppRoot>` + the
  bundled manifest, NOT manifest-driven generated PHP controllers. Run
  `composer check:strict` against a freshly exported `hello-world` app in CI.
- **Risk** — *Re-exports drift because of timestamps embedded by `composer` / `npm`
  lockfile generation.* → Mitigation: the exporter does NOT run `composer install` or
  `npm install` — it copies the snapshot's lockfiles verbatim with only placeholder
  replacement applied.
- **Risk** — *`knplabs/github-api` lags behind upstream REST endpoints.* → Mitigation:
  the GitHub surface this spec uses (create-repo, ref creation, Contents API commits,
  PR creation) is stable. If a future feature needs a newer endpoint, swap to direct
  cURL in `GitHubPushService` — no architectural change.
- **Trade-off** — *Embedded template snapshot bloats install size by ~200 files.*
  → Acceptable. The template is single-digit MB; reproducibility outweighs disk cost.
- **Trade-off** — *CI cannot exercise the GitHub-push path against real GitHub.*
  → Mitigation: GitHub path covered by a mocked `GitHubClient` interface in PHPUnit,
  plus a one-off manual integration test against a Conduction-owned scratch org.

## Open Questions

- **OQ-1 — Re-import path for exported apps.** Should an exported app be re-importable
  as a virtual Application? Use cases: a graduated team wants to share their manifest
  back to the marketplace, or revert to virtual hosting to drop ops burden. *Provisional
  decision*: defer to `openbuilt-import-from-app`. Subtleties around hand-coded PHP
  and merge strategy deserve their own spec.
- **OQ-2 — GitHub default branch detection.** The placeholder PR needs to target the
  receiving repo's default branch. For a brand-new repo created by the exporter, the
  default is whatever GitHub initialises (currently `main`, but org-level rulesets may
  override). *Provisional decision*: create the repo with `auto_init: false`, push the
  exported tree to `bootstrap`, set the repo's default to `development` if the user's
  org has the Conduction-standard ruleset (detectable via the GitHub API), else leave
  it `main` and open the PR against `main`. Document in `docs/export-pipeline.md`.
- **OQ-3 — Storage of the in-flight exported tree.** During the background job's run,
  the partially-emitted tree needs to live on disk. *Provisional decision*: use
  Nextcloud's `IAppDataFactory` under
  `appdata_<instance>/openbuilt/work/<jobUuid>/`. Clean up on terminal state. Confirm
  during apply that the scratch area survives a Nextcloud worker restart mid-export.
- **OQ-4 — Multi-export concurrency.** If two users export from the same Application
  version simultaneously, do their jobs block each other? *Provisional decision*: no —
  each job has its own scratch directory keyed by ExportJob UUID, so they are isolated
  by construction. The only shared resource is the GitHub API quota, which is per-PAT
  (per-user) and not a cross-user concern.
- **OQ-5 — Composer/npm dependency-version drift.** The embedded template's lockfiles
  pin versions at OpenBuilt's snapshot time. *Provisional decision*: out of scope for
  the exporter — the graduated maintainer runs `composer update` / `npm update` after
  checkout. Document in the placeholder PR body.
