## Context

Per ADR-002, an OpenBuild `Application` (the logical app) already has two
reference-shaped properties: `baseRef` (object `{ kind, id, manifestVersion? }`,
pointing at an installed fleet app for hybrid apps) and, via its production
version, `ApplicationVersion.register` (a plain string slug, pattern
`^openbuild-[a-z0-9][a-z0-9-]*[a-z0-9]$`, naming the per-version register the
app owns and that promotion copies/migrates). Neither shape fits a **shared
data register**: `baseRef` is single-valued and app-authored-vs-fleet-app
specific; `ApplicationVersion.register` is owned-and-versioned, exactly the
opposite of what SPECTR-NEXTCLOUD-PLAN.md §4.2 asks for.

`spectr` (Conduction's market-intelligence app, ADR-050) needs its
`Application` to bind to a ~30-schema shared register that OpenConnector
feeds continuously and that every version of the app — dev, staging,
production — reads from unchanged. Copying 82k+/158k+ rows per version
promotion is both wasteful and semantically wrong: the data is canonical and
external to any one app version, not app-owned test/prod data.

This spec (chain head, `kind: config`) declares the schema surface that makes
such a binding expressible. It ships zero PHP/Vue/route code — the consumers
(pickers, promotion-skip guarantee, export inclusion, designer UI) are the
follower spec `data-registers-runtime` (`kind: code`), per ADR-032.

## Goals / Non-Goals

**Goals:**
- Add an optional `dataRegisters` array property to the `Application` schema
  that lets an admin declare 0..N shared OR registers the app binds to.
- Keep the shape minimal: a register reference plus an optional display
  label — nothing a picker or export step can't consume directly.
- Keep the property purely additive and backward compatible: every existing
  `Application` object (including the seeded `hello-world` app) remains
  schema-valid with `dataRegisters` absent.
- Ship via a `register.d/` fragment (ADR-037) so this change never touches
  the `openbuild_register.json` monolith and never collides with any other
  concurrent OpenBuild change.

**Non-Goals:**
- No builder UI to add/remove a data-register binding (follower spec).
- No change to `useRegisterPicker.js` or any page/schema editor (follower
  spec).
- No promotion-time code change — see "Declarative-vs-imperative decision"
  below for why the invariant already holds without one, and why the
  regression test that locks it in belongs to the follower.
- No export-bundle change to `ExportService.php` (follower spec).
- No new RBAC mechanism — see "RBAC" below.
- No validation that a referenced register slug actually exists in
  OpenRegister at save time. OR does not require a register to pre-exist for
  another app's schema to reference its slug (multiple registers are queried
  by slug string, not by hard FK); a dangling reference simply resolves to an
  empty picker/export result at consume-time, which is exactly the same
  failure mode `ApplicationVersion.register` already has if its register is
  deleted out from under it. No new integrity mechanism is invented here.

## Decisions

### Decision 1: Property shape — array of `{ register, label? }` objects

```jsonc
"dataRegisters": {
  "title": "Data Registers",
  "type": "array",
  "default": [],
  "items": {
    "type": "object",
    "title": "Data Register Binding",
    "required": ["register"],
    "additionalProperties": false,
    "properties": {
      "register": {
        "title": "Register Slug",
        "type": "string",
        "pattern": "^[a-z0-9][a-z0-9-]*[a-z0-9]$",
        "minLength": 2,
        "maxLength": 64,
        "description": "Slug of the shared OpenRegister register this app binds to (e.g. `spectr`). Unlike ApplicationVersion.register, this register is NOT owned or provisioned by OpenBuild and carries NO `openbuild-` prefix convention — it is an existing register OpenConnector or another process feeds independently."
      },
      "label": {
        "title": "Display Label",
        "type": "string",
        "maxLength": 128,
        "description": "Optional human-readable label shown in the builder's data-source pickers instead of the raw register slug (e.g. `Spectr market intelligence data`). Purely a UI convenience; absent falls back to the raw slug."
      }
    }
  },
  "description": "Shared, non-versioned OpenRegister registers this Application binds to alongside its own per-version register (ADR-002 `ApplicationVersion.register`). Declared per SPECTR-NEXTCLOUD-PLAN.md §4.2 / hydra ADR-050 decision #2. Version promotion (`VersionPromotionService`) never reads or writes this property — it operates exclusively on `ApplicationVersion.register` — so promoting a version neither copies nor migrates any row in a data register. Sibling to `baseRef` on the `Application` schema; absent on every Application created before this property existed."
}
```

**Why an array of objects, not an array of plain slug strings**: a plain
`string[]` would satisfy the "which registers" question but not the picker
UX question — SPECTR-NEXTCLOUD-PLAN.md §4.2 explicitly frames this as a
builder-picker feature, and `useRegisterPicker.js` today already resolves a
register's own metadata (name, schemas) by round-tripping to OR, but has no
way to show a friendlier label than the raw slug for a register the app
didn't create itself. An optional `label` costs one property and removes a
follower-spec round-trip.

**Why not reuse the `baseRef` shape (`{ kind, id, manifestVersion? }`)**:
`baseRef` is deliberately polymorphic (`kind: "fleet-app"` today, room for
other kinds later) because it names *what the app extends*. A data register
binding has exactly one kind — "an OpenRegister register" — so the `kind`
discriminator would be dead weight (a union-shaped field with one branch is
worse than no union, and the task's own hard rule 3 rules out union types
here regardless). `manifestVersion` (drift-detection for a fleet-app's
bundled manifest) has no analogue for a data register — there is no
"manifest" to drift.

**Why not reuse `ApplicationVersion.register`'s plain-string shape
verbatim (no label, `openbuild-` prefix pattern)**: the prefix pattern
encodes ownership ("OpenBuild provisioned and names this register"), which is
precisely untrue for a shared data register (`spectr`, or a municipality's
`brp-personen`) — reusing the pattern would make every real-world consumer's
first binding a validation failure.

**Why `additionalProperties: false` on the item**: mirrors `baseRef`'s own
posture in the same schema (`"additionalProperties": false`) — keeps the
binding shape closed so a future property (e.g. a `readOnly` flag) is an
explicit, reviewable schema change rather than silent passthrough.

**Alternatives considered:**
- *Single `dataRegister` (singular, not array)* — rejected: SPECTR-NEXTCLOUD-PLAN.md
  §4.2 and ADR-050 both write `dataRegisters[]` explicitly, and `pipelinq`/`mydash`
  (the named next consumers) are plausible multi-register cases (e.g. a
  municipality app binding both a persons register and an addresses
  register — see Seed Data below).
- *Relation type (`x-openregister-relation`, like `productionVersion`)* —
  rejected: OR relations resolve to another *object* (a row with a uuid) in a
  known register/schema pair. A data-register binding refers to an entire
  *register* (a container), not a row — there is no target object to point
  a relation at. A plain string slug is the correct primitive, exactly as
  `ApplicationVersion.register` already treats "which register" as a string,
  not a relation.

### Decision 2: Ship as a `register.d/` fragment, not a monolith edit

Per ADR-037, this spec adds `lib/Settings/register.d/20-data-registers.json`
(the next free ascending prefix after the existing `10-business-rules.json`)
rather than editing `lib/Settings/openbuild_register.json` directly. The
fragment's `components.schemas.Application.properties` object unions by key
with the monolith's existing `Application.properties` — `SettingsService`'s
`deepMergeConfig` recurses into shared keys (`Application`, then
`properties`) and only adds the new `dataRegisters` key, leaving every
existing property (and the `required` array, which this fragment does not
touch) untouched. This keeps the change concurrency-safe against any other
in-flight OpenBuild change per ADR-037's stated purpose.

### Declarative-vs-imperative decision (ADR-031)

- **The schema property itself is declarative** — `dataRegisters` is pure
  schema metadata added to `lib/Settings/register.d/20-data-registers.json`.
  No service class is introduced; this is the default case ADR-031 asks for,
  identical in kind to how `baseRef`/`icon`/`iconDark`/`permissions` were
  previously added to `Application` as schema-only patches (see
  `openbuild-application-register` REQ-OBA-002/REQ-OBA-006).
- **The follower's picker/export/promotion-guard work is imperative, and
  that is correct, not an ADR-031 gap**:
  - *Pickers* (`useRegisterPicker.js` + its Vue consumers) render UI —
    exactly the same class ADR-031 already carves out in this repo's own
    precedent ("the diff and version-history UI are unavoidably code",
    `openbuild-versioning` proposal.md). There is no `x-openregister-*`
    extension for "render a dropdown"; this was never a declarative
    candidate.
  - *Export inclusion* (`ExportService.php` bundling data-register schema
    defs into the ZIP) matches ADR-031's explicit "What apps SHOULD still
    write in PHP" bullet: "Document/PDF/document-template generation ... The
    schema engine has no opinion on rendered output." A ZIP bundle is
    rendered output.
  - *Promotion-skip* needs no new code at all — see Decision 1's schema
    description: `VersionPromotionService` (already an ADR-031 §Exceptions
    file per its own docblock: "every branch in this file is classified
    imperative") only ever reads/writes `ApplicationVersion.register`. It
    has no code path that touches `Application.dataRegisters`, so the
    "promotion never copies data-register rows" guarantee holds the moment
    this schema patch merges — with zero lines changed in
    `VersionPromotionService.php`. The follower spec's job is to add the
    integration test that pins this down as a regression guard, not to
    write new promotion logic.
- No exception justification is needed for *this* spec, because this spec
  contains no imperative code at all — the exception note above exists so
  the follower spec's reviewer sees the reasoning already applied.

### RBAC — no new mechanism

`Application`, `ApplicationVersion`, and (per the `register.d/` precedent)
`RuleSet` all carry their own OR-native `authorization` block
(`create`/`update`/`delete` arrays of roles) directly on the schema that owns
the data. `dataRegisters` is a **reference-only** property — it names a
register slug and an optional label; it carries no read/write semantics of
its own and grants nothing. Access to whatever schemas and objects actually
live inside the referenced register continues to be governed exclusively by
that register's own schemas' `authorization` blocks and OR's standard
multi-tenant `organisation` scoping (ADR-022) — exactly as it is today for
every register in the fleet. Publishing an `Application` that declares
`dataRegisters: [{ register: "spectr" }]` does not itself grant the
Application's viewers, editors, or the general public any access to
`spectr`'s objects that they didn't already have; per SPECTR-NEXTCLOUD-PLAN.md
§4.2 ("RBAC stays schema-level"), this is by design, not an oversight to be
closed later.

## Seed Data

Realistic example objects an admin (or a seed migration in a later spec)
would create once this property exists. UUIDs are nil placeholders; no
object below is created by this change — it is a `kind: config` schema-only
spec and touches no running instance.

**The `spectr` case (first real consumer, per ADR-050):**

```jsonc
{
  "uuid": "00000000-0000-0000-0000-000000000000",
  "slug": "spectr",
  "name": "Spectr",
  "description": "Market intelligence: tenders, competitors, standards, features.",
  "appType": "virtual",
  "productionVersion": "00000000-0000-0000-0000-000000000000",
  "dataRegisters": [
    {
      "register": "spectr",
      "label": "Spectr market intelligence data"
    }
  ]
}
```

**Generic municipality case (illustrates the multi-binding shape a
`pipelinq`/`mydash`-style consumer, or a citizen-developer municipal app,
would use — two shared national base registers bound alongside the app's
own register):**

```jsonc
{
  "uuid": "<application-uuid>",
  "slug": "vergunningen-<gemeente-slug>",
  "name": "Vergunningen <Gemeente>",
  "description": "Permit intake for <Gemeente>.",
  "appType": "virtual",
  "productionVersion": "<application-version-uuid>",
  "dataRegisters": [
    {
      "register": "brp-personen",
      "label": "BRP personen (shared municipal register)"
    },
    {
      "register": "bag-adressen",
      "label": "BAG adressen"
    }
  ]
}
```

Both examples validate against Decision 1's schema: `dataRegisters` is an
array of `{ register, label? }`; `register` matches the kebab-case pattern
in both cases; neither example's app-owned `ApplicationVersion.register`
(e.g. `openbuild-spectr-production`, not shown) is confused with a bound
data register — the two remain visibly distinct property surfaces.

## Risks / Trade-offs

- **[Risk]** A future spec could be tempted to add validation that a
  `dataRegisters[].register` slug must already exist in OpenRegister at
  save time, coupling `Application` saves to a live OR registry lookup.
  → **Mitigation**: explicitly out of scope (see Non-Goals); `ApplicationVersion.register`
  already sets the precedent of "just a string, resolved at consume time,
  not at save time" and this spec follows it.
- **[Risk]** Two OpenBuild apps could declare the same `dataRegisters[].register`
  slug and both expect exclusive write access. → **Mitigation**: not a new risk
  — this is already true of any two processes (OpenConnector syncs, other
  apps) that reference the same OR register today; RBAC is schema-level, not
  app-level, so concurrent readers of the same register are the expected
  shape (this is the entire point of "shared"), and OpenBuild introduces no
  writer of its own.
- **[Trade-off]** The `label` property has no enforced relationship to the
  register's actual OR-side display name — an admin could set a misleading
  label. → Accepted: this is display-only UI sugar for the follower's picker,
  same trust level as any other admin-entered free-text field on `Application`
  (e.g. `name`, `description`).

## Migration Plan

None required. The property is optional and additive; OpenRegister's
`ConfigurationService::importFromApp()` re-imports the merged register
idempotently (the fragment-hash-folded version bump documented in ADR-037
triggers the re-import). No existing `Application` object needs a backfill —
absence of `dataRegisters` is a fully valid, already-common state (every
Application created before this spec lands has no such property, identical
in effect to an Application explicitly saved with `dataRegisters: []`).

Rollback is likewise trivial: removing the fragment file reverts the schema
on the next import; no data migration accompanies this change in either
direction because no object has been seeded with the new property yet.

## Open Questions

- Should a later spec cap the number of `dataRegisters` entries per
  Application (e.g. to bound picker-list growth)? Not addressed here —
  no evidence of need yet; `spectr` binds exactly one.
- ~~Export data-toggle granularity~~ **DECIDED 2026-07-05 (Ruben): per-binding
  toggle.** Each `dataRegisters` binding gets an `includeData` choice in the
  export flow (default: schema-defs-only). `data-registers-runtime` implements
  it in the export dialog + `ExportService`; this head change deliberately adds
  no schema field for it — the toggle is export-flow state, not Application
  configuration.
