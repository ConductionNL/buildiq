## 1. Schema: linkage & provenance fields

- [ ] 1.1 Add optional `githubRepo` (`{ owner: string, name: string }`, `additionalProperties: false`) and `githubDefaultBranch` (string) properties to the `Application` schema in `lib/Settings/openbuild_register.json` (siblings to `slug`/`name`, both omittable). (REQ-OBA-010)
- [ ] 1.2 Add optional `commitSha` (string) and `sourceRef` (string) properties to the `ApplicationVersion` schema in `lib/Settings/openbuild_register.json` (both omittable). (REQ-OBV-112)
- [ ] 1.3 Bump the `version` of the `Application` and `ApplicationVersion` schemas in the register JSON, and bump the app `info.xml` version, so the OpenRegister version-gate re-imports the changed schemas (memory rule: schema-change deploy is gated by app version).
- [ ] 1.4 Confirm the repair step re-imports via `ConfigurationService::importFromApp()` (idempotent, union-merge preserving existing properties — no regression). No data migration is required (fields are optional/omittable) and no new seed data is added (no new objects); verify existing seeded `hello-world` Application + version stay schema-valid after re-import.

## 2. AppRepoSerializer (local → repo files)

- [ ] 2.1 Create `lib/Service/AppRepoSerializer.php` (SPDX + copyright in the file docblock). Pure service — inject only what is needed to read an Application + ApplicationVersion + companion schemas (no `IClientService`, no OR writes). (REQ-GARF-006)
- [ ] 2.2 Implement `serialize(Application, ApplicationVersion): array` returning a `path => contents` file map: `openbuild-app.json` (descriptor), `manifest.json` (the version's `manifest` blob verbatim), and one `schemas/<slug>.json` per companion schema of the app's per-app register. (REQ-GARF-001, REQ-GARF-004, REQ-GARF-005)
- [ ] 2.3 Build the descriptor per REQ-GARF-002: `formatVersion` (`"1.0"`), `slug`, `name`, `description`, `category`, `appType`, `version` (= the version's `semver`), optional `icon`/`iconDark` refs, optional `baseRef` for hybrid apps. (REQ-GARF-002)
- [ ] 2.4 Derive the descriptor `credentials[]` (`{ provider, reason, scopes[] }`) from the manifest's top-level `credentials[]` when present. (REQ-GARF-009)
- [ ] 2.5 Canonicalise every emitted JSON file (recursively sorted keys, stable indentation, trailing newline) and emit files in a deterministic order (descriptor, manifest, `schemas/*` sorted by slug, optional README) so re-serialising an unchanged app is byte-stable. (REQ-GARF-006)
- [ ] 2.6 Emit an optional `README.md` (app name + description + "built with Buildiq" provenance line) when the app has a description.

## 3. AppRepoParser (repo files → clone-seam payload)

- [ ] 3.1 Create `lib/Service/AppRepoParser.php` (SPDX + copyright in the file docblock). Pure service — `parse(array $files): array` is a pure function of the in-memory `path => contents` map; no network I/O, no OR writes. (REQ-GARF-007)
- [ ] 3.2 Read + JSON-decode `openbuild-app.json`; enforce size/depth bounds before decode (hostile-input-safe, design.md Decision 8); map descriptor fields onto the `ApplicationTemplate`-shaped payload (`slug`, `title` = name, `description`, `useCase` = descriptor.useCase ?? description, `category`, `version`). (REQ-GARF-002, REQ-GARF-007)
- [ ] 3.3 Read + JSON-decode `manifest.json`; validate it with the same manifest validation the local clone path applies (`validateManifest`); place the validated blob on the payload's `manifest`. (REQ-GARF-004)
- [ ] 3.4 Collect `schemas/*.json` into `companionSchemas[]`, validating each is a JSON-schema object and its base filename is a valid kebab-case slug; reject duplicate slugs. (REQ-GARF-005, REQ-GARF-008)
- [ ] 3.5 Set `templateOrigin = { source: "github", repo: <owner/name when known>, version: <descriptor.version> }` on the payload. (REQ-GARF-007)
- [ ] 3.6 Treat the manifest's `credentials[]` as authoritative; do not fail the parse on a descriptor/manifest `credentials` mismatch (manifest wins, optional warning). (REQ-GARF-009)

## 4. Strict validation (all-or-nothing, actionable)

- [ ] 4.1 Implement the failure taxonomy from design.md Decision 4 as stable error codes: `descriptor_missing`, `descriptor_unparseable`, `format_version_unsupported`, `app_type_unknown`, `manifest_missing`, `manifest_unparseable`, `manifest_invalid`, `schema_unparseable`, `schema_invalid`, `schema_slug_duplicate`. (REQ-GARF-008)
- [ ] 4.2 On any violation, fail the ENTIRE parse (produce no payload) and return a structured error carrying the code + offending file path(s); never best-effort-skip a malformed file (forbid the `manifest-validation-discards-backend-delta` failure mode). (REQ-GARF-008)
- [ ] 4.3 Gate the `formatVersion` major: accept major `1`, reject unknown majors with `format_version_unsupported`; ignore unknown minor/descriptor keys (forward-compatible minors, design.md OQ-1). (REQ-GARF-008)
- [ ] 4.4 Ensure returned error codes + file paths contain no secret and no PII (ADR-005-safe to surface to the caller so the user can locate the bad file).

## 5. Wiring & documentation of the discovery contract

- [ ] 5.1 Register `AppRepoSerializer` + `AppRepoParser` in the app's DI container (`lib/AppInfo/Application.php`) as plain services (no routes, no controller — consumed by `github-shop-catalogue` and `github-app-sync`).
- [ ] 5.2 Document the canonical repo layout + the `buildiq-app` discovery topic in the Buildiq docs (e.g. `docs/` GitHub-format page): the file tree, the descriptor contract, and the "topic `buildiq-app` + root `openbuild-app.json`" discovery rule. (REQ-GARF-003)

## 6. Tests

- [ ] 6.1 PHPUnit `AppRepoSerializer`: serialising an Application with two companion schemas yields `openbuild-app.json` + `manifest.json` + two `schemas/<slug>.json`; the manifest round-trips verbatim; re-serialising an unchanged app is byte-identical; the descriptor `credentials[]` is derived from a manifest credential. (REQ-GARF-004, REQ-GARF-006, REQ-GARF-009)
- [ ] 6.2 PHPUnit `AppRepoParser` happy path: a conforming fixture repo map parses to an `installFromTemplateArray`-shaped array with `slug`/`title`/`description`/`category`/`version`/`manifest`/`companionSchemas`/`templateOrigin`. (REQ-GARF-007)
- [ ] 6.3 PHPUnit `AppRepoParser` strict-validation matrix: each failure code fires on its trigger (missing/unparseable descriptor, unknown appType, unsupported formatVersion, missing/unparseable/invalid manifest, unparseable/invalid schema, duplicate schema slug) and the whole parse fails with nothing imported. (REQ-GARF-008)
- [ ] 6.4 PHPUnit hostile-input: an oversized/deeply-nested descriptor or manifest is rejected before decode; a `schemas/` entry with a path-traversal filename is rejected with `schema_invalid`. (design.md Decision 8)
- [ ] 6.5 PHPUnit schema round-trip: an Application saved with `githubRepo`/`githubDefaultBranch` and an ApplicationVersion saved with `commitSha`/`sourceRef` round-trip via OR; a provenance-only save does not bump `semver`; legacy objects without the fields stay valid. (REQ-OBA-010, REQ-OBV-112)

## 7. Gates

- [ ] 7.1 Run the hydra mechanical gates green for the changed PHP: spdx-headers (SPDX in each new service's docblock), forbidden-patterns (no `var_dump`/`error_log`/etc.), stub-scan (no stubbed bodies), spec-coverage (`@spec` tags on the new serializer/parser public methods), notification-dialect (no legacy dialect introduced), composer-audit. No new routes/controllers, so route-auth / no-admin-idor / route-reachability are N/A for this change.
