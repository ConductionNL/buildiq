## Context

OpenBuild stores a virtual app as two OpenRegister objects (ADR-002): an
`Application` (identity: `slug`, `name`, `appType`, `permissions`,
`productionVersion`) and one or more `ApplicationVersion` rows (each carrying a
full `manifest` blob + `semver` + per-version `register`). Companion schemas —
the app's data model — live in the per-app OR register. The app-manifest v2.14.0
schema (`@conduction/nextcloud-vue`) supports a top-level `credentials[]` field
(provider / reason / scopes) declaring which broker credentials the app needs.

Two GitHub-adjacent things already exist and are **deliberately not reused** as
the round-trip format:

- The **exporter** (`GitHubPushService`, `ExportJobService`, `exportJob` schema)
  scaffolds a full standalone Nextcloud app (PHP, `appinfo/`, webpack) and pushes
  it via the Git Data API. That output is an *installable app*, not a definition
  OpenBuild re-reads — it does not round-trip.
- The **remote template store** (`RemoteTemplateStoreService`, `StoreController`)
  reads `application-template` OpenRegister objects from a remote OR instance and
  installs one through `ApplicationsController::installFromTemplateArray` — the
  reusable clone seam that takes an in-memory `ApplicationTemplate`-shaped array
  and produces a namespaced local `Application` + per-app register.

This change defines a **third** GitHub artifact: a round-trippable *data* layout
in a GitHub repo, plus the serializer/parser that is its single implementation.
It is spec-heavy and I/O-free — the actual GitHub network legs land in the two
dependent changes. The parser's job is to feed the *same* `installFromTemplateArray`
seam the remote store already uses, so nothing about namespacing / manifest
rewriting is re-invented.

## Goals / Non-Goals

**Goals:**

- Define one canonical, versioned GitHub repo layout for an OpenBuild app as a
  **data** format (descriptor + manifest + companion schemas + optional docs).
- Define the discovery contract: GitHub topic `openbuild-app` + a parseable
  root `openbuild-app.json`.
- Add the minimal linkage/provenance fields that tie a local `Application` /
  `ApplicationVersion` to its GitHub home (repo, branch, commit, ref).
- Provide `AppRepoSerializer` (local → files) and `AppRepoParser` (files →
  in-memory template array) as pure, unit-testable transformations.
- Make import validation **strict and all-or-nothing**: one bad file fails the
  whole parse with an actionable, per-file error — never a silent partial import.

**Non-Goals:**

- Any GitHub network I/O (search, fetch, push) — deferred to changes 2 / 3.
- Any change to the exporter or its PHP-scaffold format.
- A new OpenRegister schema (only additive optional fields on two existing ones).
- Transporting object/row *data* (a repo carries a *definition* — manifest +
  companion-schema blobs — never seeded rows).
- Multi-version repos (a repo represents one app at one exported version;
  version history stays in Git commit history, not multiple in-repo manifests).

## Decisions

### Decision 1 — Round-trip DATA format, distinct from the exporter's PHP scaffold

The GitHub repo layout is a **data** definition, not an installable app:

```
<repo-root>/
  openbuild-app.json        # app descriptor (see Decision 2)
  manifest.json             # the ApplicationVersion.manifest blob, verbatim
  schemas/
    <schema-slug>.json      # one file per companion schema, filename = slug
    <schema-slug>.json
  README.md                 # optional, human-facing
  img/                      # optional: screenshots + icon SVGs
    icon.svg
    icon-dark.svg
```

Only OpenBuild parses this — it is never `occ app:enable`-able. This keeps the
format free of PHP/appinfo/build cruft, so a diff on GitHub reads as "the app's
data changed", and a citizen developer can hand-author one. **Alternative
considered:** reuse the exporter's scaffold as the round-trip format — rejected:
it bakes a build toolchain and PHP into every repo, the manifest is buried under
`src/`, and re-parsing an installable app back into a definition is lossy. The
exporter stays exactly as-is for its own "ship a standalone app" purpose.

**File-ordering:** `AppRepoSerializer` emits files in a deterministic order
(descriptor, manifest, then `schemas/*` sorted by slug, then README) and
canonicalises JSON (sorted keys, 2-space indent, trailing newline) so a re-push
with no semantic change produces a no-op diff. This matters for the push path in
change 3 (a blob-by-blob tree push) and for human-readable GitHub diffs.

### Decision 2 — `openbuild-app.json` descriptor shape

The descriptor is the discovery-and-metadata anchor. It carries only app-level
identity + the credential declaration; the heavy blobs live in `manifest.json`
and `schemas/*.json`:

```json
{
  "formatVersion": "1.0",
  "slug": "permit-tracker",
  "name": "Permit Tracker",
  "description": "Municipal building-permit workflow.",
  "category": "government-services",
  "appType": "virtual",
  "version": "1.2.0",
  "icon": { "ref": "img/icon.svg" },
  "iconDark": { "ref": "img/icon-dark.svg" },
  "credentials": [
    {
      "provider": "github",
      "reason": "Publish and pull this app to its GitHub repository.",
      "scopes": ["repo"]
    }
  ]
}
```

- `formatVersion` pins the layout so a future v2 can evolve without
  mis-parsing a v1 repo; the parser rejects an unknown major `formatVersion`
  with an actionable error rather than guessing.
- `slug` / `name` / `description` / `category` map straight onto the
  `ApplicationTemplate`-shaped array fields (`slug`, `title`, `description`,
  `category`) the clone seam expects. `useCase` (a template field) is derived
  from `description` when absent — the descriptor does not duplicate it.
- `appType` mirrors `Application.appType` (`virtual` | `hybrid`). For a hybrid
  app the descriptor additionally carries the `baseRef` block (the installed
  fleet-app id) and `manifest.json` is the *delta* — but the shop/sync cuts
  focus on virtual apps; hybrid round-trip is declared valid by the format and
  exercised by change 3 only for the fields that already exist.
- `version` records the exported `ApplicationVersion.semver` so a pull can
  stamp the new draft version's provenance.
- `credentials[]` mirrors the manifest's top-level `credentials[]` field
  (provider / reason / scopes) — surfaced so a shop browser sees "this app
  needs a github credential" before install, and so the descriptor is
  self-describing without parsing the whole manifest.

**Alternative considered:** fold the descriptor into `manifest.json` (a
`manifest.meta` block) — rejected: the manifest is the runtime contract owned by
`@conduction/nextcloud-vue`; overloading it with GitHub/repo metadata couples the
repo format to the manifest schema version and pollutes the runtime blob.

### Decision 3 — `AppRepoParser` targets the existing `installFromTemplateArray` seam

`AppRepoParser::parse(array $files): array` takes an in-memory map of
`path => contents` (bytes/string, exactly what a GitHub contents-API fetch
yields in change 2/3) and returns an `ApplicationTemplate`-shaped array:

```
[
  'slug'             => <descriptor.slug>,
  'title'            => <descriptor.name>,
  'description'      => <descriptor.description>,
  'useCase'          => <descriptor.useCase ?? descriptor.description>,
  'category'         => <descriptor.category>,
  'version'          => <descriptor.version>,
  'manifest'         => <parsed manifest.json>,
  'companionSchemas' => [ <parsed schemas/*.json>, ... ],
  'templateOrigin'   => [ 'source' => 'github', 'repo' => ..., 'version' => ... ],
]
```

This is precisely the shape `ApplicationsController::installFromTemplateArray(array $template, …)`
accepts, so change 2's install endpoint calls `AppRepoParser::parse()` then hands
the result to that existing method — companion-schema namespacing, manifest
rewrite, per-app register provisioning, and `templateOrigin` recording all reuse
the audited clone path. The parser performs **no** OR writes and **no** network
calls; it is a pure function of the file map. **Alternative considered:** a
bespoke GitHub install path — rejected: it would duplicate the namespacing/rewrite
logic the remote store already extracted, and diverge over time.

### Decision 4 — Strict, all-or-nothing, actionable import validation

The parser fails the **entire** parse (nothing partially imported) with a
structured, per-file error when any of these hold — making the
`manifest-validation-discards-backend-delta` memory rule an explicit contract:

| Failure | Error code | Actionable message |
|---|---|---|
| `openbuild-app.json` missing at root | `descriptor_missing` | "No openbuild-app.json at the repo root — not an OpenBuild app repo." |
| Descriptor not valid JSON | `descriptor_unparseable` | names the JSON parse position |
| Unknown/absent `formatVersion` major | `format_version_unsupported` | "openbuild-app.json formatVersion X is not supported by this OpenBuild." |
| `appType` not in `{virtual, hybrid}` | `app_type_unknown` | names the offending value |
| `manifest.json` missing | `manifest_missing` | "manifest.json is required." |
| `manifest.json` not valid JSON | `manifest_unparseable` | names the JSON parse position |
| `manifest.json` fails app-manifest schema | `manifest_invalid` | names the failing manifest property path (reuse the local clone's `validateManifest`) |
| a `schemas/*.json` not valid JSON | `schema_unparseable` | names the file + JSON parse position |
| a `schemas/*.json` not a JSON-schema object | `schema_invalid` | names the file + the failing constraint |
| duplicate schema slug across files | `schema_slug_duplicate` | names the colliding files |

The parser returns a single failure carrying the first (or an aggregated list of)
error(s); the caller surfaces it as a generic-but-actionable 4xx (per ADR-005 no
raw exception text, but the structured error *codes + file paths* are safe to
return — they contain no secret and no PII). This guarantees "one bad file fails
loudly", never a half-imported app. **Alternative considered:** best-effort
import that skips bad files — rejected explicitly: it reproduces the silent-delta
bug the memory rule warns against.

### Decision 5 — Linkage/provenance fields are additive & omittable (no migration)

Four optional fields, declared in `lib/Settings/openbuild_register.json`:

- `Application.githubRepo` — object `{ owner: string, name: string }`, the app's
  GitHub home. Set by change 3's link action. Omittable — an app never pushed to
  GitHub has none.
- `Application.githubDefaultBranch` — string, the branch push/pull targets
  (resolved from the repo's default branch at link time, overridable). Omittable.
- `ApplicationVersion.commitSha` — string, the exact commit a version was pushed
  to or pulled from. Omittable.
- `ApplicationVersion.sourceRef` — string, the branch/tag/ref a *pulled* version
  came from (e.g. `refs/heads/main` or a tag). Omittable.

All four are optional and additive, so no data migration is required and every
existing `Application` / `ApplicationVersion` stays schema-valid (contrast
REQ-OBA-007's permissions back-fill, which was needed because RBAC gated access;
these fields gate nothing). Re-import is gated by the OR version-gate memory
rule: bump `info.xml`, bump each touched schema's `version`, run the repair-step
`ConfigurationService::importFromApp()`, restart. **Alternative considered:** a
separate `GithubLink` OR schema keyed by application — rejected as over-built for
four scalar fields with a 1:1 relationship to the app.

### Decision 6 — Declarative-vs-imperative (ADR-031)

This change introduces **no** declarative behaviour matching the ADR-031 triggers
— no lifecycle/state machine, no aggregation/count, no derived/virtual field, no
notification, no declarative cross-object relation, no dashboard widget. The four
new fields are **plain declarative properties** (stored data, no state machine,
no calc) — declared directly in the schema register, no service.

The `AppRepoSerializer` / `AppRepoParser` pair is **imperative**, and correctly
so under ADR-031 §Exceptions: format serialization/parsing + strict validation is
a **data-transformation** concern that cannot be expressed as schema metadata
(it maps between a filesystem layout and OR object shapes, and enforces
cross-file invariants like slug-uniqueness). It is the format's *implementation
surface*, mirroring how `RemoteTemplateStoreService` is an imperative
external-integration service. No lifecycle, notification, or calc is added, so
there is nothing that should have been declarative but was written as code.

### Decision 7 — Seed Data (ADR-001)

This change adds **no new OpenRegister schema** and **no new objects**, so there
is **no new seed data**. The two modified schemas (`application`,
`applicationVersion`) gain only optional, omittable fields; existing seeded
objects (the `hello-world` Application + its version, seeded by the existing
repair steps) remain valid unchanged and need no back-fill (Decision 5). Seed
data for this change = **N/A**. The only "fixture" this change reasons about is
an *example repo file map* used in unit tests (a `permit-tracker` descriptor +
manifest + two companion schemas) — a test fixture, not OR seed data.

### Decision 8 — Security (ADR-005)

- **No network, no secrets in this change.** The serializer/parser operate on
  in-memory maps; there is no outbound request, no credential, no token. The
  broker/GitHub-auth surface lands in changes 2/3.
- **Structured error codes are safe to return.** The parser's per-file error
  codes + file paths carry no secret and no PII, so returning them to the caller
  (unlike a raw exception message) is ADR-005-safe and is the point — the user
  needs to know *which file* is malformed to fix it.
- **Parser is hostile-input-safe.** It treats the file map as untrusted: JSON is
  size-bounded and depth-bounded before parse (reject absurdly large/deeply
  nested blobs), schema files are validated as JSON-schema objects before use,
  and the manifest runs the same `validateManifest` guard the local clone applies
  before anything is handed to `installFromTemplateArray`. A hostile repo can at
  worst produce a rejected parse — never a partial write.
- **Slug/path safety.** Schema filenames are validated against the kebab-case
  slug pattern; a `schemas/…` entry with a path-traversal or non-slug filename is
  rejected (`schema_invalid`). The descriptor `slug` is validated against the
  Application slug pattern before it reaches the clone seam.

## Risks / Trade-offs

- **[Hand-authored repo drift]** A human editing `openbuild-app.json` by hand can
  desync `version` from `manifest` content → mitigated by strict validation
  (manifest still must pass the schema) and by change 3's pull always creating a
  *new draft* version (never trusting the descriptor `version` as authoritative
  for the local semver, which auto-bumps on content per REQ-OBV-103).
- **[Format evolution]** A future layout change → `formatVersion` gate: the
  parser rejects an unsupported major loudly instead of mis-parsing.
- **[Hybrid round-trip completeness]** The format declares `appType: hybrid` +
  `baseRef` valid, but the shop/sync cuts focus on virtual apps; a hybrid app's
  delta-manifest round-trip is representable but only lightly exercised until a
  follow-up. Documented as a known edge, not a silent gap.
- **[Large repos]** A repo with hundreds of companion schemas → the serializer's
  deterministic ordering + canonical JSON keep diffs stable, and the parser's
  size/depth bounds cap a hostile blob; genuinely large apps are an operational
  edge, not a correctness risk.
- **[Trade-off: single-version repos]** One manifest per repo keeps the format
  legible and diffable; multi-version-in-one-repo was rejected as unnecessary
  (Git history *is* the version history). A pull creates a new local draft
  version, so local version history is preserved independently.

## Migration Plan

1. Add the four optional fields to `application` / `applicationVersion` in
   `lib/Settings/openbuild_register.json`; bump each touched schema's `version`
   and the app `info.xml` version (OR version-gate memory rule).
2. Ship `AppRepoSerializer` + `AppRepoParser` (pure services, no wiring beyond
   DI registration). No routes, no controller in this change — they are consumed
   by changes 2/3.
3. Run the repair step (`ConfigurationService::importFromApp()`) + restart so OR
   picks up the additive fields. No data migration (fields omittable), no seed
   change.
4. **Rollback:** the fields are omittable and unused by any runtime path in this
   change, so reverting the schema patch is safe (no object carries the fields
   until change 3 writes them); reverting the code removes two unused services.
   Nothing else is affected.

## Open Questions

- **OQ-1 (formatVersion policy):** ships as `"1.0"`; the parser accepts major `1`
  and rejects others. Whether minor bumps are forward-compatible-by-default (parse
  a `1.1` repo on a `1.0` parser) is provisionally **yes** (ignore unknown
  descriptor keys, reject unknown *major*) — revisit if a breaking minor is ever
  needed.
- **OQ-2 (credentials[] source of truth):** the descriptor's `credentials[]` is a
  *surfaced copy* of the manifest's top-level `credentials[]`. On serialize it is
  derived from the manifest; on parse the manifest remains authoritative. If they
  disagree in a hand-authored repo, the manifest wins and the parser MAY warn.
  Provisionally: manifest-authoritative, descriptor is a convenience mirror.
- **OQ-3 (hybrid delta round-trip depth):** the format represents `appType:
  hybrid` + `baseRef` + a delta `manifest.json`, but full hybrid pull/merge
  fidelity is deferred to a follow-up; this cut guarantees virtual-app fidelity
  and hybrid *representability*.
