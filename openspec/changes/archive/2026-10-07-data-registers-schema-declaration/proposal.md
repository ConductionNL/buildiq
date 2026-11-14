---
kind: config
depends_on: []
chain:
  - data-registers-schema-declaration   # this spec
  - data-registers-runtime               # next in chain
---

## Why

`SPECTR-NEXTCLOUD-PLAN.md` §4.2 and hydra ADR-050 (decision #2) lock a new
Buildiq capability: an `Application` can bind to one or more **shared,
non-versioned OpenRegister data registers** alongside its own per-version
config register. The first concrete consumer is `spectr` (Conduction's
market-intelligence app) — its ~30-schema, 82k+/158k+-row canonical dataset
must be fed continuously by OpenConnector and read by every `Application`
version without being copied on each promotion. `pipelinq` and `mydash` are
named as the obvious next consumers once the capability exists.

Per ADR-002, `ApplicationVersion.register` is already the per-version,
app-owned register (`buildiq-{slug}-{versionSlug}`) — schemas and objects
that a promotion is expected to copy or migrate. A shared data register is
architecturally different: it is **not owned by the app**, it is **not
versioned**, and promotion **must never touch it**. Today there is no
property on `Application` (or anywhere in the Buildiq schema surface) that
lets an admin declare such a binding, so `spectr`'s data-register work is
blocked until the schema exists.

Per ADR-032 (spec sizing and chained-spec routing), this is a `kind: config`
head: it declares the schema surface only. The code that *consumes* the new
property — builder dataSources pickers on both hosts, the promotion-skip
guarantee, export schema-def inclusion, and the designer UI field to
add/remove bindings — lands in the follower spec `data-registers-runtime`
(`kind: code`, `depends_on: [data-registers-schema-declaration]`). Declaring
the schema first means `spectr` (and any other consumer) can start
referencing `dataRegisters` in seed data and manual testing the moment this
spec merges, while the picker/export/promotion-guard code follows in its own
right-sized, single-surface review cycle.

## What Changes

- **NEW** optional `dataRegisters` array property on the `Application` schema
  (`lib/Settings/openbuild_register.json`, added via a `register.d/` fragment
  per ADR-037 — no edit to the monolith). Each entry is an object binding the
  app to one existing OpenRegister register by slug, with an optional
  human-readable label for picker UX. Absent on every existing Application
  (backward compatible; no migration needed — an app with no declared data
  registers behaves exactly as it does today).
- **NEW** seed example on the `hello-world` Application record's `spectr`
  sibling scenario is documented in `design.md` (Seed Data section) as
  realistic reference data — not created as a live object in this change (no
  running instance is touched by a `kind: config` schema-only spec).
- **NO code changes.** No PHP service, no Vue component, no route is added or
  modified. RBAC is unchanged — schema-level RBAC on the *referenced*
  register continues to be the sole authorization surface; declaring
  `dataRegisters` on an `Application` does not itself grant or widen any
  access (see design.md's Declarative-vs-imperative + RBAC sections).

### Capabilities

#### New Capabilities

_(none — this spec extends an existing schema; it introduces no new
capability domain)_

#### Modified Capabilities

- `buildiq-application-register`: ADDED Requirement — the `Application`
  schema gains an optional `dataRegisters` array property (shared data
  register bindings), sibling to `baseRef`. No existing requirement's
  behavior changes; this is purely additive.

## Impact

- **Changed files**: `lib/Settings/register.d/20-data-registers.json` (new
  fragment, per ADR-037 — the shared `buildiq` register's `Application`
  schema gains the `dataRegisters` property via deep-merge; no edit to
  `lib/Settings/openbuild_register.json` itself).
- **No PHP/Vue/route changes** — see "What Changes" above.
- **No breaking changes** — purely additive optional property; every
  existing `Application` object remains schema-valid with `dataRegisters`
  absent.
- **OpenRegister** — no new register, no new OR-side schema; this only adds
  one property to the existing `Application` schema already imported into
  the shared `buildiq` register.
- **Downstream (out of scope here, tracked by the follower spec
  `data-registers-runtime`)**:
  - `src/composables/useRegisterPicker.js` (consumed by
    `IndexPageEditor.vue`, `DetailPageEditor.vue`, `LogsPageEditor.vue`,
    `ApplicationDetailActions.vue`) — today `fetchRegisters()` lists every
    OR register instance-wide and hoists the app's own per-version register
    to the top; the follower teaches it to also surface/label the
    Application's declared `dataRegisters`.
  - `lib/Service/VersionPromotionService.php` — today `forwardSchemaSetToOR()`
    / `wipeTargetRegister()` / `copyRowsFromSource()` operate exclusively on
    `ApplicationVersion.register` (the per-version register) and never touch
    `Application`-level fields, so "promotion skips data registers" already
    holds true by construction; the follower adds the explicit regression
    test (and, if needed, a defensive guard) that locks this invariant in.
  - `lib/Service/ExportService.php` — `generateAppZip()` bundles the
    per-version register/manifest into the export ZIP today; the follower
    adds shared data-register **schema defs** (never data) to that bundle.
  - The version-promotion, page-designer-ui / schema-designer-ui, and
    buildiq-exporter capabilities are the follower's spec-delta targets —
    none of them change here.
- **Foundational ADRs honoured** — ADR-002 (extends the versioned model
  without altering it: `dataRegisters` is explicitly NOT the per-version
  `register` field), ADR-022 (consume OR abstractions — a data register is
  itself just another OR register; no new abstraction invented), ADR-031
  (schema-declarative — this is a pure schema addition, no service class),
  ADR-032 (kind: config head of a 2-spec chain — see frontmatter), ADR-037
  (modular register fragments — ships as `register.d/20-data-registers.json`,
  not a monolith edit), ADR-050 / SPECTR-NEXTCLOUD-PLAN.md §4.2 (the source
  decision this spec implements).
