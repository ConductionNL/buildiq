---
kind: code
---

# Proposal: app-repo-format-v2

## Summary

`AppRepoSerializer::serialize()` emits exactly four kinds of file: `openbuild-app.json`, `manifest.json`, `schemas/<slug>.json` (companion schemas of the app's *own* `buildiq-{slug}` register) and `README.md`. Everything else that makes a virtual app work — the shared data registers it binds to, the OpenConnector configs that feed them, its automations, and its skills — is left behind. This change extends the format to v2 so a published app repository carries the app's whole configuration, and the parser reads it back.

## Motivation

A published Buildiq app today is a manifest plus whatever schemas happen to live in its own per-app register. For a real app that is not enough to run.

The sharpest case is **spectr**. Its manifest binds every page to the shared external register `spectr-live`; it has no `buildiq-spectr` companion schemas to speak of, and its `dataRegisters` binding is currently null. `collectCompanionSchemas()` is deliberately *total* — a missing register logs at `debug` and returns `[]`. So serialising spectr today produces a `manifest.json` full of pages pointing at schema slugs the recipient never receives, plus **zero** `schemas/` entries, and reports success. That is a green-but-empty publish: the artefact installs and the app does not work.

The same gap applies to every ingestion-backed app. OpenConnector configs are what populate the registers the pages read; without them an installed app renders empty tables forever.

This is the format `buildiq-spectr` and `buildiq-hydra` are meant to ship in, so the format has to carry enough to reconstitute a working app.

## Affected Projects

- [ ] Project: `buildiq` — `AppRepoSerializer` gains four channels, `AppRepoParser` learns to read them, `formatVersion` moves to 2.0 with 1.0 still accepted.

## Scope

### In Scope

- **`data-registers/<slug>.json`** — for each `Application.dataRegisters[].register` binding, the shared register's definition and its schemas. Schema definitions only; no objects.
- **`connectors/<kind>/<slug>.json`** — the OpenConnector configuration objects (`source`, `mapping`, `synchronization`, `job`) the app **explicitly declares**, via a new `Application.connectors[]` binding. Read as OpenRegister objects from the `openconnector` register — no cross-app PHP dependency (ADR-022: apps consume OR abstractions).
- **`Application.connectors[]`** — a new declarative binding (`{kind, slug}`), added as a register.d fragment in the same ADR-037 style `dataRegisters` was, so no base-schema surgery is needed.
- **`automations/<slug>.json`** — the app's `Automation` objects, selected by their existing `applicationSlug` field.
- **`skills/<name>/…`** — the app's skills, in the layout `skill-bundle-publish` already defines, so hermiq and buildiq agree on one shape.
- `formatVersion` 2.0; `AppRepoParser` accepts **both** 1.0 (four kinds, exactly as today) and 2.0.
- Secret hygiene: connector configs are emitted with credential *references* only; any inline secret-shaped value is stripped and the strip is recorded.

### Out of Scope

- **Objects / seed data.** Schema and configuration only — the locked decision was full config fidelity, no data. spectr's register is 82k+ tenders against an external Postgres and is not shippable in a repo; it is rehydrated by the connectors this change now carries.
- **Installing the configs.** This change makes the repo *carry* them and the parser *read* them. Provisioning them into a target instance on install is the follow-on (`application-skills-binding` and the wiring steps).
- **Changing v1 behaviour.** A v1 repo parses exactly as it does today.
- **Flows.** Hydra's `flows/*.json` are OpenConnector-owned artefacts that arrive through the `connectors/` channel if they target a bound register; a separate `flows/` channel would be a second name for the same thing.
- **Credential values.** Never exported, in any channel.

## Approach

Keep `serialize()`'s contract — a deterministic, canonicalised `path => contents` map — and add four collectors behind it. Each collector is total in the same way `collectCompanionSchemas()` already is: a missing or unreadable source yields no entries rather than an error, so serialisation never becomes the thing that blocks a publish.

The association rules are deliberately derived from data that already exists, so no schema changes are needed:

| Channel | Selected by |
|---|---|
| `data-registers/` | `Application.dataRegisters[].register` |
| `connectors/` | `Application.connectors[]` — an **explicit** `{kind, slug}` declaration |
| `automations/` | `Automation.applicationSlug` |
| `skills/` | the app's skill set (bound in the follow-on change) |

Connectors are declared explicitly rather than inferred from register targets. Inference looked cheaper — it needed no schema change — but it would have made an app's published surface depend on which *other* objects happened to point at a shared register, so the same app could export differently on two instances. An explicit list is reviewable, diffable, and stable.

Declared entries are exported as declared. The serializer additionally resolves the objects a declared entry *directly references* (a synchronization's source, mapping and target), because a synchronization without its source installs into something that cannot run — and records in the descriptor which entries were declared and which were pulled in as dependencies, so "explicit" stays honest rather than becoming "explicit plus surprises".

`AppRepoParser` gains a `formatVersion` switch: 1.0 keeps its current strict path; 2.0 additionally collects the new directories, applying the same slug and path validation the existing `schemas/` prefix already gets.

## New Dependencies

None. OpenConnector data is read through OpenRegister's `ObjectService`, which buildiq already depends on.

## Impact

- `lib/Service/AppRepoSerializer.php` — four collectors, `FORMAT_VERSION` 1.0 → 2.0.
- `lib/Service/AppRepoParser.php` — v2 parsing, v1 preserved.
- `lib/Service/GitHubAppSyncService.php` — unchanged seam; it already commits whatever map the serializer returns.
- Tests: `AppRepoSerializerTest`, `AppRepoParserTest`, `tests/Integration/ExporterEndToEndTest.php`.

## Cross-Project Dependencies

- **Depends on** hermiq `skill-bundle-publish` for the `skills/<name>/` layout (already landed).
- **Consumed by** the `buildiq-spectr` and `buildiq-hydra` publications.

## Risks

### Risk 1: Exporting a credential

**Severity:** High — **Mitigation:** OpenConnector source/job configs are exactly where API keys and connection strings live. Emit credential **references** only (`configuration.authentication.credentialRef` and equivalents), strip any inline secret-shaped value, and record that a strip occurred so a silently-emptied config is visible rather than mysterious. A publish that leaks `intelligence-db` credentials into a repository would be the worst possible outcome of this change, and repos start private precisely so a mistake here is recoverable.

### Risk 2: A v1 consumer meets a v2 repo

**Severity:** Medium — **Mitigation:** `formatVersion` is checked before anything else and an unsupported *major* is refused outright rather than best-effort parsed — the existing `AppRepoParser` already refuses an unknown `formatVersion`, so an older Buildiq declines a v2 repo cleanly instead of installing a partial app.

### Risk 3: Over-collection via dependency resolution

**Severity:** Medium — **Mitigation:** Explicit declaration removes the main over-collection risk, but dependency resolution reintroduces a bounded version of it. Resolve **one level** from a declared entry — never transitively chase a whole graph — bound the per-channel entry count, and record resolved-vs-declared in the descriptor so an unexpectedly large export is visible in review rather than discovered on install.

### Risk 4: An app's bindings are empty, so the export is silently thin

**Severity:** Medium — **Mitigation:** Already observed on spectr: `dataRegisters` is null and `connectors` will not exist until declared, so both channels would collect nothing and the publish would still report success — the exact green-but-empty shape this change exists to end. The serializer records per-channel counts in the descriptor, and an app whose manifest binds pages to a register it does not declare is a reportable condition rather than a silent empty export.

## Rollback Strategy

Revert the commit. Additive: v1 repos are unaffected, no schema change, no migration. A v2 repo published while it was live remains a valid git repository and reverts to being parsed as… nothing, because its `formatVersion` would then be unsupported — which is the intended refusal rather than a partial parse.

## Open Questions

None. The app↔connector association was raised as an assumption and resolved in favour of an **explicit** `Application.connectors[]` binding rather than inference from register targets.
