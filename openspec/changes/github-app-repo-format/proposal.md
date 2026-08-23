---
kind: code
depends_on: []
chain:
  - github-app-repo-format
  - github-shop-catalogue
  - github-app-sync
---

## Why

Buildiq can already *export* a published app to GitHub as a standalone
Nextcloud app (`GitHubPushService` + `ExportJobService`, the `buildiq-exporter`
capability): it scaffolds PHP, an `appinfo/`, a webpack build — a real installable
Nextcloud app that no longer round-trips back into Buildiq. And it can already
*consume* templates from a remote OpenRegister catalogue (`buildiq-remote-template-store`).
What it cannot do is treat **GitHub as a first-class, round-trippable home for a
virtual app's definition** — push an app's manifest + schemas to a repo, discover
other people's apps on GitHub, and pull one back in as an editable draft. Every
serious low-code platform (Retool, Budibase, Appsmith) has a Git-backed "app as
data" round-trip; Buildiq's only GitHub path is the one-way scaffold exporter.

Before any of the shop (change 2) or owner sync (change 3) work can happen, the
fleet needs **one canonical, versioned answer to "what does an Buildiq app look
like in a GitHub repo?"** — a data format (not the exporter's PHP scaffold), a
discovery contract, the linkage fields that tie a local `Application` to its
GitHub home, and a serializer/parser pair that is the single implementation of
that format. This change defines exactly that and nothing more. It is the head of
a three-change chain:

1. **`github-app-repo-format`** (this change) — the canonical repo layout, the
   `openbuild-app.json` descriptor, the discovery topic, the `Application` /
   `ApplicationVersion` linkage fields, and the `AppRepoSerializer` /
   `AppRepoParser` service pair with strict import validation.
2. **`github-shop-catalogue`** — the shop reads apps from GitHub via search
   (`topic:buildiq-app`) as a new source alongside the existing local + remote-OR
   sources, and installs one by parsing it through this change's parser.
3. **`github-app-sync`** — the owner round-trip (push/pull) from the app admin
   screen, routing every write through OpenRegister's credential broker.

This change is a **data-format / spec-heavy** change. The exporter is untouched:
its PHP-scaffold output is a different artifact for a different purpose (ship a
standalone app), and the round-trip data format defined here never tries to be an
installable app on its own — it is a definition that only Buildiq parses.

## What Changes

- **NEW** canonical GitHub repo layout for an Buildiq app (the round-trip
  **data** format, not the exporter's PHP scaffold):
  - `openbuild-app.json` — the top-level app descriptor: `slug`, `name`,
    `description`, `category`, `appType` (`virtual` | `hybrid`), `version`
    (the exported ApplicationVersion's semver), optional `icon` / `iconDark`
    refs, and an optional `credentials[]` declaration (provider / reason /
    scopes) mirroring the manifest's top-level `credentials[]` field.
  - `manifest.json` — the ApplicationVersion `manifest` blob verbatim (the
    JSON `CnAppRoot` consumes at runtime).
  - `schemas/*.json` — one file per companion schema (filename = schema slug),
    the OpenRegister schema blobs the app's data model needs.
  - optional `README.md` + `img/` (screenshots, icon SVGs referenced by the
    descriptor).
  - **Discovery contract:** a repo is an Buildiq app iff it carries the GitHub
    **topic `buildiq-app`** and a parseable `openbuild-app.json` at its root.
- **NEW** capability `github-app-repo-format`: the format definition above plus a
  serializer/parser service pair:
  - `AppRepoSerializer` — turns a local `Application` + a chosen
    `ApplicationVersion` into the ordered set of repo files (descriptor +
    manifest + one file per companion schema + optional README).
  - `AppRepoParser` — turns a fetched repo file set into an **in-memory
    template-array payload** shaped exactly like the existing
    `ApplicationTemplate` clone input, so an install reuses
    `ApplicationsController::installFromTemplateArray` (the seam extracted for
    the remote store) — no new clone/namespacing/rewrite logic.
  - **Strict import validation:** a repo whose descriptor is missing/unparseable,
    whose `appType` is unknown, whose `manifest.json` fails app-manifest schema
    validation, or whose `schemas/*.json` contains one malformed file SHALL fail
    the whole parse **loudly** with actionable, per-file error codes — never a
    silent partial import (the `manifest-validation-discards-backend-delta`
    memory rule made explicit for this format).
- **MODIFIED** `Application` schema (`lib/Settings/openbuild_register.json`) —
  two optional additive linkage fields: `githubRepo` (object `{ owner, name }`
  identifying the app's GitHub home) and `githubDefaultBranch` (string, the
  branch push/pull targets, default resolved at link time). Both omittable so
  every existing Application stays schema-valid.
- **MODIFIED** `ApplicationVersion` schema — two optional additive provenance
  fields: `commitSha` (the exact commit a version was pushed to or pulled from)
  and `sourceRef` (the branch/tag/ref a pulled version came from). Both
  omittable; absent on versions with no GitHub provenance.
- **NO** change to the exporter (`GitHubPushService`, `ExportJobService`,
  `ExportsController`, `exportJob` schema) — that PHP-scaffold path is orthogonal.
  **NO** network calls in this change — the serializer/parser operate on
  in-memory file maps; the actual GitHub I/O lands in changes 2 and 3. **NO** new
  OpenRegister schema — only additive optional fields on two existing schemas.

## Capabilities

### New Capabilities

- `github-app-repo-format`: the canonical GitHub repo layout for an Buildiq app
  (`openbuild-app.json` descriptor, `manifest.json`, `schemas/*.json`, optional
  `README.md` + `img/`), the `buildiq-app` discovery-topic contract, the
  `credentials[]` declaration, and the `AppRepoSerializer` / `AppRepoParser`
  service pair with strict, actionable, all-or-nothing import validation that
  targets the existing `installFromTemplateArray` clone seam.

### Modified Capabilities

- `buildiq-application-register`: the `Application` schema gains two optional
  additive linkage fields — `githubRepo` (`{ owner, name }`) and
  `githubDefaultBranch` — recording the app's GitHub home. No lifecycle or
  behaviour change; the fields are declarative and omittable.
- `application-versions`: the `ApplicationVersion` schema gains two optional
  additive provenance fields — `commitSha` and `sourceRef` — recording the exact
  commit / ref a version was pushed to or pulled from. Omittable; no lifecycle
  or auto-bump change.

## Impact

- **New PHP**: `lib/Service/AppRepoSerializer.php` (Application + version →
  ordered repo file map, deterministic file ordering for stable diffs) and
  `lib/Service/AppRepoParser.php` (repo file map → in-memory template array +
  strict validation with per-file error codes). Pure transformation services —
  no `IClientService`, no OR writes; unit-testable in isolation on in-memory maps.
- **Schema**: four optional additive fields across two existing schemas in
  `lib/Settings/openbuild_register.json` (`githubRepo`, `githubDefaultBranch` on
  `application`; `commitSha`, `sourceRef` on `applicationVersion`), re-imported
  via the existing repair step (`ConfigurationService::importFromApp()`), gated
  by an `info.xml` + schema-version bump per the OR version-gate memory rule. No
  migration (additive, omittable) and no new seed data (no new objects).
- **Reuse**: the parser output is the exact `ApplicationTemplate`-shaped array
  that `ApplicationsController::installFromTemplateArray` already accepts (the
  remote-store seam) — this change adds the *format*, not a new clone path.
- **Dependencies**: none new. OpenRegister is already a hard dep for the schema
  re-import; no network, no credential broker, no GitHub API in this change.
- **Downstream**: `github-shop-catalogue` (change 2) consumes `AppRepoParser`
  for install; `github-app-sync` (change 3) consumes both `AppRepoSerializer`
  (push) and `AppRepoParser` (pull) and reads/writes the four linkage fields.
