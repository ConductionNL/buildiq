# Integrator guide — authoring a virtual app by hand

This guide walks you through creating a new virtual app in OpenBuilt without using the visual editor (which lives in chain spec [`openbuilt-page-editor`](../openspec/changes/) — not yet shipped). At this stage, OpenBuilt is integrator-only: you write JSON and the runtime renders it.

## What you author

A virtual app is one record in OpenBuilt's `Application` OR schema. The shape is:

```jsonc
{
  "slug": "permit-tracker",                // kebab-case, 2–48 chars
  "name": "Permit Tracker",
  "description": "Track building permits through review stages.",
  "version": "0.1.0",
  "status": "draft",                       // draft → published → archived
  "manifest": {
    "version": "1.0.0",
    "dependencies": ["openregister"],
    "menu": [ ... ],
    "pages": [ ... ]
  }
}
```

The `manifest` object validates against [`@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`](https://github.com/ConductionNL/nextcloud-vue/blob/main/src/schemas/app-manifest.schema.json). The closed `type` enum for pages is `index | detail | dashboard | logs | settings | chat | files | form | custom`.

## Creating a virtual app with the wizard

For most operators the visual wizard at **Virtual apps → New application** is the
quicker path. It walks you through three steps in one round-trip:

1. **Identity** — pick a slug + human-readable name + description.
2. **Versions** — accept the default chain (`development → staging → production`)
   or adjust it. Each version maps to its own per-version register
   (`openbuilt-{slug}-{versionSlug}`) so production data is physically isolated
   from staging and development. The wizard's chain editor enforces ADR-002's
   linear-chain rule (no fan-out, no cycles, exactly one terminal `production`
   tier).
3. **Permissions** — pick the owners / editors / viewers. The caller is
   pre-filled into `owners`. Group `group:*` becomes the "all signed-in users"
   wildcard per REQ-OBRBAC-004.

On submit the wizard atomically creates the `Application` record, the N
`ApplicationVersion` rows, and N per-version registers — and seeds each
register with the default schema set (`hello-message` by default) under the
namespaced slug `{appSlug}-{versionSlug}-{originalSchemaSlug}`. The manifest's
`config.register` and `config.schema` pointers are rewritten to match
(`openbuilt-{slug}-{tier}` and `{appSlug}-{tier}-hello-message` respectively)
so the insights service and the runtime each address the right per-tier slice.

**Empty-state landing** — fresh installs no longer auto-seed a `hello-world`
Application (the legacy `SeedHelloWorld` repair step was retired by
`openbuilt-versioning-model`). New deploys land the admin on an empty Virtual
apps index with a CTA pointing at the wizard. Pre-existing installs are not
affected; the migration step `MigrateToVersionedModel` only fires when
pre-spec-C Application rows are present and is idempotent on re-runs.

For further reading on what each step writes through to OR, see
[`openbuilt-runtime.md`](./openbuilt-runtime.md) and the wizard chain spec
[`openspec/changes/openbuilt-app-creation-wizard/`](../openspec/changes/openbuilt-app-creation-wizard/).

## Step-by-step (manual / integrator path)

1. **Pick a slug.** Must be kebab-case, 2–48 chars, unique within your organisation. The synthetic appId in CnAppRoot becomes `openbuilt-${slug}`.
2. **Design your schemas** in OpenRegister directly (the OpenBuilt schema editor lands in chain spec `openbuilt-schema-editor`). At minimum: one schema per primary entity your app shows.
3. **Author the manifest** as JSON. The canonical example is the seeded `hello-world` Application — open it in OpenBuilt's manifest editor (top-bar OpenBuilt entry → Virtual apps → hello-world) and read its manifest.
4. **Save as `draft`** while iterating. The textarea editor validates each save against the canonical schema; you see the failing JSON path on save error.
5. **Transition to `published`** when ready (via OR's lifecycle endpoint or the editor's Publish action — landing in chain spec `openbuilt-versioning`). On publish, OpenBuilt's lifecycle creates the corresponding `BuiltAppRoute` so `/builder/{slug}` becomes reachable.

## Manifest checklist

Per [ADR-024](https://github.com/ConductionNL/hydra/blob/main/openspec/architecture/adr-024-app-manifest.md):

- `version` (semver) — your app's content version
- `dependencies` — list of NC app IDs that must be installed (almost always `["openregister"]`)
- `menu[]` — at least one entry; supports one level of `children[]`
- `pages[]` — at least one entry; every page's `id` MUST be unique and match a vue-router route name
- `label` / `title` strings are i18n KEYS, not literals. The consuming app's `t()` resolves them. Use kebab.dot.notation: `myapp.permits.title.list`.

Per [ADR-007](https://github.com/ConductionNL/hydra/blob/main/openspec/architecture/adr-007-i18n.md):

- Every translation key MUST exist in `l10n/en.json` AND `l10n/nl.json` of the **OpenBuilt** repo (until per-virtual-app translations land in chain spec `openbuilt-page-editor`).

## Reading the seed manifest

The seeded `hello-world` Application is the canonical reference. Its manifest exercises:

- **index** page → drives `CnIndexPage` with `register: openbuilt`, `schema: hello-message`, three columns
- **detail** page → drives `CnDetailPage` keyed on `:id`
- **form** page → drives `CnFormPage` with `mode: create` and `submitEndpoint` going to OR's REST

See [`lib/Repair/SeedHelloWorld.php`](../lib/Repair/SeedHelloWorld.php) `buildHelloWorldManifest()` for the full JSON.

## When you hit a limit

The closed `type` enum can't be extended from a manifest — adding a new page type requires a library-level openspec change in `@conduction/nextcloud-vue`. If you need something the four built-in types can't express:

1. Confirm the requirement isn't satisfied by `form` (the most flexible built-in).
2. Open an issue on `ConductionNL/nextcloud-vue` describing the new page type's shape.
3. As an interim, mount a custom Vue component via `type: "custom"` + `component: "MyCustomPage"` and register the component in OpenBuilt's `customComponents` map. (Note: spec #1 only ships the built-in types — the `customComponents` registry surface lands when a real consumer needs it.)

## What does NOT work yet (spec #1 limitations)

- **No visual editor** — JSON textarea only. Visual editor: chain spec `openbuilt-page-editor`.
- **No schema designer** — schemas must be authored in `lib/Settings/openbuilt_register.json` and imported via the repair step. Runtime schema authoring: chain spec `openregister-runtime-schema-api`.
- **No draft preview** — only `published` apps appear at `/builder/{slug}`. Draft preview: chain spec `openbuilt-versioning`.
- **No per-app permissions** — auth-only visibility for v1; everyone in your organisation sees every virtual app. Per-app RBAC: chain spec `openbuilt-rbac`.
- **No export to a real Nextcloud app** — virtual-only. Export pipeline: chain spec `openbuilt-export-to-real-app`.

If any of these limitations block your project, talk to Conduction — chain spec prioritisation can shift.
