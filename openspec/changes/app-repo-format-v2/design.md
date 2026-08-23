# Design: app-repo-format-v2

## Architecture Overview

v1 emits four kinds of file. v2 emits eight, and the four new ones are what turn a manifest into a working app.

```
v1 (today)                          v2 (this change)
──────────                          ────────────────
buildiq-app.json                  buildiq-app.json      formatVersion 2.0 + channel counts
manifest.json                       manifest.json
schemas/<slug>.json                 schemas/<slug>.json     per-app register (unchanged)
README.md                           data-registers/<slug>.json   NEW  shared registers the app binds
                                    connectors/<kind>/<slug>.json NEW  declared ingestion
                                    automations/<slug>.json      NEW  the app's automations
                                    skills/<name>/…              NEW  hermiq bundle layout
                                    README.md
```

**Why this is the difference between an artefact and an app.** spectr's manifest binds every page to `spectr-live` — 109 references — and has no meaningful `buildiq-spectr` companion schemas. Under v1 it serialises to a manifest plus **zero** `schemas/` entries and reports success. v2 carries the register definitions the pages read *and* the connectors that populate them.

**Collectors are total.** Each new collector mirrors `collectCompanionSchemas()`: a missing or unreadable source yields no entries and a `debug` log, never an exception. Serialisation must not become the thing that blocks a publish. The counter-measure against that turning back into a silent empty export is the descriptor: every channel's entry count is recorded, so "collected nothing" is visible in the artefact itself.

**One layout, two apps.** `skills/<name>/SKILL.md` + auxiliaries is byte-for-byte the layout hermiq's `SkillBundleSerializer` produces. buildiq does not invent a second skill shape.

## API Design

No new endpoints. `GitHubAppSyncService::push()` already commits whatever `path => contents` map the serializer returns, so widening the map needs no route change.

The **descriptor** grows a provenance block:

```json
{
  "formatVersion": "2.0",
  "slug": "spectr",
  "channels": {
    "schemas": 0,
    "dataRegisters": 1,
    "connectors": { "declared": 20, "resolved": 7 },
    "automations": 0,
    "skills": 0
  }
}
```

`connectors.declared` vs `connectors.resolved` keeps "explicit" honest: a reviewer can see exactly how many objects were pulled in as dependencies rather than named.

## Database Changes

None to tables. One **register fragment**, `lib/Settings/register.d/21-connectors.json`, adds the optional `Application.connectors[]` property in the same ADR-037 style `20-data-registers.json` added `dataRegisters`. `SettingsService::deepMergeConfig` merges only the new key; `Application.required` and every other property are untouched.

## Nextcloud Integration

- **Controllers:** none changed.
- **Services:** `AppRepoSerializer` (four collectors), `AppRepoParser` (v2 read path).
- **Mappers:** `RegisterMapper` / `SchemaMapper` (already injected) for data-registers; `ObjectService` for connectors and automations — OpenConnector is read as OpenRegister objects, so there is **no cross-app PHP dependency** (ADR-022).
- **Events/Hooks:** none.

## Security Considerations

**1. Credential export is the dominant risk.** OpenConnector `source` and `job` configs are exactly where API keys, bearer tokens and connection strings live — spectr's sources reach `intelligence-db:5433`. The rule is: emit credential **references** only (`configuration.authentication.credentialRef` and equivalents), and strip any value whose key or shape looks secret-bearing (`password`, `secret`, `token`, `apiKey`, `authorization`, `connectionString`, inline `://user:pass@`). A strip is **recorded in the descriptor**, because a config that silently lost a field is harder to debug than one that says it did. Repos start private, but that is a recovery margin, not the control.

**2. Path safety on every new channel.** `<slug>` and `<kind>` become path components. Both are validated against the existing slug pattern *before* concatenation, and `kind` is constrained to the four-value enum. A crafted slug cannot escape its channel prefix — the same rule `schemas/` already relies on.

**3. Bounded collection.** Dependency resolution is **one level only** — a declared synchronization pulls its source, mapping and target, and stops. No transitive graph walk. Per-channel entry caps prevent one app declaring the whole instance.

**4. Parse-side validation mirrors emit-side.** `AppRepoParser` re-validates every path and slug it reads; it never trusts that the repository was written by a well-behaved serializer.

## File Structure

```
lib/
  Service/
    AppRepoSerializer.php   (modified — 4 collectors, FORMAT_VERSION 2.0)
    AppRepoParser.php       (modified — v2 read path, v1 preserved)
  Settings/register.d/
    21-connectors.json      (new — Application.connectors[])
tests/
  Unit/Service/AppRepoSerializerTest.php  (modified)
  Unit/Service/AppRepoParserTest.php      (modified)
  Integration/ExporterEndToEndTest.php    (modified)
```

## Declarative-vs-imperative decision

Assessed under ADR-031.

| Behaviour | Path | Rationale |
|---|---|---|
| `Application.connectors[]` binding | **Declarative** | A register.d schema fragment. No service class, no route — exactly how `dataRegisters` was added. |
| Channel collection + serialisation | Imperative | Wire-format translation to an external system (a git repository). ADR-031's external-integration exception; none of the six declarative categories applies. |
| Secret stripping | Imperative | Input/output sanitisation at a trust boundary. |

The one genuinely new *data* concept in this change is declarative, which is the part ADR-031 cares about.

## Seed Data

No new schema is introduced — `connectors[]` is a property on the existing `Application` schema, so ADR-016 is satisfied by the existing application seed set. The channels are exercised against real objects already present on the instance: register `openconnector` (369 sources, 206 synchronizations) and the `spectr-live` binding applied as part of this work. Inventing fixture connectors would test the fixtures, not the collectors.

## Rollout / Rollback

Additive. v1 repositories parse exactly as before. Rollback removes the v2 read path, at which point a v2 repository is refused on its `formatVersion` — the intended refusal rather than a partial parse.
